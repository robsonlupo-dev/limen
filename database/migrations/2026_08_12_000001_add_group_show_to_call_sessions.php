<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Group show 1:X (Sprint 15, PR #141). Uma sessão `type=group` é dona da
     * performer e NÃO tem membro único — os membros vivem em `call_session_participants`,
     * cada um com a própria cobrança. Daí `member_id` virar NULLABLE (o 1:1 continua
     * setando; o group deixa NULL).
     *
     * - `max_participants`: teto de MEMBROS do group (2..livekit.max_participants_group);
     *   a sala LiveKit nasce com esse +1 (a performer publica).
     * - `upgrade_price_per_minute`: snapshot do preço 1:1 da performer no START
     *   (congelado — imune a mudança no meio); NULL = upgrade indisponível.
     * - `upgrade_requested_by` / `upgrade_requested_at`: UMA solicitação de upgrade
     *   pendente por vez (2º pedido → 409). Limpos no accept/decline.
     *
     * Todos ficam FORA do $fillable de CallSession — autoridade do GroupShowService
     * (forceFill), nunca de request (disciplina de `type`/`discrete_mode`).
     */
    public function up(): void
    {
        // member_id nullable: MySQL exige dropar a FK antes de alterar a coluna e
        // recriá-la. sqlite não tem FK nomeada da mesma forma — o change() basta.
        if (DB::connection()->getDriverName() === 'mysql') {
            Schema::table('call_sessions', function (Blueprint $table) {
                $table->dropForeign(['member_id']);
            });
        }

        Schema::table('call_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('member_id')->nullable()->change();
            $table->unsignedInteger('max_participants')->nullable()->after('price_per_minute');
            $table->unsignedInteger('upgrade_price_per_minute')->nullable()->after('max_participants');
            $table->unsignedBigInteger('upgrade_requested_by')->nullable()->after('upgrade_price_per_minute');
            $table->timestamp('upgrade_requested_at')->nullable()->after('upgrade_requested_by');
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            Schema::table('call_sessions', function (Blueprint $table) {
                $table->foreign('member_id')->references('id')->on('users')->cascadeOnDelete();
                $table->foreign('upgrade_requested_by')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            Schema::table('call_sessions', function (Blueprint $table) {
                $table->dropForeign(['member_id']);
                $table->dropForeign(['upgrade_requested_by']);
            });
        }

        Schema::table('call_sessions', function (Blueprint $table) {
            $table->dropColumn(['max_participants', 'upgrade_price_per_minute', 'upgrade_requested_by', 'upgrade_requested_at']);
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            Schema::table('call_sessions', function (Blueprint $table) {
                $table->foreign('member_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }
    }
};
