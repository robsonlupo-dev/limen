<?php

use App\Models\ChatAccess;
use App\Models\Conversation;
use App\Models\Follow;
use App\Models\IdentityVerification;
use App\Models\PerformerProfile;
use App\Models\User;
use App\Services\ChatAccessService;
use App\Services\InterestService;
use App\Services\TokenService;
use App\Support\FanAlias;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Corpo do POST de Interesse Controlado.
 *
 * O endpoint recebe o handle opaco (App\Support\FanAlias), não o id do membro —
 * é o mesmo valor que a tela de Seguidores entrega. Aceita um id cru para os
 * testes que provam que um alvo inválido é indistinguível de um inexistente:
 * ali o ponto é justamente montar um handle que não resolve.
 */
function interestPayload(PerformerProfile $profile, User|int $member): array
{
    return [
        'member_handle' => FanAlias::handle(
            $profile->id,
            $member instanceof User ? $member->id : $member
        ),
    ];
}

/**
 * Persists a pending performer + KYC verification keyed on a Didit session ref,
 * so webhook tests have a row to transition. Shared across the KYC test files.
 */
function makePendingVerification(string $reference): IdentityVerification
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'pending']);
    $user->performerProfile()->create([
        'stage_name' => 'Didit Performer',
        'slug' => 'didit-'.strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => false,
    ]);

    return $user->identityVerifications()->create([
        'document_type' => 'rg',
        'document_number' => '52998224725',
        'full_legal_name' => 'Maria Teste Silva',
        'date_of_birth' => '1998-01-01',
        'provider' => 'didit',
        'provider_reference' => $reference,
        'provider_status' => 'pending',
        'status' => 'pending',
    ]);
}

/**
 * Canonicalizes a payload exactly like KycWebhookController: recursively sorted
 * keys, integer-valued floats collapsed to int, unescaped unicode/slashes.
 */
function kycCanonicalize(mixed $value): mixed
{
    if (is_array($value)) {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map('kycCanonicalize', $value);
    }

    if (is_float($value) && is_finite($value) && floor($value) === $value) {
        return (int) $value;
    }

    return $value;
}

/** Headers for a Didit v3 webhook signed with X-Signature-V2 (canonical HMAC). */
function kycV3Headers(array $payload, ?int $timestamp = null): array
{
    $timestamp ??= now()->getTimestamp();
    $canonical = json_encode(kycCanonicalize($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return [
        'X-Timestamp' => (string) $timestamp,
        'X-Signature-V2' => hash_hmac('sha256', $canonical, (string) config('kyc.webhook_secret')),
    ];
}

/** Headers for the X-Signature-Simple fallback: HMAC over a compact field string. */
function kycV3SimpleHeaders(array $payload, ?int $timestamp = null): array
{
    $timestamp ??= now()->getTimestamp();
    $base = implode(':', [
        $timestamp,
        $payload['session_id'] ?? '',
        $payload['status'] ?? '',
        $payload['webhook_type'] ?? '',
    ]);

    return [
        'X-Timestamp' => (string) $timestamp,
        'X-Signature-Simple' => hash_hmac('sha256', $base, (string) config('kyc.webhook_secret')),
    ];
}

// ─── Chat (par com Interesse desbloqueado) ───────────────────────────────────

/**
 * Par membro+performer com o canal de chat REALMENTE aberto (follow →
 * interesse → unlock), como no fluxo de produção. Compartilhado entre
 * ChatPhase1Test e ChatContentFilterTest.
 */
function chatPerformer(int $splitPct = 65): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);

    return $user->performerProfile()->create([
        'stage_name' => 'Perf '.Str::random(4),
        'slug' => 'perf-'.strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => true,
        'level' => 'iniciante',
        'split_pct' => $splitPct,
    ]);
}

function chatMember(int $balance = 0): User
{
    $member = User::factory()->create(['role' => 'consumer', 'status' => 'active']);

    if ($balance > 0) {
        app(TokenService::class)->credit($member, $balance, 'purchase');
    }

    return $member;
}

/** Membro que seguiu, recebeu interesse e desbloqueou — canal aberto de verdade. */
function chatUnlockedPair(PerformerProfile $performer, int $balance = 0): array
{
    $member = chatMember($balance + 15);
    Follow::create(['user_id' => $member->id, 'performer_profile_id' => $performer->id]);

    $interest = app(InterestService::class)->send($performer, $member);
    app(InterestService::class)->unlock($member, $interest);

    $conversation = Conversation::where('member_id', $member->id)
        ->where('performer_profile_id', $performer->id)
        ->sole();

    return [$member, $conversation, $interest->fresh()];
}

function grantChatAccess(User $member, Conversation $conversation): ChatAccess
{
    return app(ChatAccessService::class)->openOrRenew($conversation, $member, (string) Str::uuid());
}
