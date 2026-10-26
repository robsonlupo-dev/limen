<?php

namespace App\Support;

/**
 * Normalização de número de celular brasileiro para E.164 (feat/signup-email-gate).
 * Entrada é texto livre que a pessoa digita ("(11) 91234-5678", "11991234567",
 * "+55 11 9...", etc.); a saída canônica é "+55DDNNNNNNNNN". Dona única da
 * normalização para que o banco e o push ao Chatwoot falem o MESMO formato.
 */
class BrazilPhone
{
    /**
     * Normaliza para E.164 BR (+55 + DDD de 2 dígitos + 9 dígitos do celular).
     * Devolve null quando não é um celular brasileiro plausível — o chamador
     * trata como inválido (nunca grava lixo).
     */
    public static function toE164(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        // Só dígitos.
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if ($digits === '') {
            return null;
        }

        // Tira o prefixo internacional do Brasil se veio junto.
        if (str_starts_with($digits, '55') && strlen($digits) > 11) {
            $digits = substr($digits, 2);
        }

        // Tira um zero de tronco eventual (ex.: 0 11 ...).
        if (strlen($digits) === 12 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        // Agora espera-se DDD (2) + celular (9, começando em 9) = 11 dígitos.
        if (strlen($digits) !== 11) {
            return null;
        }

        $ddd = substr($digits, 0, 2);
        $number = substr($digits, 2);

        // DDD brasileiro válido começa em 1–9 (11–99); celular começa em 9.
        if ((int) $ddd < 11 || $number[0] !== '9') {
            return null;
        }

        return '+55'.$digits;
    }

    /**
     * Máscara para exibição que não revela o número inteiro na tela
     * ("+55 (11) 9••••-••78").
     */
    public static function mask(?string $e164): ?string
    {
        if ($e164 === null || ! str_starts_with($e164, '+55') || strlen($e164) !== 14) {
            return $e164;
        }

        $ddd = substr($e164, 3, 2);
        $last2 = substr($e164, -2);

        return "+55 ({$ddd}) 9••••-••{$last2}";
    }
}
