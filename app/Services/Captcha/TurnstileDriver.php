<?php

namespace App\Services\Captcha;

/**
 * Cloudflare Turnstile. Verificação em
 * https://challenges.cloudflare.com/turnstile/v0/siteverify — o contrato é o
 * mesmo do hCaptcha (POST `secret`+`response`, resposta `success: bool`), então
 * toda a lógica está em RemoteCaptchaDriver; aqui só a chave de config. Ver
 * config/captcha.php e docs/CAPTCHA.md.
 */
class TurnstileDriver extends RemoteCaptchaDriver
{
    protected function provider(): string
    {
        return 'turnstile';
    }
}
