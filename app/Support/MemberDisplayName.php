<?php

namespace App\Support;

/**
 * Como a performer VÊ o membro (feat/member-nickname): o apelido que o membro
 * escolheu, ou — se não escolheu — o FanAlias de sempre.
 *
 * É camada de APRESENTAÇÃO e nada mais. O identificador técnico continua sendo o
 * FanAlias (ledger, extrato de ganhos, logs, auditoria) e o `handle` (resolução
 * de alvo). O apelido NUNCA vira chave de nada financeiro.
 *
 * Diferença sutil: o FanAlias é POR PAR (o mesmo membro é um número diferente
 * para cada performer); o apelido é GLOBAL (o mesmo nome para todo mundo, é o
 * personagem público que o membro escolheu). Por isso o apelido, quando existe,
 * ignora o performer_profile_id.
 */
final class MemberDisplayName
{
    /**
     * @param  ?string  $nickname            o `users.nickname` do membro (null/'' = sem apelido)
     * @param  int      $performerProfileId  para o fallback FanAlias (por par)
     * @param  int      $memberId            idem
     * @param  string   $prefix              prefixo do fallback ("Fã #" / "Membro #")
     */
    public static function for(?string $nickname, int $performerProfileId, int $memberId, string $prefix = 'Fã #'): string
    {
        $nickname = is_string($nickname) ? trim($nickname) : '';

        return $nickname !== ''
            ? $nickname
            : FanAlias::label($performerProfileId, $memberId, $prefix);
    }
}
