<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Apelido do membro (feat/member-nickname). O membro ESCOLHE um apelido e é
     * assim que a performer o chama, no lugar do "Fã #NNNN". Camada de APRESENTAÇÃO
     * apenas — o FanAlias segue como identificador técnico no ledger/extrato/logs.
     *
     * `nickname`            = valor de EXIBIÇÃO (caixa original), nullable.
     * `nickname_normalized` = forma normalizada (minúsculas + ascii) — chave ÚNICA
     *                         (case/acento-insensível). NULL não colide (vários
     *                         membros sem apelido). Nunca exibida.
     * `nickname_set_at`     = carimbo da última definição — cooldown de 7 dias
     *                         entre trocas (a performer constrói relação com o nome;
     *                         troca semanal quebra o vínculo e foge de bloqueio).
     *
     * Os três ficam FORA do $fillable; a escrita passa só pelo MemberNicknameService
     * (validação própria + unicidade + cooldown). `nickname_normalized`/`nickname_set_at`
     * são $hidden (internos); `nickname` é público (é o nome que a performer vê).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('nickname', 20)->nullable()->after('name');
            $table->string('nickname_normalized', 40)->nullable()->unique()->after('nickname');
            $table->timestamp('nickname_set_at')->nullable()->after('nickname_normalized');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['nickname_normalized']);
            $table->dropColumn(['nickname', 'nickname_normalized', 'nickname_set_at']);
        });
    }
};
