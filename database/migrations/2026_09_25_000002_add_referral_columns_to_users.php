<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Colunas de indicação no usuário (feat/referral-program).
     *
     * - `referral_code`        → código único do usuário (o que ele compartilha).
     *   Nullable: só é gerado sob demanda (cadastro novo ou 1ª visita à tela
     *   "meu link"); usuários antigos ficam null até gerarem. UNIQUE permite
     *   múltiplos NULL no MySQL, então a unicidade só vale para códigos reais.
     * - `referred_by_user_id`  → quem indicou este usuário. Gravado UMA vez, no
     *   cadastro, e IMUTÁVEL depois (nenhum caminho de escrita o altera). FK com
     *   nullOnDelete: se o indicador for excluído (LGPD), a indicação perde o
     *   vínculo mas o indicado permanece.
     * - `referred_via`         → 'link' (veio de ?ref= / cookie) ou 'code' (digitou
     *   o campo no cadastro). Só métrica.
     *
     * `referral_code`/`referred_by_user_id`/`referred_via` ficam FORA do $fillable
     * (escrita só pelo ReferralService/AuthService, nunca payload) — mesma
     * disciplina de `role` e `registration_ip_hash`.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('referral_code', 32)->nullable()->unique()->after('asaas_customer_id');
            $table->foreignId('referred_by_user_id')->nullable()->after('referral_code')
                ->constrained('users')->nullOnDelete();
            $table->string('referred_via', 16)->nullable()->after('referred_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referred_by_user_id');
            $table->dropColumn(['referral_code', 'referred_via']);
        });
    }
};
