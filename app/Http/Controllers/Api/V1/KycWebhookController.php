<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\IdentityVerification;
use App\Services\KycService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Receives Didit v3 verification webhooks (webhook_type=status.updated).
 *
 * Authentication is HMAC-SHA256 with the shared webhook secret, in two flavors:
 *  - X-Signature-V2 (preferred): HMAC over the *canonical* JSON of the payload
 *    (keys sorted recursively, integer-valued floats collapsed to int, unescaped
 *    unicode/slashes). Robust to key-ordering differences between Didit and us.
 *  - X-Signature-Simple (fallback): HMAC over "{timestamp}:{session_id}:{status}:
 *    {webhook_type}".
 * Both require a fresh X-Timestamp (±300s) to blunt replay. The endpoint is
 * closed when no secret is configured.
 */
class KycWebhookController extends Controller
{
    /** Max clock drift, in seconds, tolerated on X-Timestamp (replay guard). */
    private const TIMESTAMP_TOLERANCE = 300;

    public function __construct(private KycService $kycService) {}

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->json()->all();

        if (! $this->isAuthorized($request, $payload)) {
            Log::warning('KYC webhook auth failed', ['ip' => $request->ip()]);
            Audit::log('kyc.webhook_auth_failed', metadata: ['ip' => $request->ip()]);

            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        // Only decision transitions matter; acknowledge (2xx) and ignore the rest
        // so Didit does not retry session.created/session.expired/etc.
        if (($payload['webhook_type'] ?? null) !== 'status.updated') {
            return response()->json(['message' => 'Ignored.']);
        }

        // Idempotency: a replayed event_id is acknowledged without reprocessing.
        // Cache::add is atomic, so concurrent duplicates collapse to one winner.
        $eventId = $payload['event_id'] ?? null;
        if ($eventId !== null && ! Cache::add($this->eventKey($eventId), true, now()->addDays(7))) {
            return response()->json(['message' => 'OK.']);
        }

        $reference = $payload['session_id'] ?? null;
        $status = $this->normalizeStatus($payload['status'] ?? null);

        if (! $reference) {
            return response()->json(['message' => 'OK.']);
        }

        $verification = IdentityVerification::where('provider_reference', $reference)->first();

        if (! $verification) {
            return response()->json(['message' => 'OK.']);
        }

        // Terminal states never transition again (second guard beyond event_id).
        if (in_array($verification->status, ['approved', 'rejected'], true)) {
            return response()->json(['message' => 'OK.']);
        }

        // The transition itself is a short DB transaction and the notification
        // emails are already dispatched to the queue, so we answer inline and
        // still return quickly — no separate webhook job needed.
        if ($status === 'approved') {
            $this->kycService->approve($verification);
        } elseif ($status === 'rejected') {
            $this->kycService->reject($verification, data_get($payload, 'decision.reason'));
        }

        return response()->json(['message' => 'OK.']);
    }

    private function eventKey(string $eventId): string
    {
        return 'kyc_webhook_event:' . $eventId;
    }

    /**
     * Verifies the webhook is authentic and fresh. Returns true when the secret
     * is set, X-Timestamp is within tolerance, and either signature matches.
     */
    private function isAuthorized(Request $request, array $payload): bool
    {
        $secret = (string) config('kyc.webhook_secret');

        if ($secret === '') {
            return false;
        }

        $timestamp = $request->header('X-Timestamp');

        if ($timestamp === null || ! is_numeric($timestamp)) {
            return false;
        }

        if (abs(now()->getTimestamp() - (int) $timestamp) > self::TIMESTAMP_TOLERANCE) {
            return false;
        }

        // Preferred: HMAC over the canonical JSON of the payload.
        $v2 = $request->header('X-Signature-V2');

        if ($v2 !== null) {
            $expected = hash_hmac('sha256', $this->canonicalJson($payload), $secret);

            if (hash_equals($expected, (string) $v2)) {
                return true;
            }
        }

        // Fallback: HMAC over a compact field string.
        $simple = $request->header('X-Signature-Simple');

        if ($simple !== null) {
            $base = implode(':', [
                $timestamp,
                $payload['session_id'] ?? '',
                $payload['status'] ?? '',
                $payload['webhook_type'] ?? '',
            ]);
            $expected = hash_hmac('sha256', $base, $secret);

            if (hash_equals($expected, (string) $simple)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Serializes the payload deterministically so our HMAC matches Didit's:
     * keys sorted recursively, integer-valued floats collapsed to int, unicode
     * and slashes left unescaped.
     */
    private function canonicalJson(mixed $value): string
    {
        return json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map(fn ($item) => $this->canonicalize($item), $value);
        }

        if (is_float($value) && is_finite($value) && floor($value) === $value) {
            return (int) $value;
        }

        return $value;
    }

    /**
     * Maps Didit's decision status to our vocabulary. Only the terminal
     * Approved/Declined act; anything else (In Review, …) returns null → ignored.
     */
    private function normalizeStatus(?string $status): ?string
    {
        return match ($status) {
            'Approved' => 'approved',
            'Declined' => 'rejected',
            default => null,
        };
    }
}
