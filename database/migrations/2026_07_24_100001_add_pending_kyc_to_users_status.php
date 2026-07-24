<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            // Novo estado inicial do membro: cadastrado, mas ainda sem a selfie
            // de verificação (KYC Nível 2). Fica ANTES de 'active' na intenção,
            // mas a ordem do enum é irrelevante — nada compara por ordinal.
            // Acrescentar valor que mantém a coluna em 1 byte é INSTANT-eligible;
            // pedir explícito troca a cópia silenciosa da tabela (que travaria
            // escritas em users no deploy) por uma falha alta (1221 se a
            // migração ficar não-INSTANT por engano).
            DB::statement("ALTER TABLE users MODIFY COLUMN status ENUM('pending','active','suspended','banned','pending_kyc') NOT NULL DEFAULT 'pending', ALGORITHM=INSTANT");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            // Contas paradas em pending_kyc não têm valor equivalente no enum
            // antigo. Voltam para 'pending' (não-verificado, sem acesso), não
            // para 'active': reverter a migração não pode promover a member que
            // nunca enviou a selfie. Sem isso o DROP do valor reescreveria as
            // linhas para '' (ou falharia em strict mode).
            DB::table('users')->where('status', 'pending_kyc')->update(['status' => 'pending']);

            // Remover valor de enum não é INSTANT-eligible — deixa o MySQL escolher.
            DB::statement("ALTER TABLE users MODIFY COLUMN status ENUM('pending','active','suspended','banned') NOT NULL DEFAULT 'pending'");
        }
    }
};
