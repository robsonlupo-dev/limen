<?php

namespace App\Support;

/**
 * Derivado unidirecional do NÚMERO DO DOCUMENTO de KYC, para a lista negra
 * antifraude. Mesma mecânica do [[CpfHash]] — HMAC-SHA256 chaveado pela APP_KEY,
 * irreversível sem a chave, refazível por um auditor com o número em mãos.
 *
 * Namespace próprio (`doc:`) de propósito: hoje o KYC guarda o CPF no campo
 * `document_number`, então cpf e documento coincidem — mas se coincidissem
 * TAMBÉM no digest, o `document_hash` seria cópia do `cpf_hash` e a coluna não
 * carregaria informação nenhuma. Com prefixo distinto os dois espaços ficam
 * separados, e no dia que o documento for um RG/CNH (≠ CPF) a lista já dedupe
 * pelos dois eixos sem migração.
 *
 * Mesma ressalva de rotação de APP_KEY do CpfHash: rotacionar invalida os
 * digests e a dedupe deixa de enxergar o histórico anterior à rotação.
 */
final class DocumentHash
{
    /**
     * @param  string  $documentNumber  com ou sem máscara/pontuação — a
     *                                   normalização (alfanumérico, maiúsculas)
     *                                   é feita aqui para RG com dígito 'X' e
     *                                   máscaras diferentes casarem no digest.
     */
    public static function make(string $documentNumber): string
    {
        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $documentNumber));

        return hash_hmac('sha256', 'doc:'.$normalized, (string) config('app.key'));
    }

    /** Comparação em tempo constante, para não virar oráculo de timing. */
    public static function matches(string $documentNumber, string $digest): bool
    {
        return hash_equals(self::make($documentNumber), $digest);
    }
}
