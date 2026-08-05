<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Estado `expired` da chamada 1:1 (Sprint 15, PR #140): o request fica
     * `pending` e vence sozinho em 60s sem resposta da performer. A expiração
     * vale na LEITURA (reconcilePending) — precedente ChatAccess/Story/Boost; o
     * command `calls:expire-pending` é só GC. MySQL exige ALTER da enum (padrão
     * dos add_*_entry_types do ledger); sqlite guarda enum como TEXT e ignora.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE call_sessions MODIFY COLUMN status ENUM('pending','active','ended','declined','expired') NOT NULL DEFAULT 'pending'");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE call_sessions MODIFY COLUMN status ENUM('pending','active','ended','declined') NOT NULL DEFAULT 'pending'");
        }
    }
};
