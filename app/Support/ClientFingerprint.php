<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Derivado unidirecional de IP e user-agent, para o registro de aceite.
 *
 * O aceite precisa de corroboração ("veio deste IP, deste navegador") mas não
 * precisa LER esses valores de volta — nenhuma tela mostra o IP de quem aceitou.
 * Guardar em texto puro traria dado pessoal (IP é dado pessoal na LGPD) para
 * dentro de uma tabela que já é evidência jurídica, sem contrapartida.
 *
 * Mesma construção do [[CpfHash]] e pelo mesmo motivo: o espaço de IPv4 é
 * enumerável (2^32) e o de user-agents é pequeno na prática, então hash puro
 * seria reversível por varredura. É HMAC com a APP_KEY — sem a chave, que mora
 * no .env fora do Git, um dump do banco não permite a varredura.
 *
 * Rotacionar a APP_KEY invalida os digests: aceites antigos continuam válidos
 * (a linha é a evidência), mas o fingerprint deles deixa de ser conferível.
 */
final class ClientFingerprint
{
    public static function hash(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    /** @return array{ip_address_hash: ?string, user_agent_hash: ?string} */
    public static function of(Request $request): array
    {
        return [
            'ip_address_hash' => self::hash($request->ip()),
            'user_agent_hash' => self::hash($request->userAgent()),
        ];
    }
}
