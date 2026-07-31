<?php

namespace App\Services;

use App\Exceptions\OtpThrottleException;
use App\Mail\OtpCodeEmail;
use App\Models\OtpCode;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Login passwordless por código OTP de 6 dígitos por e-mail (Sprint 11).
 *
 * Dona única das regras do fluxo. Convive com o login por senha (AuthService) —
 * é uma porta ALTERNATIVA, não substituta. Os dois princípios que amarram o
 * desenho:
 *
 *  1. **Anti-enumeração.** `requestCode` responde igual para e-mail existente e
 *     inexistente, e o rate limit é aplicado sobre a STRING do e-mail ANTES do
 *     lookup — senão o próprio limite viraria oráculo ("existe porque me
 *     bloqueia"). O status da conta (suspensa/banida) também nunca vaza aqui:
 *     não se manda código, e a resposta é a mesma.
 *
 *  2. **O código é credencial efêmera.** TTL de 5 min, uso único, 5 palpites, e
 *     os anteriores morrem quando um novo nasce. NUNCA entra em `audit_logs`
 *     (a única tabela que o Hard Delete preserva, com IP em claro ao lado) — os
 *     eventos gravam o usuário e o fato, jamais o dígito.
 *
 * A porta web (sessão) e a API (Sanctum) chamam os MESMOS dois métodos daqui —
 * uma segunda cópia da regra numa das portas reabriria a enumeração ou o reuso.
 */
class OtpService
{
    /** Teto de códigos por e-mail por hora. */
    public const MAX_PER_HOUR = 3;

    private const RATE_WINDOW_SECONDS = 3600;

    /**
     * Emite (ou finge emitir) um código para o e-mail.
     *
     * Void e sem sinal de sucesso de propósito: o chamador responde SEMPRE a mesma
     * coisa, exista o e-mail ou não. A única saída não-uniforme é o estouro do
     * rate limit — e ele é idêntico para existente e inexistente, porque conta a
     * string do e-mail antes de qualquer consulta.
     *
     * @throws OtpThrottleException  teto de 3/hora atingido para este e-mail
     */
    public function requestCode(string $email): void
    {
        $email = mb_strtolower(trim($email));
        $key = 'otp-request:'.sha1($email);

        // ANTES do lookup: conta para todo e-mail, existente ou não. O 4º na
        // janela de 1h estoura, sem revelar existência.
        if (RateLimiter::tooManyAttempts($key, self::MAX_PER_HOUR)) {
            throw new OtpThrottleException(RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, self::RATE_WINDOW_SECONDS);

        $user = User::where('email', $email)->first();

        // Inexistente OU bloqueada: nada é enviado, e a resposta lá em cima é a
        // mesma. Suspensa/banida não recebe código pela mesma razão que
        // AuthService::attemptLogin as barra — e não distinguimos "não existe" de
        // "existe mas está bloqueada".
        if ($user === null || $this->isBlocked($user)) {
            return;
        }

        DB::transaction(function () use ($user) {
            // Invalida os anteriores: um novo código nascer aposenta os antigos,
            // senão o membro teria vários válidos ao mesmo tempo e reenviar não
            // reduziria a superfície de palpite. DELETE dos ainda não usados —
            // os usados já não verificam.
            OtpCode::where('user_id', $user->id)->whereNull('used_at')->delete();

            $code = OtpCode::generateCode();

            // `code` é guardado (fora do $fillable, como discrete_mode/2FA), então
            // NÃO pode entrar no array de mass-assignment do create() — ele seria
            // dropado em silêncio e o INSERT quebraria por coluna sem default.
            // Atribuição direta contorna o guard de propósito: a credencial nasce
            // aqui, nunca de um payload de request.
            $otp = $user->otpCodes()->make([
                'expires_at' => now()->addMinutes(OtpCode::EXPIRY_MINUTES),
                'attempts' => 0,
            ]);
            $otp->code = $code;
            $otp->save();

            // ShouldQueue: nunca bloqueia a resposta. O e-mail é discreto —
            // remetente "Limen", assunto neutro (ver OtpCodeEmail).
            Mail::to($user->email)->queue(new OtpCodeEmail($code));

            // Fato + usuário, NUNCA o dígito. audit_logs é preservado no Hard
            // Delete, então o código jamais pode encostar aqui.
            Audit::log('auth.otp_requested', $user);
        });
    }

    /**
     * Verifica um par (e-mail, código). Devolve o usuário no acerto, ou null.
     *
     * Null é a resposta ÚNICA para todos os modos de falha (e-mail inexistente,
     * sem código, expirado, já usado, palpites estourados, dígito errado): o
     * chamador não distingue os casos, para nenhum deles virar oráculo.
     *
     * ── Palpite errado incrementa; o 5º queima o código ─────────────────────
     * `attempts` sobe a cada erro; ao chegar em MAX_ATTEMPTS o código é marcado
     * como usado (queimado), então nem o palpite seguinte com o dígito CERTO
     * entra. Isso limita o força-bruta de um código a 5 tentativas — com só 3
     * códigos/hora, são no máximo 15 palpites/hora contra 10^6.
     */
    public function verifyCode(string $email, string $code): ?User
    {
        $email = mb_strtolower(trim($email));

        $user = User::where('email', $email)->first();

        if ($user === null || $this->isBlocked($user)) {
            return null;
        }

        // Toda a leitura-e-escrita do código sob transação + lockForUpdate: sem
        // serializar os POSTs concorrentes, (a) N palpites errados simultâneos
        // leriam o mesmo `attempts` e o teto de 5 seria furado por corrida (o
        // brute do código deixaria de ser 5 tentativas), e (b) dois acertos ao
        // mesmo tempo com o mesmo código de USO ÚNICO logariam duas vezes. É a
        // mesma disciplina do recovery code do 2FA (CLAUDE.md, § 2FA).
        return DB::transaction(function () use ($user, $code) {
            // O código vivo mais recente. Como os anteriores são apagados na
            // emissão, há no máximo um não-usado; mesmo assim pego o mais novo.
            $otp = OtpCode::where('user_id', $user->id)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($otp === null || ! $otp->isConsumable()) {
                return null;
            }

            // Comparação em tempo constante: o código é secreto, e um `===`
            // vazaria por timing quantos dígitos iniciais bateram.
            if (! hash_equals($otp->code, trim($code))) {
                $otp->attempts++;

                // 5º erro: queima o código. `used_at` é o mesmo marcador do
                // acerto — depois disto, isConsumable() é false para qualquer
                // palpite.
                if ($otp->attempts >= OtpCode::MAX_ATTEMPTS) {
                    $otp->used_at = now();
                }

                $otp->save();

                return null;
            }

            // Acerto: uso único. Marca sob o lock, para o segundo POST com o
            // mesmo código encontrar isConsumable() já false.
            $otp->used_at = now();
            $otp->save();

            $user->forceFill(['last_login_at' => now()])->save();

            // Sem o código no metadata (é credencial). Só o fato e o usuário.
            Audit::log('auth.otp_login', $user);

            return $user;
        });
    }

    /**
     * GC: apaga os códigos já vencidos. A expiração vale na LEITURA
     * (`isConsumable`); isto é só faxina — material de autenticação em claro não
     * pode se acumular no banco indefinidamente, e uma conta que nunca mais pede
     * OTP deixaria seus códigos vencidos para sempre (a invalidação-na-emissão só
     * varre os do próprio user na próxima emissão). Mesmo precedente de
     * `stories:purge` / `visits:purge`. Rodada perdida custa linhas mortas no
     * banco, nunca acesso indevido.
     */
    public function purgeExpired(): int
    {
        return OtpCode::where('expires_at', '<', now())->delete();
    }

    /**
     * Suspensa/banida não loga por OTP — mesmo corte de AuthService. `pending` e
     * `pending_kyc` NÃO são bloqueio: são contas legítimas em onboarding, e o
     * login por senha as deixa entrar (o gate de KYC/onboarding é depois).
     */
    private function isBlocked(User $user): bool
    {
        return $user->status === 'suspended' || $user->status === 'banned';
    }
}
