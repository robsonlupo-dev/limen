<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PPV no chat (Onda 4, §4.1) — mensagem TRAVADA: a performer manda uma PEÇA do
 * cofre dela (performer_content, já moderada) com um preço próprio; o membro paga
 * para desbloquear. Mesma convenção das outras mídias do chat: NÃO há coluna de
 * "tipo" — a PRESENÇA de `ppv_price_tokens` marca a mensagem como PPV (isPpv()).
 *
 * - `ppv_content_id` aponta para a peça do cofre. nullOnDelete: se a performer
 *   apagar a peça, a mensagem fica sem ponteiro (a bolha vira "indisponível") em vez
 *   de cascatear e sumir a mensagem. O desbloqueio (content_unlocks) é que guarda o
 *   direito do membro — não esta coluna.
 * - `ppv_price_tokens` é o preço DESTE envio (a performer precifica na hora). Fora do
 *   fillable e gravado por forceCreate no ChatService — nunca de array de request.
 *
 * O DESBLOQUEIO reusa `content_unlocks` (par peça×membro, UNIQUE): pagar um PPV cria a
 * mesma linha de unlock do cofre, então o membro passa a ver a peça também na galeria
 * e o serving/paywall (ContentVisibilityService) já reconhece o acesso — nenhuma
 * tabela nova, nenhuma porta de serving nova.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('ppv_content_id')
                ->nullable()
                ->after('reply_to_story_id')
                ->constrained('performer_content')
                ->nullOnDelete();
            $table->unsignedInteger('ppv_price_tokens')
                ->nullable()
                ->after('ppv_content_id');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ppv_content_id');
            $table->dropColumn('ppv_price_tokens');
        });
    }
};
