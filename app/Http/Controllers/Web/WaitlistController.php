<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\WaitlistWebRequest;
use App\Mail\WaitlistConfirmationMail;
use App\Models\WaitlistEntry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Mail;

class WaitlistController extends Controller
{
    /**
     * Pre-launch interest capture from the public landing page. Idempotent per
     * (email, role): re-submitting the same interest updates the name instead of
     * creating a duplicate, so a curious visitor never sees an error.
     */
    public function store(WaitlistWebRequest $request): RedirectResponse
    {
        $success = 'Tudo certo! Você está na lista. Avisaremos assim que o Limen abrir.';

        // Honeypot: a hidden field no human should ever fill. When a bot fills it,
        // we swallow the submission (return the same success) without persisting.
        if (filled($request->input('website'))) {
            return back()->with('success', $success);
        }

        $data = $request->validated();

        $entry = WaitlistEntry::updateOrCreate(
            ['email' => $data['email'], 'role' => $data['role']],
            [
                'name' => $data['name'],
                'world' => $data['world'] ?? null,
                'age_confirmed' => true,
                'source' => 'landing',
            ],
        );

        // Confirm by email only for a genuinely new signup, so a curious visitor
        // re-submitting the form is not mailed again on every save.
        if ($entry->wasRecentlyCreated) {
            // 1-based place in line, frozen at signup time so the number in the
            // email never drifts. Ties on the same timestamp break by id.
            $position = WaitlistEntry::where('created_at', '<', $entry->created_at)
                ->orWhere(fn ($q) => $q->where('created_at', $entry->created_at)->where('id', '<=', $entry->id))
                ->count();

            Mail::to($entry->email)->send(new WaitlistConfirmationMail($entry, $position));
        }

        return back()->with('success', $success);
    }

    /**
     * Landing page of the unsubscribe flow (from the email link). A GET must be
     * side-effect-free: email clients and security scanners pre-fetch every link
     * on delivery, so deleting here would silently unsubscribe legitimate users.
     * Instead we only render a confirmation page; the actual removal happens on
     * the POST below (CSRF-protected, never pre-fetched). The token is opaque and
     * carries the email, so nothing sensitive appears in the query string/log.
     */
    public function confirmUnsubscribe(Request $request): View|RedirectResponse
    {
        $token = (string) $request->query('t', '');
        $email = WaitlistEntry::emailFromUnsubscribeToken($token);

        // Invalid/tampered/missing token → neutral bounce, no oracle.
        if ($email === null) {
            return redirect()->route('landing');
        }

        return view('waitlist.unsubscribe', ['email' => $email, 'token' => $token]);
    }

    /**
     * Perform the unsubscribe. Reached only via the confirmation form's POST, so
     * it is CSRF-protected and cannot be triggered by a link pre-fetch. The
     * response is intentionally neutral — it never reveals whether the email was
     * on the list (no enumeration oracle). Removes every role for the email.
     */
    public function unsubscribe(Request $request): RedirectResponse
    {
        $email = WaitlistEntry::emailFromUnsubscribeToken((string) $request->input('token', ''));

        if ($email !== null) {
            WaitlistEntry::where('email', $email)->delete();
        }

        return redirect()
            ->route('landing')
            ->with('success', 'Pronto. Se você estava na lista, seu email foi removido.');
    }
}
