<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * KYC Nível 2 do MEMBRO é selfie-only (o documento fica para o Sprint 9): a
 * linha de identity_verifications nasce sem document_type/number/nome/nascimento.
 * Essas colunas eram NOT NULL (fluxo da performer, que sempre traz documento) —
 * torná-las nullable é a "estrutura preparada para receber o documento depois"
 * sem inventar placeholder de PII para satisfazer um NOT NULL.
 *
 * A linha da performer continua preenchendo todas: nada no fluxo dela muda.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE identity_verifications MODIFY COLUMN document_type ENUM('cpf','rg','cnh') NULL");
            DB::statement('ALTER TABLE identity_verifications MODIFY COLUMN document_number TEXT NULL');
            DB::statement('ALTER TABLE identity_verifications MODIFY COLUMN full_legal_name TEXT NULL');
            DB::statement('ALTER TABLE identity_verifications MODIFY COLUMN date_of_birth TEXT NULL');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            // Reverter para NOT NULL só é seguro se não houver linha selfie-only
            // (member) com esses campos vazios. Apaga as verificações sem
            // document_type — são exatamente as de membro criadas por este
            // sprint; nenhuma performer tem document_type null.
            DB::table('identity_verifications')->whereNull('document_type')->delete();

            DB::statement("ALTER TABLE identity_verifications MODIFY COLUMN document_type ENUM('cpf','rg','cnh') NOT NULL");
            DB::statement('ALTER TABLE identity_verifications MODIFY COLUMN document_number TEXT NOT NULL');
            DB::statement('ALTER TABLE identity_verifications MODIFY COLUMN full_legal_name TEXT NOT NULL');
            DB::statement('ALTER TABLE identity_verifications MODIFY COLUMN date_of_birth TEXT NOT NULL');
        }
    }
};
