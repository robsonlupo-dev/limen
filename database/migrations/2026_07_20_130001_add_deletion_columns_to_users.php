<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Direito de eliminação (LGPD art. 18, VI). O pedido não apaga na hora:
     * abre uma janela de arrependimento de 30 dias (deletion_scheduled_at) que
     * o titular pode cancelar. `deleted_at` (soft delete) já existia e continua
     * sendo o marcador de "conta encerrada" — estas colunas são o AGENDAMENTO,
     * não o resultado.
     *
     * O token de confirmação é guardado como HASH, não em claro: quem lê o
     * banco não pode forjar o link do e-mail. Mesma razão do password_reset.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('deletion_requested_at')->nullable()->after('last_login_at');
            $table->timestamp('deletion_scheduled_at')->nullable()->after('deletion_requested_at');
            // Confirmação pelo e-mail: prova que quem pediu tem a caixa, não só
            // a sessão. Não é porta de execução — o job roda pelo prazo (a LGPD
            // corre desde o pedido); isto fica registrado no deletion_log.
            $table->timestamp('deletion_confirmed_at')->nullable()->after('deletion_scheduled_at');
            $table->string('deletion_token_hash', 64)->nullable()->after('deletion_confirmed_at');
            $table->timestamp('deletion_token_expires_at')->nullable()->after('deletion_token_hash');

            // Varredura do job diário: quem já venceu e ainda não foi encerrado.
            $table->index(['deletion_scheduled_at', 'deleted_at'], 'users_deletion_sweep_index');
            // Lookup do link do e-mail (token opaco → usuário).
            $table->index('deletion_token_hash', 'users_deletion_token_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_deletion_sweep_index');
            $table->dropIndex('users_deletion_token_index');
            $table->dropColumn([
                'deletion_requested_at',
                'deletion_scheduled_at',
                'deletion_confirmed_at',
                'deletion_token_hash',
                'deletion_token_expires_at',
            ]);
        });
    }
};
