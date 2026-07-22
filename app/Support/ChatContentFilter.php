<?php

namespace App\Support;

use Illuminate\Support\Str;
use Normalizer;

/**
 * Casa o corpo de uma mensagem contra as duas categorias de
 * config/chat_filters.php e diz QUAL delas casou.
 *
 * A categoria importa porque as respostas são diferentes: risco legal explica
 * a violação (Termos de Uso), conduta explica a política, e só conduta é
 * marcada para moderação.
 *
 * Ver o cabeçalho do config para o que este filtro deliberadamente NÃO barra —
 * troca de contato, palavrão consensual e encontro sem valor passam.
 */
final class ChatContentFilter
{
    public const LEGAL = 'legal';

    public const CONDUCT = 'conduct';

    /**
     * @return array{category: string, rule: string}|null
     */
    public static function match(string $body): ?array
    {
        if (! config('chat_filters.enabled')) {
            return null;
        }

        $text = self::normalize($body);

        // Risco legal primeiro: é a categoria com consequência jurídica, e uma
        // mensagem que dispara as duas deve ser reportada como a mais grave.
        if ($rule = self::matchLegal($text)) {
            return ['category' => self::LEGAL, 'rule' => $rule];
        }

        if ($rule = self::matchConduct($text)) {
            return ['category' => self::CONDUCT, 'rule' => $rule];
        }

        return null;
    }

    public static function blocks(string $body): bool
    {
        return self::match($body) !== null;
    }

    public static function categoryOf(string $body): ?string
    {
        return self::match($body)['category'] ?? null;
    }

    /**
     * Digest da REGRA para o audit — HMAC com a APP_KEY, como o
     * [[ClientFingerprint]].
     *
     * Hash simples não serviria: a lista está no repo, então `sha256` seria
     * revertido por tabela em segundos. Com HMAC, quem tem só o banco não monta
     * a tabela; quem tem a chave e a lista confere "foi esta regra?", que é o
     * que a calibração precisa.
     */
    public static function digest(string $rule): string
    {
        return hash_hmac('sha256', self::normalize($rule), (string) config('app.key'));
    }

    // ─── Tipo 1: risco legal ────────────────────────────────────────────────

    private static function matchLegal(string $text): ?string
    {
        foreach ((array) config('chat_filters.legal.phrases') as $phrase) {
            if (self::contains($text, (string) $phrase)) {
                return (string) $phrase;
            }
        }

        // Termo ambíguo só conta acompanhado de dinheiro. É o que separa
        // "vamos num motel" (vida pessoal de adultos, permitido) de
        // "motel, 300 reais" (intermediação, bloqueado).
        if (! self::hasMoneySignal($text)) {
            return null;
        }

        foreach ((array) config('chat_filters.legal.requires_money') as $term) {
            if (self::contains($text, (string) $term)) {
                return (string) $term.' + valor';
            }
        }

        return null;
    }

    private static function hasMoneySignal(string $text): bool
    {
        foreach ((array) config('chat_filters.legal.money_signals') as $signal) {
            if (self::contains($text, (string) $signal)) {
                return true;
            }
        }

        return false;
    }

    // ─── Tipo 2: conduta ────────────────────────────────────────────────────

    private static function matchConduct(string $text): ?string
    {
        foreach ((array) config('chat_filters.conduct.threats') as $threat) {
            if (self::contains($text, (string) $threat)) {
                return (string) $threat;
            }
        }

        // Qualificador consensual em qualquer lugar da mensagem desarma o
        // insulto direcionado: "sua puta safada" é dirty talk, "sua puta
        // nojenta" não. Checado na mensagem inteira, e não só colado ao
        // xingamento, porque a fala real intercala ("sua puta, que gostosa").
        if (self::hasConsensualQualifier($text)) {
            return null;
        }

        foreach ((array) config('chat_filters.conduct.directed_insults') as $insult) {
            if (preg_match(self::directedInsultPattern((string) $insult), $text) === 1) {
                return 'direcionado: '.$insult;
            }
        }

        return null;
    }

