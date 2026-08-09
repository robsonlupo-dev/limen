<?php

namespace App\Services\Captcha;

/**
 * Provedor `none` — captcha DESLIGADO. É o padrão de dev/teste/CI e o valor
 * versionado.
 *
 * `verify()` devolve `true` sem tocar na rede: é a garantia de que, mesmo que um
 * chamador futuro passe por cima da regra de validação, nenhuma requisição sai
 * para terceiro com a feature desligada. `frontend()` devolve `null` — a tela
 * não recebe sitekey e o widget nem monta.
 */
class NullCaptchaDriver implements CaptchaDriver
{
    public function verify(?string $token): bool
    {
        return true;
    }

    public function enabled(): bool
    {
        return false;
    }

    public function frontend(): ?array
    {
        return null;
    }
}
