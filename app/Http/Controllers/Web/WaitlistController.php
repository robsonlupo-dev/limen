<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\WaitlistWebRequest;
use App\Mail\WaitlistConfirmationMail;
use App\Models\WaitlistEntry;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

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
     * One-click unsubscribe from the pre-launch waitlist. The token is an
     * HMAC of the email (see WaitlistEntry) so a link cannot be forged to remove
     * someone else's address. The response is intentionally neutral — it never
     * reveals whether the email was on the list (no enumeration oracle).
     */
    public function unsubscribe(Request $request): RedirectResponse
    {
        $email = Str::lower(trim((string) $request->query('email', '')));
        $token = (string) $request->query('token', '');

        if ($email !== '' && WaitlistEntry::isValidUnsubscribeToken($email, $token)) {
            WaitlistEntry::where('email', $email)->delete();
        }

        return redirect()
            ->route('landing')
            ->with('success', 'Pronto. Seu email foi removido da lista de espera.');
    }
}
