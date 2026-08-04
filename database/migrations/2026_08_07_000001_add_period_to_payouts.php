<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ciclo mensal do payout (M.10): o sweep do dia 1 marca cada linha com o mês
     * que fechou (period_year/period_month). O índice único (performer_id,
     * period_year, period_month) é o backstop de idempotência — no máximo um saque
     * automático por performer por mês, mesmo sob dois runs concorrentes.
     *
     * Saque on-demand fica com period NULL: o MySQL trata NULLs como distintos no
     * índice único, então múltiplos saques on-demand por performer seguem válidos e
     * não colidem com o sweep.
     */
    public function up(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            $table->unsignedSmallInteger('period_year')->nullable()->after('status');
            $table->unsignedTinyInteger('period_month')->nullable()->after('period_year');

            $table->unique(['performer_id', 'period_year', 'period_month'], 'payouts_performer_period_unique');
        });
    }

    public function down(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            $table->dropUnique('payouts_performer_period_unique');
            $table->dropColumn(['period_year', 'period_month']);
        });
    }
};
