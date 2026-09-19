<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suspensão TEMPORIZADA (feat/moderator-actions). O enum `status` já tem
 * `suspended`, mas era indefinido (só o admin reativava). `suspended_until` dá
 * prazo: quando passa, a própria porta de login reativa a conta (AuthService).
 * NULL = suspensão indefinida (o caso do painel admin de membros) — segue
 * barrando até reativação manual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('suspended_until')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('suspended_until');
        });
    }
};
