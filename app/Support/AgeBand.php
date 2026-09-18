<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Faixa etária do membro (feat/member-profile-v2). DERIVADA do `birthdate` que o
 * membro já forneceu no cadastro (18+/KYC) — nunca uma coluna nova, nunca a data
 * ou a idade EXATA exibida. Só a faixa grossa vai ao ar, e só quando o membro
 * optou por mostrá-la (`users.show_age_band`).
 *
 * As faixas são as do PO (18-24, 25-30, 31-40, 41-50, 50+). Menor de 18 devolve
 * null — não deveria existir (a plataforma é 18+), mas o guarda evita exibir
 * qualquer coisa nesse caso impossível.
 *
 * Dona única da regra: a mesma faixa alimenta o card do catálogo e a página de
 * perfil, então derivar em dois lugares divergiria justo no limite de uma faixa.
 */
final class AgeBand
{
    public static function for(?CarbonInterface $birthdate): ?string
    {
        if ($birthdate === null) {
            return null;
        }

        $age = (int) $birthdate->age;

        return match (true) {
            $age < 18 => null,
            $age <= 24 => '18-24',
            $age <= 30 => '25-30',
            $age <= 40 => '31-40',
            $age <= 50 => '41-50',
            default => '50+',
        };
    }
}
