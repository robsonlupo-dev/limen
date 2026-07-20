<?php

namespace App\Support;

/**
 * Pseudônimo do membro nas telas da performer.
 *
 * O id cru do membro não pode aparecer para a performer. Enquanto "Fã #" saía de
 * `consumer_id % 10000` e "Membro #" saía do `user_id`, os dois pseudônimos
 * viviam no MESMO espaço e correlacionavam de forma determinística: Membro
 * #12345 nas gorjetas é Fã #2345 — quatro dígitos do id real entregues por quem
 * mandou uma gorjeta, sem passar por piso nenhum (docs/SECURITY_ISSUES.md).
 *
 * Aqui o pseudônimo é derivado por PAR (perfil da performer, membro): o mesmo
 * membro é um número diferente para cada performer, então nada correlaciona
 * entre perfis, e o alias não volta a ser id (HMAC truncado, com a APP_KEY como
 * chave — que já mora no .env e nunca é versionada).
 *
 * Estável de propósito: a performer precisa reconhecer "o Fã #0042 de sempre"
 * entre gorjetas. É um identificador persistente por performer — decisão de
 * produto, não acidente.
 *
 * DOIS derivados, e a distinção importa:
 *  - for()/label(): 4 dígitos, EXIBIÇÃO. Espaço pequeno, então dois seguidores
 *    da mesma performer podem colidir. Nunca use como chave.
 *  - handle(): 16 hex, IDENTIFICAÇÃO. É o que trafega para o front no lugar do
 *    id e volta no POST do Interesse Controlado.
 *
 * Rotacionar a APP_KEY rotaciona todos os pseudônimos de uma vez: a performer
 * perde o histórico ("quem era o Fã #0042?"), mas nada quebra.
 *
 * O id real continua sendo a chave interna em todo lugar — ledger, audit log,
 * reference_id. Isto é camada de apresentação.
 */
final class FanAlias
{
    /**
     * @param  int  $performerProfileId  performer_profile_id (NÃO o user_id da
     *                                   performer) — é a chave que as três telas
     *                                   já têm em mãos. Trocar de uma para outra
     *                                   faria a mesma pessoa aparecer com dois
     *                                   aliases em telas diferentes.
     */
    public static function for(int $performerProfileId, int $memberId): int
    {
        return intval(substr(self::digest($performerProfileId, $memberId), 0, 8), 16) % 10000;
    }

    /** Rótulo pronto: "Fã #0042" / "Membro #7351". */
    public static function label(int $performerProfileId, int $memberId, string $prefix = 'Fã #'): string
    {
        return $prefix.str_pad(
            (string) self::for($performerProfileId, $memberId),
            4,
            '0',
            STR_PAD_LEFT
        );
    }

    /**
     * Referência opaca do membro para o front da performer.
     *
     * Substitui o `member_id` que ia nas props e voltava no POST — sem isto, o
     * alias de exibição seria maquiagem: o id cru continuaria legível no payload
     * do Inertia. 64 bits também fecham a enumeração: adivinhar um handle é
     * inviável, enquanto varrer ids era trivial.
     */
    public static function handle(int $performerProfileId, int $memberId): string
    {
        return substr(self::digest($performerProfileId, $memberId), 0, 16);
    }

    /**
     * Handle → id do membro. Não é reversível: refaz o handle de cada candidato e
     * compara. O chamador passa APENAS os candidatos que a tela mostraria, e é
     * isso que mantém o envio e a lista concordando (FollowerVisibilityService).
     *
     * @param  iterable<int|string>  $candidateMemberIds
     */
    public static function resolveHandle(int $performerProfileId, iterable $candidateMemberIds, string $handle): ?int
    {
        foreach ($candidateMemberIds as $memberId) {
            if (hash_equals(self::handle($performerProfileId, (int) $memberId), $handle)) {
                return (int) $memberId;
            }
        }

        return null;
    }

    private static function digest(int $performerProfileId, int $memberId): string
    {
        return hash_hmac(
            'sha256',
            "fan_alias:{$performerProfileId}:{$memberId}",
            (string) config('app.key')
        );
    }
}
