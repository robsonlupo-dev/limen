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
     * Formulário público "reenviar e-mail de confirmação", para quem se
     * inscreveu e não recebeu (caiu no spam, apagou sem querer). Página standalone
     * em blade (sem Inertia), no mesmo padrão do unsubscribe. GET é inócuo.
     */
    public function resendForm(): View
    {
        return view('waitlist.resend');
    }

    /**
     * Reenvia o e-mail de confirmação para uma inscrição NÃO confirmada. Resposta
     * SEMPRE neutra — nunca revela se o e-mail está ou não na lista (anti-oráculo
     * de enumeração), igual ao unsubscribe. Honeypot + throttle na rota cobrem o
     * abuso; só reenvia para entradas ainda não confirmadas (confirmada não
     * precisa). Pode haver duas entradas (membro e performer) no mesmo e-mail:
     * reenvia para cada uma não confirmada.
     */
    public function resend(Request $request): RedirectResponse
    {
        $neutral = 'Se houver uma inscrição não confirmada com esse e-mail, reenviamos o link de confirmação.';

        // Honeypot: campo oculto que humano nenhum preenche.
        if (filled($request->input('website'))) {
            return redirect()->route('landing')->with('success', $neutral);
        }

        $email = mb_strtolower(trim((string) $request->input('email')));

        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            WaitlistEntry::where('email', $email)
                ->whereNull('confirmed_at')
                ->get()
                ->each(fn (WaitlistEntry $entry) => Mail::to($entry->email)->send(new WaitlistConfirmationMail($entry)));
        }

        return redirect()->route('landing')->with('success', $neutral);
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
