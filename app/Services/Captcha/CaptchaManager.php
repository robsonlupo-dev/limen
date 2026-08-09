<?php

namespace App\Services\Captcha;

/**
 * Dona única da escolha do provedor de captcha.
 *
 * Resolve o driver a partir de config('captcha.provider') e é o ÚNICO ponto que
 * a regra de validação (App\Rules\CaptchaValid) e as props do Inertia
 * (HandleInertiaRequests) consultam. Trocar de provedor, ligar ou desligar é
 * uma linha no .env — nenhum chamador conhece o driver concreto.
 *
 * Sem estado: o driver é resolvido A CADA chamada (não cacheado em propriedade)
 * para que uma troca de config em teste tenha efeito imediato.
 */
class CaptchaManager
{
    public function driver(): CaptchaDriver
    {
        return match (config('captcha.provider')) {
            'hcaptcha' => app(HcaptchaDriver::class),
            'turnstile' => app(TurnstileDriver::class),
            default => app(NullCaptchaDriver::class),
        };
    }

    /** Há um provedor ativo (qualquer coisa que não seja `none`)? */
    public function enabled(): bool
    {
        return $this->driver()->enabled();
    }

    public function verify(?string $token): bool
    {
        return $this->driver()->verify($token);
    }

    /**
     * Props PÚBLICAS para a tela de auth montar o widget. Formato estável para o
     * front: sempre `enabled`, e quando ligado também `provider`/`sitekey`.
     * Desligado, nenhum sitekey vaza (frontend() do NullCaptchaDriver é null).
     *
     * @return array{enabled: bool, provider: string|null, sitekey: string|null}
     */
    public function sharedProps(): array
    {
        $frontend = $this->driver()->frontend();

        return [
            'enabled' => $frontend !== null,
            'provider' => $frontend['provider'] ?? null,
            'sitekey' => $frontend['sitekey'] ?? null,
        ];
    }
}
