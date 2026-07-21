<?php

namespace App\Http\Controllers\Web\Performer;

use App\Http\Controllers\Controller;
use App\Http\Requests\TwoFactorCodeRequest;
use App\Models\User;
use App\Services\TwoFactorService;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Porta web (sessão + CSRF) do 2FA da performer: cadastro, confirmação,
 * desativação e o desafio de sessão.
 *
 * O material sensível do cadastro (segredo, QR, recovery codes) vai para a tela
 * por FLASH de sessão, uma renderização só — nunca como prop persistente nem em
 * querystring. Prop persistente reapareceria em toda visita à tela de
 * configurações (e no histórico do Inertia); querystring entraria no log do
 * nginx e no Referer. Ver CLAUDE.md: segredo não vai em URL nem em log.
 */
class TwoFactorController extends Controller
{
    private const SETUP_FLASH = '2fa_setup';

    public function __construct(private TwoFactorService $twoFactor) {}

    /** Tela de configurações do 2FA. */
    public function show(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Performer/TwoFactor/Settings', [
            'enabled' => $this->twoFactor->isEnabled($user),
            'pending' => $this->twoFactor->isPending($user),
            'remainingRecoveryCodes' => $this->twoFactor->remainingRecoveryCodes($user),
            // Presente só no redirect que vem logo depois de enable()/reemissão.
            'setup' => $request->session()->get(self::SETUP_FLASH),
        ]);
    }

    /** Gera segredo + recovery codes e devolve o QR para escanear. */
    public function enable(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        // 409 e não flash: a UI não oferece "ativar" para quem já tem 2FA ativo,
        // então quem chega aqui nesse estado é requisição forjada. O service
        // também recusa (LogicException) — isto é a resposta HTTP decente, não
        // a regra.
        abort_if($this->twoFactor->isEnabled($user), 409, 'A verificação em duas etapas já está ativa.');

        $setup = $this->twoFactor->enable($user);

        return redirect()
            ->route('performer.2fa.show')
            ->with(self::SETUP_FLASH, [
                'secret' => $setup['secret'],
                'qr_svg' => $setup['qr_svg'],
                'recovery_codes' => $setup['recovery_codes'],
            ]);
    }

    /** Fecha o cadastro provando que o autenticador gera código válido. */
    public function confirm(TwoFactorCodeRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $this->twoFactor->confirm($user, $request->code())) {
            throw ValidationException::withMessages([
                'code' => ['Código inválido. Confira o app autenticador e tente de novo.'],
            ]);
        }

        // Quem acabou de confirmar já apresentou o fator — mandar essa pessoa
        // direto para o desafio seria pedir o mesmo código duas vezes seguidas
        // (e o TOTP da janela atual já foi gasto).
        $this->markSessionVerified($request);

        return redirect()
            ->route('performer.2fa.show')
            ->with('success', 'Verificação em duas etapas ativada.');
    }

    /** Desliga o 2FA — exige um fator válido antes. */
    public function disable(TwoFactorCodeRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $this->twoFactor->disable($user, $request->code())) {
            throw ValidationException::withMessages([
                'code' => ['Código inválido. A verificação em duas etapas continua ativa.'],
            ]);
        }

        return redirect()
            ->route('performer.2fa.show')
            ->with('success', 'Verificação em duas etapas desativada.');
    }

    /** Emite um lote novo de recovery codes (invalida o anterior). */
    public function regenerateRecoveryCodes(TwoFactorCodeRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $codes = $this->twoFactor->regenerateRecoveryCodes($user, $request->code());

        if ($codes === null) {
            throw ValidationException::withMessages([
                'code' => ['Código inválido. Os códigos de recuperação não foram alterados.'],
            ]);
        }

        return redirect()
            ->route('performer.2fa.show')
            ->with(self::SETUP_FLASH, ['recovery_codes' => $codes])
            ->with('success', 'Novos códigos de recuperação gerados. Os antigos não valem mais.');
    }

    /** Tela do desafio, mostrada pelo middleware TwoFactorChallenge. */
    public function challenge(Request $request): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Sem 2FA ativo não há desafio a apresentar; e quem já verificou não
        // deve ficar preso numa tela que não tem o que fazer.
        if (! $this->twoFactor->isEnabled($user)
            || $request->session()->get(TwoFactorService::SESSION_KEY) === true) {
            return redirect()->route('performer.dashboard');
        }

        return Inertia::render('Performer/TwoFactor/Challenge');
    }

    /** Resolve o desafio e libera a sessão. */
    public function verify(TwoFactorCodeRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($this->twoFactor->isEnabled($user), 404);

        if (! $this->twoFactor->verify($user, $request->code())) {
            Audit::log('performer.2fa_challenge_failed', $user);

            throw ValidationException::withMessages([
                'code' => ['Código inválido.'],
            ]);
        }

        $this->markSessionVerified($request);

        Audit::log('performer.2fa_challenge_passed', $user);

        return redirect()->intended(route('performer.dashboard'));
    }

    /**
     * Marca a sessão como verificada, trocando o id antes.
     *
     * O regenerate é o que impede fixação em cima do segundo fator: sem ele, um
     * id de sessão que o atacante tivesse plantado ANTES do desafio (ele sabe a
     * senha; o que falta é o fator) sairia daqui já carimbado como verificado.
     * Trocar o id na hora da elevação de privilégio invalida o que ele tem.
     */
    private function markSessionVerified(Request $request): void
    {
        $request->session()->regenerate();
        $request->session()->put(TwoFactorService::SESSION_KEY, true);
    }
}
