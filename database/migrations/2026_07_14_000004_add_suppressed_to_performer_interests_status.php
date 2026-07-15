<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 'suppressed' marca o interesse enviado a um membro que optou por sair.
     * A linha existe para que cooldown e limite diário contem igual ao de um
     * membro comum — sem ela, a ausência de cooldown revelava o opt-out à
     * performer (docs/INTEREST_SYSTEM_SPEC.md, seção 6). Nunca aparece na
     * caixa do membro.
     */
    public function up(): void
    {
        // SQLite has no ENUM — values are TEXT, so no ALTER needed there.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE performer_interests MODIFY COLUMN status ENUM('sent','unlocked','suppressed') NOT NULL DEFAULT 'sent'");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            // MySQL refuses to drop an enum value still in use. Estas linhas são
            // invisíveis ao membro por definição; descartá-las é a única remoção
            // coerente — remapear para 'sent' as revelaria a quem optou por sair.
            DB::table('performer_interests')->where('status', 'suppressed')->delete();

            DB::statement("ALTER TABLE performer_interests MODIFY COLUMN status ENUM('sent','unlocked') NOT NULL DEFAULT 'sent'");
        }
    }
};
