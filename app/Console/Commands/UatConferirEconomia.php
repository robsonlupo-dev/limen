<?php

namespace App\Console\Commands;

use App\Models\CustomOrder;
use App\Models\TokenLedger;
use App\Services\AdminMetricsService;
use Illuminate\Console\Command;

/**
 * Auditoria de economia para UAT — read-only. Junta num comando repetível o que antes
 * era colar query no tinker a cada rodada. Quatro blocos:
 *
 *  1) INTEGRIDADE: balance == soma do ledger em TODA carteira (reusa o ledgerHealth do
 *     AdminMetricsService — dono único da consulta; não duplica SQL).
 *  2) SPLIT por vertical: confere o `applied_rate` CONGELADO em cada *_credit contra a
 *     taxa da ECONOMIA.md (80/20 na maioria, 70/30 em live/chamada). Como o valor do
 *     crédito é determinístico (bruto × applied_rate em TokenCreditPolicy), bater a taxa
 *     prova o split. Lançamentos SEM applied_rate são LEGADO (pré-04/08, antes da coluna)
 *     ou seed — contados à parte, nunca como falha.
 *  3) RETENÇÃO: reconstrói o bruto liquidado a partir dos créditos reais (crédito ÷ taxa),
 *     então retido = bruto − ganho. Exclui naturalmente o escrow aberto (ainda sem crédito)
 *     e o legado (sem rate).
 *  4) ESCROW: encomendas ativas (accepted/delivered/disputed) e os tokens retidos nelas.
 *
 * Sai 0 (sucesso) só quando integridade e todos os splits batem. Nada de escrita.
 */
class UatConferirEconomia extends Command
{
    protected $signature = 'uat:conferir-economia';

    protected $description = 'Audita a economia (ledger íntegro + splits por vertical + retenção + escrow). Read-only.';

    /** Vertical => [tipo de gasto, tipo de crédito, taxa esperada da performer em %]. */
    private const VERTICAIS = [
        'gorjeta'   => ['spend_tip',          'tip_credit',          80],
        'presente'  => ['spend_gift',         'gift_credit',         80],
        'conteudo'  => ['spend_content',      'content_credit',      80],
        'ppv'       => ['spend_ppv_message',  'ppv_message_credit',  80],
        'chat'      => ['spend_chat_access',  'chat_access_credit',  80],
        'chamada'   => ['spend_call',         'call_credit',         70],
        'live'      => ['spend_live',         'live_credit',         70],
        'encomenda' => ['spend_custom_order', 'custom_order_credit', 80],
        'fanclub'   => ['spend_fanclub_sub',  'fanclub_sub_credit',  80],
    ];

    public function handle(AdminMetricsService $metrics): int
    {
        $tudoOk = true;

        // ── 1) Integridade do ledger ────────────────────────────────────────────────
        $this->line('== INTEGRIDADE DO LEDGER (balance == soma) ==');
        $health = $metrics->ledgerHealth();
        if ($health['ok']) {
            $this->info("  carteiras verificadas: {$health['checked']}   divergencias: 0   OK");
        } else {
            $tudoOk = false;
            $this->error('  DIVERGENCIAS: '.count($health['divergences']).' (bug de dinheiro — PARAR)');
            foreach ($health['divergences'] as $d) {
                $this->error("    wallet {$d['wallet_id']} / user {$d['user_id']}: balance={$d['wallet_balance']} ledger={$d['ledger_sum']} diff={$d['diff']}");
            }
        }

        // ── 2) Split por vertical (applied_rate vs ECONOMIA) ─────────────────────────
        $this->newLine();
        $this->line('== SPLIT POR VERTICAL (applied_rate vs ECONOMIA.md) ==');
        foreach (self::VERTICAIS as $nome => [$spendType, $creditType, $esperado]) {
            $total = TokenLedger::where('entry_type', $creditType)->count();
            if ($total === 0) {
                $this->line(sprintf('  %-10s esperado %d%%   sem lancamentos', $nome, $esperado));

                continue;
            }

            $semRate = TokenLedger::where('entry_type', $creditType)->whereNull('applied_rate')->count();
            $errados = TokenLedger::where('entry_type', $creditType)
                ->whereNotNull('applied_rate')
                ->where('applied_rate', '!=', $esperado)
                ->count();

            $sufixoLegado = $semRate > 0 ? ", {$semRate} sem rate (legado/seed)" : '';

            if ($errados === 0) {
                $this->info(sprintf('  %-10s esperado %d%%   %d ok%s   OK', $nome, $esperado, $total - $semRate, $sufixoLegado));
            } else {
                $tudoOk = false;
                $this->error(sprintf('  %-10s esperado %d%%   %d com taxa DIVERGENTE   FALHOU%s', $nome, $esperado, $errados, $sufixoLegado));
                // Detalha as taxas divergentes encontradas, para investigar.
                foreach (TokenLedger::where('entry_type', $creditType)->whereNotNull('applied_rate')
                    ->where('applied_rate', '!=', $esperado)
                    ->selectRaw('applied_rate, count(*) n')->groupBy('applied_rate')->get() as $r) {
                    $this->error("      rate={$r->applied_rate}% ({$r->n} lancamentos)");
                }
            }
        }

        // ── 3) Retenção (fluxo real; reconstrói o bruto do crédito) ──────────────────
        $this->newLine();
        $this->line('== RETENCAO (fluxo real, so com applied_rate) ==');
        $bruto = 0.0;
        $ganho = 0.0;
        foreach (self::VERTICAIS as [$spendType, $creditType, $esperado]) {
            $credito = (float) TokenLedger::where('entry_type', $creditType)->whereNotNull('applied_rate')->sum('amount');
            if ($credito == 0.0 || $esperado <= 0) {
                continue;
            }
            $bruto += $credito / ($esperado / 100);
            $ganho += $credito;
        }
        $retido = $bruto - $ganho;
        $pct = $bruto > 0 ? round($retido / $bruto * 100, 1) : 0.0;
        $this->line(sprintf('  bruto liquidado=%.2f   ganho performers=%.2f   retido Limen=%.2f (%.1f%%)', $bruto, $ganho, $retido, $pct));

        // ── 4) Escrow de encomenda em aberto ────────────────────────────────────────
        $this->newLine();
        $this->line('== ESCROW DE ENCOMENDA (retido, ainda nao liquidado) ==');
        $abertos = CustomOrder::whereIn('status', [
            CustomOrder::STATUS_ACCEPTED, CustomOrder::STATUS_DELIVERED, CustomOrder::STATUS_DISPUTED,
        ])->get(['status', 'offered_price_tokens']);
        $tokensEscrow = (int) $abertos->sum('offered_price_tokens');
        $this->line("  pedidos ativos: {$abertos->count()}   tokens em escrow: {$tokensEscrow}");
        foreach ($abertos->groupBy('status') as $status => $grupo) {
            $this->line(sprintf('    %-10s %d pedidos, %d tokens', $status, $grupo->count(), (int) $grupo->sum('offered_price_tokens')));
        }

        // ── Veredito ─────────────────────────────────────────────────────────────────
        $this->newLine();
        if ($tudoOk) {
            $this->info('RESULTADO: economia OK ✅  (integridade + todos os splits batem)');

            return self::SUCCESS;
        }

        $this->error('RESULTADO: ha divergencias 🔴  (ver acima)');

        return self::FAILURE;
    }
}
