<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\WaitlistWebRequest;
use App\Http\Requests\Web\WaitlistWhatsappRequest;
use App\Mail\WaitlistConfirmationMail;
use App\Models\WaitlistEntry;
use App\Services\Waitlist\WaitlistService;
use App\Support\BrazilPhone;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

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

        // Direciona para a tela de CONTINUAÇÃO (confirme o e-mail + WhatsApp +
        // convide), em vez do aviso inline. A identificação é por SESSÃO — nenhum
        // token/PII vai na URL. (feat/waitlist-continue-screen)
        $request->session()->put('waitlist_entry_id', $entry->id);

        return redirect()->route('waitlist.continue')->with('success', $success);
    }

    /**
     * Tela de continuação do pré-cadastro (feat/waitlist-continue-screen): logo
     * depois de entrar na lista, reforça a confirmação do e-mail (anti-spam / não
     * perder o go-live), oferece o opt-in de WhatsApp e o convite de amigos. Sem
     * login — a pessoa é identificada pelo `waitlist_entry_id` da sessão. Sem
     * sessão (acesso direto), volta para a home.
     */
    public function continueScreen(Request $request): Response|RedirectResponse
    {
        $entry = $this->sessionEntry($request);

        if ($entry === null) {
            return redirect()->route('landing');
        }

        return Inertia::render('Waitlist/Continue', [
            'waitlist' => $this->entryProps($entry),
        ]);
    }

    /** Opt-in de WhatsApp da tela de continuação (JSON; identificado por sessão). */
    public function storeWhatsapp(WaitlistWhatsappRequest $request): JsonResponse
    {
        $entry = $this->sessionEntry($request);

        if ($entry === null) {
            return response()->json(['message' => 'Sessão expirada. Volte à página inicial.'], 419);
        }

        $e164 = $this->service->optInWhatsapp($entry, (string) $request->input('whatsapp'));

        if ($e164 === null) {
            return response()->json([
                'message' => 'Informe um número de celular válido com DDD.',
                'errors' => ['whatsapp' => ['Informe um número de celular válido com DDD.']],
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'whatsapp_masked' => BrazilPhone::mask($e164),
            'message' => 'Pronto! Vamos te avisar por WhatsApp quando as portas abrirem.',
        ]);
    }

    /** Revoga o opt-in de WhatsApp (JSON). */
    public function destroyWhatsapp(Request $request): JsonResponse
    {
        $entry = $this->sessionEntry($request);

        $entry && $this->service->optOutWhatsapp($entry);

        return response()->json(['ok' => true]);
    }

    /** Reenvia o e-mail de confirmação para o registro da sessão (JSON). */
    public function resendForEntry(Request $request): JsonResponse
    {
        $entry = $this->sessionEntry($request);

        if ($entry === null) {
            return response()->json(['message' => 'Sessão expirada. Volte à página inicial.'], 419);
        }

        if (! $entry->isConfirmed()) {
            Mail::to($entry->email)->send(new WaitlistConfirmationMail($entry));
        }

        return response()->json(['ok' => true, 'message' => 'Reenviamos o e-mail de confirmação.']);
    }

    /** Registro da lista de espera da sessão atual, ou null. */
    private function sessionEntry(Request $request): ?WaitlistEntry
    {
        $id = $request->session()->get('waitlist_entry_id');

        return $id ? WaitlistEntry::find($id) : null;
    }

    /**
     * Props da tela de continuação. Nada sensível: papel, primeiro nome, e-mail
     * mascarado, código/link de convite (o invite_code já é público) e o estado do
     * opt-in. NUNCA valores de token (pré-lançamento não tem conta nem token).
     *
     * @return array<string, mixed>
     */
    private function entryProps(WaitlistEntry $entry): array
    {
        $inviteCode = $entry->invite_code;
        $base = rtrim((string) config('app.url'), '/');

        return [
            'role' => $entry->isPerformer() ? 'performer' : 'member',
            'name' => (string) \Illuminate\Support\Str::of($entry->name)->trim()->explode(' ')->first(),
            'email_masked' => $this->maskEmail($entry->email),
            'confirmed' => $entry->isConfirmed(),
            'founder_title' => $entry->founderTitle(),
            'invite_code' => $inviteCode,
            'invite_link' => $inviteCode ? $base.'/convite/'.$inviteCode : null,
            'referral_count' => (int) $entry->referral_count,
            'whatsapp_opted_in' => filled($entry->whatsapp_consent_at),
            'whatsapp_masked' => BrazilPhone::mask($entry->whatsapp),
        ];
    }

    /** ma•••@gmail.com — nunca o e-mail inteiro na tela. */
    private function maskEmail(string $email): string
    {
        $parts = explode('@', $email, 2);

        return mb_substr($parts[0] ?? '', 0, 2).'•••@'.($parts[1] ?? '');
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
