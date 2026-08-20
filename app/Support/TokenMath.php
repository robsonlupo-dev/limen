<?php

namespace App\Support;

/**
 * Aritmética decimal EXATA de tokens (bcmath, escala fixa 4 — ver `SCALE`). Dona
 * única do cálculo de saldo/split desde a emenda de 19/08/2026 (CLAUDE.md M.14), que
 * moveu TODA a economia para split percentual decimal: 80% de 2 = 1,60 — não fecha em
 * inteiro, então o ledger deixou de ser inteiro (colunas DECIMAL(20,4)).
 *
 * "Dinheiro/tokens nunca float" (Convenções do CLAUDE.md) CONTINUA valendo: DECIMAL
 * + bcmath é aritmética decimal EXATA, não ponto flutuante. Esta classe é a
 * PRECISÃO da regra, não seu abandono — nenhum operador nativo (+ - < (int) (float))
 * pode tocar amount/balance, porque a coerção a float reintroduziria exatamente o
 * erro de arredondamento que o modelo inteiro evitava (o `intdiv` de M.13.7 era a
 * forma antiga; o split exato é a nova, e as duas se substituem — não convivem).
 *
 * Canônico em toda a camada de tokens: STRING decimal de escala 4 ("1500.0000",
 * "4.8000", "-2.0000"). Entrada aceita int OU string; a saída é sempre a string
 * canônica. `display()` é a ÚNICA saída destinada a olho humano — o backend nunca
 * a consome de volta.
 */
final class TokenMath
{
    // Escala 4 (não 2): o crédito de split tem no máximo 2 casas hoje, mas o
    // câmbio futuro (token→BRL→USD) gera mais casas, e migrar coluna de dinheiro
    // depois, com dinheiro real, custa muito mais que reservar precisão agora.
    // Guardar 4 e exibir 2 é trivial; o contrário exige nova migração.
    public const SCALE = 4;

    /** Zero canônico. */
    public const ZERO = '0.0000';

    /** Normaliza int|string para a string canônica de escala 4. */
    public static function of(int|string $v): string
    {
        return bcadd((string) $v, '0', self::SCALE);
    }

    public static function add(int|string $a, int|string $b): string
    {
        return bcadd((string) $a, (string) $b, self::SCALE);
    }

    public static function sub(int|string $a, int|string $b): string
    {
        return bcsub((string) $a, (string) $b, self::SCALE);
    }

    public static function mul(int|string $a, int|string $b): string
    {
        return bcmul((string) $a, (string) $b, self::SCALE);
    }

    /** -1 se a<b, 0 se a==b, 1 se a>b (comparação exata em escala 4). */
    public static function cmp(int|string $a, int|string $b): int
    {
        return bccomp((string) $a, (string) $b, self::SCALE);
    }

    public static function isPositive(int|string $v): bool
    {
        return self::cmp($v, 0) > 0;
    }

    /** Menor valor entre os argumentos, na string canônica original. */
    public static function min(int|string $first, int|string ...$rest): string
    {
        $m = self::of($first);
        foreach ($rest as $v) {
            if (self::cmp($v, $m) < 0) {
                $m = self::of($v);
            }
        }

        return $m;
    }

    /** Maior valor entre os argumentos, na string canônica original. */
    public static function max(int|string $first, int|string ...$rest): string
    {
        $m = self::of($first);
        foreach ($rest as $v) {
            if (self::cmp($v, $m) > 0) {
                $m = self::of($v);
            }
        }

        return $m;
    }

    /**
     * Parte inteira (floor para valores não-negativos) como int nativo. Usado onde o
     * movimento DEVE ser inteiro por construção — grant/franquia pendente (coluna
     * unsignedInteger): o "quarto de token" de sobra da carteira da performer nunca
     * vira um grant fracionário nem uma pendência fracionária. bcadd escala 0 trunca
     * em direção a zero (= floor porque a entrada é ≥ 0).
     */
    public static function intFloor(int|string $v): int
    {
        return (int) bcadd(self::of($v), '0', 0);
    }

    /**
     * Saldo "legível": INT nativo quando o valor é inteiro ("90.00" → 90), STRING
     * decimal quando é fracionário ("4.8000"). É o contrato de `TokenService::balance()`
     * — casa com o modelo do PO ("saldo do membro é inteiro por construção": o membro
     * só se move em inteiros, então lê int; a carteira da performer, que recebe frações
     * do split, lê a string exata). Callers que fazem aritmética já passam por este
     * helper (aceita int|string), então o tipo de união nunca vira ramo `is_int()`.
     */
    public static function readable(int|string $v): int|string
    {
        $norm = self::of($v);

        return str_ends_with($norm, '.0000') ? self::intFloor($norm) : $norm;
    }

    /**
     * Exibição amigável: inteiro sem casas ("1500"), fracionário com zeros à direita
     * aparados ("4.8000" → "4.8"). SÓ para UI/log — nunca é reconsumida pelo backend.
     */
    public static function display(int|string $v): string
    {
        $norm = self::of($v);

        // Inteiro → sem casas. Fracionário → zeros à direita aparados ("1.6000"
        // → "1.6"), SEM float — o canônico de 4 casas nunca é reconsumido daqui.
        // Nunca é dinheiro sacado: o payout tem seu próprio floor exato (R2).
        if (str_ends_with($norm, '.0000')) {
            return (string) self::intFloor($norm);
        }

        return rtrim(rtrim($norm, '0'), '.');
    }
}
