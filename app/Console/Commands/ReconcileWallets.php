<?php

namespace App\Console\Commands;

use App\Models\TokenLedger;
use App\Models\TokenWallet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReconcileWallets extends Command
{
    protected $signature = 'tokens:reconcile-wallets
        {--dry-run : Only report mismatches, insert nothing}
        {--force : Allow running in the production environment}
        {--allow-negative : Also reconcile wallets whose balance is BELOW the ledger sum (dangerous)}';

    protected $description = 'Insert append-only ledger adjustments so each wallet\'s ledger sum equals its materialized balance (fixes seed residue). Never mutates a balance.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $allowNegative = (bool) $this->option('allow-negative');

        // This command writes to the money ledger. In production it could silently
        // "fix" a real balance↔ledger discrepancy (masking corruption), so it must
        // be opted into explicitly.
        if ($this->getLaravel()->isProduction() && ! $this->option('force')) {
            $this->error('Refusing to run in production without --force (this mutates the token ledger).');

            return self::FAILURE;
        }

        $scanned = 0;
        $adjusted = 0;
        $skippedNegative = 0;

        TokenWallet::query()->orderBy('id')->each(function (TokenWallet $wallet) use (&$scanned, &$adjusted, &$skippedNegative, $dryRun, $allowNegative) {
            $scanned++;

            // Lock the wallet and recompute the sum inside the transaction so a
            // concurrent tip/purchase can't make the diff stale between read and write.
            DB::transaction(function () use ($wallet, &$adjusted, &$skippedNegative, $dryRun, $allowNegative) {
                $locked = TokenWallet::whereKey($wallet->getKey())->lockForUpdate()->first();
                $ledgerSum = (int) TokenLedger::where('wallet_id', $locked->id)->sum('amount');
                $diff = (int) $locked->balance - $ledgerSum;

                if ($diff === 0) {
                    return;
                }

                // A negative diff means the materialized balance is BELOW the ledger
                // sum — that is not seed residue (seed only over-set balance); it is a
                // potential over-debit/loss. Reconciling here would erase the evidence,
                // so skip loudly unless explicitly forced.
                if ($diff < 0 && ! $allowNegative) {
                    $skippedNegative++;
                    $this->warn(sprintf(
                        'wallet #%d: balance=%d < ledger_sum=%d (diff=%d) — NOT seed residue; SKIPPED. Investigate (or --allow-negative).',
                        $locked->id,
                        $locked->balance,
                        $ledgerSum,
                        $diff
                    ));

                    return;
                }

                $adjusted++;
                $this->line(sprintf(
                    'wallet #%d: balance=%d ledger_sum=%d diff=%+d%s',
                    $locked->id,
                    $locked->balance,
                    $ledgerSum,
                    $diff,
                    $dryRun ? ' (dry-run, not written)' : ''
                ));

                if ($dryRun) {
                    return;
                }

                // Add the missing entry — never touch the balance. After this row
                // the ledger sum equals the (unchanged) materialized balance, so
                // balance_after is the current balance. Re-running is a no-op.
                TokenLedger::create([
                    'wallet_id' => $locked->id,
                    'entry_type' => 'staging_seed_backfill',
                    'amount' => $diff,
                    'balance_after' => (int) $locked->balance,
                    'reference_type' => null,
                    'reference_id' => null,
                    'description' => 'Backfill: ledger reconciled to materialized balance',
                ]);
            });
        });

        $summary = sprintf(
            '%sScanned %d wallets, %s %d, skipped %d negative.',
            $dryRun ? '[dry-run] ' : '',
            $scanned,
            $dryRun ? 'would adjust' : 'adjusted',
            $adjusted,
            $skippedNegative
        );

        // Persistent trail for a money-ledger action.
        Log::info('tokens:reconcile-wallets run', [
            'dry_run' => $dryRun,
            'scanned' => $scanned,
            'adjusted' => $adjusted,
            'skipped_negative' => $skippedNegative,
        ]);

        $this->info($summary);

        if ($skippedNegative > 0) {
            $this->warn("{$skippedNegative} wallet(s) had balance below the ledger sum and were skipped — investigate before forcing.");
        }

        return self::SUCCESS;
    }
}