    private static function hasConsensualQualifier(string $text): bool
    {
        foreach ((array) config('chat_filters.conduct.consensual_qualifiers') as $qualifier) {
            if (self::contains($text, (string) $qualifier)) {
                return true;
            }
        }

        return false;
    }

    /**
     * O xingamento só conta DIRECIONADO — precedido de pronome/possessivo, com
     * até duas palavras de folga no meio ("você é uma vaca").
     *
     * Sem o direcionamento, 'puta' casaria em "que puta gostosa" e "puta
     * merda", que é o vocabulário normal de uma plataforma adulta. O que muda
     * o sentido não é a palavra, é para quem ela aponta.
     */
    private static function directedInsultPattern(string $insult): string
    {
        $pronouns = '(?:voce|vc|tu|sua|seu|tua|teu)';
        $filler = '(?:\s+\p{L}+){0,2}';

        return '/(?<![\p{L}\p{N}])'.$pronouns.$filler.'\s+'
            .self::fuzzy(self::normalize($insult))
            .'(?![\p{L}\p{N}])/u';
    }

    // ─── Casamento e normalização ───────────────────────────────────────────

    private static function contains(string $text, string $needle): bool
    {
        $needle = self::normalize($needle);

        if ($needle === '') {
            return false;
        }

        return preg_match(
            '/(?<![\p{L}\p{N}])'.self::fuzzy($needle).'(?![\p{L}\p{N}])/u',
            $text,
        ) === 1;
    }

    /**
     * Normaliza para desarmar o desvio preguiçoso.
     *
     * A ORDEM importa, e os dois primeiros passos existem porque a revisão de
     * segurança do Sprint 6 achou dois bypasses de copiar-e-colar:
     *
     *  1. `\p{Cf}` (zero-width, joiners, marcas de direção) sai ANTES de
     *     tudo. O `Str::ascii` convertia o ZWSP em espaço de verdade, então
     *     "wh<ZWSP>atsapp" virava duas palavras e escapava.
     *  2. Normalização de COMPATIBILIDADE (NFKC) colapsa fullwidth em ASCII.
     *     Sem ela o `Str::ascii` DESCARTAVA os caracteres, e uma mensagem
     *     inteira em fullwidth normalizava para string vazia — casava nada.
     */
    private static function normalize(string $value): string
    {
        $value = (string) preg_replace('/\p{Cf}/u', '', $value);

        if (class_exists(Normalizer::class)) {
            $value = (string) Normalizer::normalize($value, Normalizer::FORM_KC);
        }

        $value = Str::lower($value);
        $value = Str::ascii($value);

        $value = strtr($value, [
            '4' => 'a', '@' => 'a',
            '3' => 'e',
            '1' => 'i', '!' => 'i',
            '0' => 'o',
            '5' => 's', '$' => 's',
            '7' => 't',
        ]);

        // Colapsa 3+ repetições em 2 ('zaaaap' → 'zaap'); duas e não uma para
        // não destruir dígrafo legítimo ('carro', 'nossa').
        return (string) preg_replace('/(.)\1{2,}/u', '$1$1', $value);
    }

    /**
     * Padrão tolerante do termo: cada caractere 1 ou 2 vezes (pega o
     * alongamento que a normalização deixou), espaço flexível em frase.
     *
     * Concatenação simples de `{1,2}`, sem quantificador aninhado — não há
     * backtracking catastrófico (medido em <0,2 ms sobre os 1000 caracteres
     * de CHAT_MESSAGE_MAX_LENGTH).
     */
    private static function fuzzy(string $normalized): string
    {
        $chars = preg_split('//u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $pattern = '';
        foreach ($chars as $char) {
            $pattern .= $char === ' '
                ? '\s+'
                : preg_quote($char, '/').'{1,2}';
        }

        return $pattern;
    }
}
