<?php

namespace App\Support;

/**
 * Derivado unidirecional do CPF, para o cadastro de membro.
 *
 * O CPF é coletado na verificação de maioridade e NÃO é persistido em texto
 * puro — mesma regra do checkout de tokens, onde ele só transita até o Asaas.
 * Guardar o número traria PII sensível para dentro da tabela de contas sem
 * nenhuma contrapartida: o cadastro não precisa lê-lo de volta.
 *
 * Mas não guardar NADA também custa: a linha de `age_verifications` viraria uma
 * afirmação sem lastro ("um CPF foi apresentado, confie"), e a plataforma
 * perderia a única coisa concreta que coletar CPF compra hoje — detectar que a
 * mesma pessoa abriu duas contas.
 *
 * O HMAC resolve os dois lados: prova auditável de qual CPF foi apresentado (o
 * auditor com o número em mãos refaz o digest e compara) e chave de deduplicação,
 * sem que o número seja recuperável a partir do banco.
 *
 * IMPORTANTE — o espaço de CPF é pequeno (10^11) e enumerável em GPU. Isto é
 * HMAC com a APP_KEY como chave, não hash puro: sem a chave — que mora no .env,
 * fora do Git — um dump do banco não permite a varredura. Vazando APP_KEY *e*
 * banco, os CPFs são recuperáveis por força bruta; o modelo de ameaça aqui é
 * dump de banco isolado, que é o cenário comum.
 *
 * Rotacionar a APP_KEY invalida os digests: a dedupe passa a não enxergar
 * cadastros anteriores à rotação (as contas seguem válidas). Mesma pegadinha do
 * [[FanAlias]] e dos documentos de KYC.
 */
final class CpfHash
{
    /**
     * @param  string  $cpf  com ou sem máscara — a normalização é feita aqui,
     *                       senão "111.444.777-35" e "11144477735" gerariam
     *                       digests diferentes e a dedupe não veria a colisão.
     */
    public static function make(string $cpf): string
    {
        return hash_hmac(
            'sha256',
            'cpf:'.preg_replace('/\D/', '', $cpf),
            (string) config('app.key')
        );
    }

    /** Comparação em tempo constante, para não virar oráculo de timing. */
    public static function matches(string $cpf, string $digest): bool
    {
        return hash_equals(self::make($cpf), $digest);
    }
}
