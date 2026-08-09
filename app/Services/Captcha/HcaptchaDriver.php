<?php

namespace App\Services\Captcha;

/**
 * hCaptcha (Intuition Machines). Verificação em https://api.hcaptcha.com/siteverify
 * — toda a lógica de rede/fail-open está em RemoteCaptchaDriver; aqui só a chave
 * de config. Ver config/captcha.php e docs/CAPTCHA.md.
 */
class HcaptchaDriver extends RemoteCaptchaDriver
{
    protected function provider(): string
    {
        return 'hcaptcha';
    }
}
