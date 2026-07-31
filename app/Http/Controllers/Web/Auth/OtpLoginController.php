<?php

namespace App\Http\Controllers\Web\Auth;

use App\Exceptions\OtpThrottleException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Auth\Concerns\RedirectsToHome;
use App\Http\Requests\Auth\RequestOtpRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Models\OtpCode;
use App\Services\OtpService;
use App\Services\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Login passwordless por código OTP — porta WEB (sessão). Alternativa ao login
 * por senha, não substituta (o link "Entrar com código" mora na tela de login).
 *
 * Duas telas: pede o e-mail → recebe o código → cola e entra. O e-mail viaja na
 * SESSÃO entre as duas telas (nunca na URL), para não deixar o endereço num link
 * compartilhável nem no histórico.
 *
 * Todas as respostas do envio são UNIFORMES (existe ou não o e-mail) — a
 * anti-enumeração vive no OtpService. O 2FA da performer continua valendo: este
 * controller só estabelece a sessão; o middleware `2fa` desafia depois, igual ao
 * login por senha.
 */
class OtpLoginController extends Controller
{
    use RedirectsToHome;

    /** Chave da sessão onde o e-mail em verificação fica entre as duas telas. */
    private const EMAIL_SESSION_KEY = 'otp_email';

    public function __construct(private OtpService $otp) {}

    /** Tela 1: input do e-mail. */
    public function requestForm(): Response
    {
        return Inertia::render('Auth/OtpRequest');
    }

    /** Envia (ou finge enviar) o código e leva para a tela do código. */
    public function sendCode(RequestOtpRequest $request): RedirectResponse
    {
        $email = (string) $request->validated('email');

        try {
            $this->otp->requestCode($email);
        } catch (OtpThrottleException $e) {
            // O único caso não-uniforme, e ele é idêntico para e-mail existente e
            // inexistente (o teto conta a string antes do lookup).
            throw ValidationException::withMessages([
                'email' => [$e->getMessage()],
            ]);
        }

        // E-mail na SESSÃO, não na URL. Segue para a tela do código.
        $request->session()->put(self::EMAIL_SESSION_KEY, $email);

        return redirect()->route('otp.verify.show');
    }

    /** Tela 2: input do código de 6 dígitos. */
    public function verifyForm(Request $request): Response|RedirectResponse
    {
        $email = $request->session()->get(self::EMAIL_SESSION_KEY);

        // Chegou aqui sem passar pela tela do e-mail (link direto, sessão
        // expirada): volta ao começo em vez de mostrar um formulário órfão.
        if (! $email) {
            return redirect()->route('otp.request.show');
        }

        return Inertia::render('Auth/OtpVerify', [
            'email' => $email,
            'expiresInMinutes' => OtpCode::EXPIRY_MINUTES,
        ]);
    }

    /** Verifica o código e estabelece a sessão. */
    public function verify(VerifyOtpRequest $request): RedirectResponse
    {
        // O e-mail vem da SESSÃO, não do corpo do request. É o que o docblock da
        // classe promete (o endereço não circula pelo cliente entre as telas) e
        // impede que um POST forjado troque o alvo da verificação por outro
        // e-mail. Sem sessão (link direto, expirou), volta ao começo.
        $email = $request->session()->get(self::EMAIL_SESSION_KEY);

        if (! $email) {
            return redirect()->route('otp.request.show');
        }

        $user = $this->otp->verifyCode(
            (string) $email,
            (string) $request->validated('code'),
        );

        if ($user === null) {
            // Mensagem única para todo modo de falha (errado, expirado, queimado):
            // não distingue os casos, pelo mesmo motivo do login por senha.
            throw ValidationException::withMessages([
                'code' => ['Código inválido ou expirado. Peça um novo.'],
            ]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        // regenerate() preserva os dados da sessão: sem este forget, uma marca de
        // 2FA sobrevivente faria a sessão nova nascer já verificada. Cada login
        // prova o fator de novo — mesma disciplina do LoginController.
        $request->session()->forget(TwoFactorService::SESSION_KEY);
        $request->session()->forget(self::EMAIL_SESSION_KEY);

        return redirect()->intended(route($this->homeRouteFor($user)));
    }
}
