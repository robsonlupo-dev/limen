<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\WaitlistWebRequest;
use App\Mail\WaitlistConfirmationMail;
use App\Models\WaitlistEntry;
use App\Services\Waitlist\WaitlistService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class WaitlistController extends Controller
{
    public function __construct(
        private readonly WaitlistService $service,
    ) {}

    /**
     * Pre-launch interest capture from the public landing page. Idempotent per
     * (email, role). When the visitor arrived through an invite link, the
     * referrer id was stashed in the session by ConviteController; we attribute
     * the referral here (the service enforces the anti-fraud cap).
     */
    public function store(WaitlistWebRequest $request): RedirectResponse
    {
        $success = 'Tudo certo! Confirme seu e-mail para garantir seu lugar na lista.';

        // Honeypot: a hidden field no human should ever fill.
        if (filled($request->input('website'))) {
            return back()->with('success', $success);
        }

        $referrer = WaitlistEntry::find($request->session()->get('waitlist_referrer_id'));

        ['entry' => $entry, 'created' => $created] = $this->service->join(
            $request->validated(),
            $referrer,
            $request->ip(),
        );

        if ($created) {
            Mail::to($entry->email)->send(new WaitlistConfirmationMail($entry));
        }

        // The invite has been consumed; don't attribute future signups to it.
        $request->session()->forget('waitlist_referrer_id');

        return back()->with('success', $success);
    }

    /**
     * Double opt-in email confirmation. Reached from the link in the
     * confirmation email; idempotent, so a link pre-fetch confirms at most once.
     * On success we land the person on their own founder panel.
     */
    public function confirm(Request $request): RedirectResponse
    {
        $entry = WaitlistEntry::findByInviteToken((string) $request->query('t', ''));

        if ($entry === null) {
            return redirect()->route('landing');
        }

        $this->service->confirm($entry);

        return redirect()
            ->route('waitlist.founder', ['invite_code' => $entry->invite_code])
            ->with('success', 'E-mail confirmado! Seu lugar está garantido.');
    }

    /**
     * Landing of the unsubscribe flow. A GET must be side-effect-free (email
     * clients/scanners pre-fetch links), so it only renders a confirmation page;
     * the removal happens on the CSRF-protected POST below. The token is the
     * per-row invite_token — opaque and unguessable, no PII in the URL.
     */
    public function confirmUnsubscribe(Request $request): View|RedirectResponse
    {
        $entry = WaitlistEntry::findByInviteToken((string) $request->query('t', ''));

        if ($entry === null) {
            return redirect()->route('landing');
        }

        return view('waitlist.unsubscribe', ['email' => $entry->email, 'token' => $entry->invite_token]);
    }

    /**
     * Perform the unsubscribe. Reached only via the confirmation form's POST, so
     * it is CSRF-protected. Neutral response — never reveals membership.
     */
    public function unsubscribe(Request $request): RedirectResponse
    {
        $entry = WaitlistEntry::findByInviteToken((string) $request->input('token', ''));

        $entry?->delete();

        return redirect()
            ->route('landing')
            ->with('success', 'Pronto. Se você estava na lista, seu e-mail foi removido.');
    }
}
