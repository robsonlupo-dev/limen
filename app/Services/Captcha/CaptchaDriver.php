<?php

namespace App\Services\Captcha;

/**
 * Contrato de um provedor de captcha.
 *
 * Três implementações: HcaptchaDriver, TurnstileDriver e NullCaptchaDriver
 * (provedor `none`). O CaptchaManager escolhe qual instância responde a partir
 * de config('captcha.provider'), e TODO chamador — a regra de validação e as
 * props do Inertia — fala só com o manager, nunca com um driver concreto. É a
 * "dona única" do contrato: a quinta porta que precisar de captcha pergunta ao
 * manager, não reimplementa a escolha do provedor.
 */
interface CaptchaDriver
{
    /**
     * O token confere?
     *
     *  - provedor diz sucesso            → true;
     *  - provedor diz falha (forjado,
     *    expirado, já usado)             → false;
     *  - provedor fora do ar (rede/5xx)  → true, com log (fail-open — ver
     *    config/captcha.php, item 3).
     */
    public function verify(?string $token): bool;

    /**
     * Este provedor exige o campo no formulário? `false` para o driver `none`,
     * que torna o campo `nullable` e o verificador um no-op.
     */
    public function enabled(): bool;

    /**
     * Dados PÚBLICOS que a tela de auth precisa para montar o widget, ou `null`
     * quando desligado. NUNCA inclui o segredo — só o provider e a chave
     * pública. Consumido por HandleInertiaRequests via CaptchaManager.
     *
     * @return array{provider: string, sitekey: string|null}|null
     */
    public function frontend(): ?array;
}
