<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Emenda de 19/08/2026 — economia de tokens decimal EXATA (split percentual p/ todos
 * os fluxos, arredondamento só no payout).
 *
 * A convenção "tokens são inteiros, nunca float" foi escrita quando TODO split fechava
 * em inteiro — o que só valia por COINCIDÊNCIA de configuração (preços múltiplos de 5/4).
 * A economia real quebra isso: 80% de 2 = 1,60; 80% de 7 (conteúdo) = 5,60; 70% de 13
 * (chamada) = 9,10; tudo fração. E câmbio futuro (token→BRL→USD) gera fração por taxa,
 * que nenhuma grade de preço resolve. Logo amount/balance do ledger passam a
 * DECIMAL(20,4) e a aritmética de carteira a bcmath (App\Support\TokenMath, escala 4) —
 * decimal EXATO, NÃO ponto flutuante.
 *
 * Override registrado (ver CLAUDE.md "Regra Única de Arredondamento"): "tokens são
 * inteiros" continua valendo para o SALDO DO MEMBRO e para PREÇOS; deixa de valer para
 * o CRÉDITO DA PERFORMER. "Nunca float" continua valendo — DECIMAL+bcmath é aritmética
 * decimal exata. Substitui M.13.1 (crédito fixo de chat) e M.13.7 (round-half-up).
 *
 * Por que 4 casas e não 2: o split fecha em ≤2 casas hoje, mas câmbio futuro precisa de
 * mais precisão que o BRL. Guardar 4 e exibir 2 é trivial; o contrário exige nova
 * migração — e migrar coluna de dinheiro depois, com dinheiro real, custa muito mais.
 *
 * NÃO mudam (permanecem inteiros por construção/decisão do PO):
 *  - token_wallets.pending_grant_tokens (franquia pendente é sempre inteira).
 *  - o saldo do MEMBRO (só recebe compra/grant/bonus/refund inteiros e gasta inteiro —
 *    fica X.0000 sempre; a fração só existe na carteira da performer).
 *
 * MUDAM para DECIMAL(20,4): amount/balance_after do ledger, token_wallets.balance, os
 * espelhos de split (tips/gift_sends performer_amount+platform_amount) e payouts.tokens
 * (o payout consome uma fração de token, com a sobra do floor preservada — ver R1–R4).
 *
 * ⚠️ ROLLBACK É LOSSY. Ao subir (up), os inteiros existentes viram X.0000 sem perda.
 * Depois do PRIMEIRO crédito fracionário (ex.: 1,6000), o down() reverte a coluna para
 * inteiro e o MySQL TRUNCA a parte decimal — 1,6000 vira 1, e o invariante
 * balance == SUM(amount) se quebra silenciosamente. Não há down() não-destrutivo depois
 * disso; reverter em produção exige congelar a economia decimal antes. Rodada uma vez,
 * é de mão única na prática.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Conversão inteiro → DECIMAL(20,4): o MySQL promove os inteiros existentes a
        // X.0000 sem perda. NOT NULL preservado (decimal é not-null por padrão);
        // default(0) preservado onde havia.
        Schema::table('token_ledger', function (Blueprint $table) {
            $table->decimal('amount', 20, 4)->change();
            $table->decimal('balance_after', 20, 4)->change();
        });

        Schema::table('token_wallets', function (Blueprint $table) {
            $table->decimal('balance', 20, 4)->default(0)->change();
        });

        // Espelhos do split (fatia da performer / retenção da Limen) também fracionam —
        // senão o mirror trunca (2,40 → 2) e passa a DISCORDAR do ledger. O bruto (o
        // membro paga inteiro) permanece inteiro: tips.amount, gift_sends.tokens,
        // content_unlocks.tokens_paid não mudam.
        Schema::table('tips', function (Blueprint $table) {
            $table->decimal('performer_amount', 20, 4)->change();
            $table->decimal('platform_amount', 20, 4)->change();
        });

        Schema::table('gift_sends', function (Blueprint $table) {
            $table->decimal('performer_amount', 20, 4)->change();
            $table->decimal('platform_amount', 20, 4)->change();
        });

        // O payout consome uma quantidade FRACIONÁRIA de token (a conversão p/ R$ é
        // floored ao centavo — R2 —, e a sobra fica no saldo da performer — R3), então
        // o registro do saque deixa de ser inteiro.
        Schema::table('payouts', function (Blueprint $table) {
            $table->decimal('tokens', 20, 4)->change();
        });
    }

    public function down(): void
    {
        // GUARDA ATIVA (achado da revisão de segurança 🟡#2): em vez de TRUNCAR
        // silenciosamente qualquer fração já gravada (1,6000 → 1, quebrando
        // balance == SUM(amount) sem aviso), o rollback ABORTA se detectar qualquer
        // valor fracionário. Reverter uma coluna de dinheiro com frações vivas é uma
        // decisão que exige congelar a economia decimal antes — não um efeito colateral
        // de um `migrate:rollback` acidental.
        $fractional = collect([
            ['token_ledger', 'amount'],
            ['token_ledger', 'balance_after'],
            ['token_wallets', 'balance'],
            ['tips', 'performer_amount'],
            ['tips', 'platform_amount'],
            ['gift_sends', 'performer_amount'],
            ['gift_sends', 'platform_amount'],
            ['payouts', 'tokens'],
        ])->first(function ($col) {
            [$table, $column] = $col;

            return DB::table($table)->whereRaw("{$column} <> FLOOR({$column})")->exists();
        });

        if ($fractional !== null) {
            [$table, $column] = $fractional;
            throw new RuntimeException(
                "Rollback abortado: {$table}.{$column} tem valores fracionários — reverter para inteiro os TRUNCARIA e quebraria balance == SUM(amount). ".
                'Congele a economia decimal (M.14) e zere as frações antes de reverter.'
            );
        }

        // ⚠️ LOSSY se o guard acima fosse burlado: a fração seria TRUNCADA pelo MySQL
        // ao voltar para inteiro. Ver o docblock do topo.
        Schema::table('token_ledger', function (Blueprint $table) {
            $table->bigInteger('amount')->change();
            $table->bigInteger('balance_after')->change();
        });

        Schema::table('token_wallets', function (Blueprint $table) {
            $table->bigInteger('balance')->default(0)->change();
        });

        Schema::table('tips', function (Blueprint $table) {
            $table->unsignedInteger('performer_amount')->change();
            $table->unsignedInteger('platform_amount')->change();
        });

        Schema::table('gift_sends', function (Blueprint $table) {
            $table->unsignedInteger('performer_amount')->change();
            $table->unsignedInteger('platform_amount')->change();
        });

        Schema::table('payouts', function (Blueprint $table) {
            $table->unsignedInteger('tokens')->change();
        });
    }
};
