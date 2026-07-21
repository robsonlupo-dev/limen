<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Casa o corpo de uma mensagem contra a lista de termos barrados
 * (config/chat_filters.php).
 *
 * Devolve o termo que casou — para o AUDIT, nunca para o remetente. Quem
 * responde ao usuário usa mensagem genérica: dizer qual palavra derrubou a
 * mensagem é entregar o mapa da evasão de graça (a pessoa reescreve trocando só
 * aquela palavra e o filtro deixa de ver qualquer coisa).
 *
 * Ver o cabeçalho de config/chat_filters.php para o que este filtro NÃO é.
 */
final class ChatContentFilter
{
    /**
     * Primeiro termo barrado encontrado, ou null.
     *
     * Só o PRIMEIRO: a resposta ao usuário é a mesma de qualquer jeito, e
     * varrer o resto só gastaria ciclo.
     */
    public static function firstMatch(string $body): ?string
    {
        if (! config('chat_filters.enabled')) {
            return null;
        }

        $haystack = self::normalize($body);

        foreach ((array) config('chat_filters.terms') as $term) {
            $term = (string) $term;

            if ($term !== '' && preg_match(self::patternFor($term), $haystack) === 1) {
                return $term;
            }
        }

        return null;
    }

    public static function blocks(string $body): bool
    {
        return self::firstMatch($body) !== null;
    }

    /**
     * Digest do termo para o audit log — HMAC com a APP_KEY, como o
     * [[ClientFingerprint]], e pelo mesmo motivo.
     *
     * Hash simples não serviria: a lista de termos é pública (está no repo) e
     * tem dezenas de entradas, então um `sha256` seria revertido por tabela em
     * segundos. Com HMAC, quem só tem o banco não consegue montar a tabela — e
     * quem tem a APP_KEY e a lista consegue conferir "foi este termo?", que é
     * exatamente o que a operação precisa para calibrar a lista.
     */
    public static function digest(string $term): string
    {
        return hash_hmac('sha256', self::normalize($term), (string) config('app.key'));
    }

    /**
     * Normaliza para desarmar o desvio preguiçoso:
     * caixa, acento ('endereço' → 'endereco'), leet ('wh4ts' → 'whats') e
     * alongamento ('zaaaap' → 'zaap', que o padrão de repetição resolve).
     *
     * Não tenta resolver separador no meio da palavra ('z a p', 'w-h-a-t-s'):
     * remover espaço antes de casar juntaria palavras vizinhas legítimas e
     * criaria falso positivo em cima de falso positivo ("faça o pi**x fora** de
     * hora" já seria borderline; "conta" grudaria em qualquer coisa).
     */
    private static function normalize(string $value): string
    {
        $value = Str::lower($value);

        // Transliteração ASCII: resolve acento e cirílico/homoglifo simples.
        $value = Str::ascii($value);

        $value = strtr($value, [
            '4' => 'a', '@' => 'a',
            '3' => 'e',
            '1' => 'i', '!' => 'i',
            '0' => 'o',
            '5' => 's', '$' => 's',
            '7' => 't',
        ]);

        // Colapsa 3+ repetições da mesma letra em 2 ('zaaaap' → 'zaap'). Duas e
        // não uma para não destruir dígrafo legítimo do português ('carro',
        // 'nossa') — o padrão de busca tolera a sobra.
        return (string) preg_replace('/(.)\1{2,}/u', '$1$1', $value);
    }

    /**
     * Padrão do termo: fronteira de palavra de verdade (não `\b`, que trata
     * acento como fronteira), espaço flexível em termo composto, e tolerância a
     * uma letra repetida — o mesmo alongamento que a normalização deixou passar.
     */
    private static function patternFor(string $term): string
    {
        $normalized = self::normalize($term);

        // Cada caractere pode aparecer 1 ou 2 vezes ('zap' casa 'zaap').
        $chars = preg_split('//u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $body = '';
        foreach ($chars as $char) {
            $body .= $char === ' '
                ? '\s+'
                : preg_quote($char, '/').'{1,2}';
        }

        // (?<![\p{L}\p{N}]) / (?![\p{L}\p{N}]) = fronteira que entende unicode.
        // Sem ela 'fone' casaria dentro de 'telefone' e 'zap' dentro de
        // 'zapping' — falso positivo garantido em português.
        return '/(?<![\p{L}\p{N}])'.$body.'(?![\p{L}\p{N}])/u';
    }
}
