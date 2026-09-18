<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Presente entregue no chat (feat/gift-from-profile). Quando o membro envia um
     * presente pelo PERFIL da performer (fora da live), o presente é registrado no
     * chat 1:1 do par como uma mensagem de presente que renderiza o ícone do item.
     *
     * `gift_id` = ponteiro OPCIONAL para o catálogo de presentes (gifts). NULL na
     * imensa maioria das mensagens (texto normal). Presença de `gift_id` marca a
     * mensagem como "de presente" na renderização — não há coluna de tipo/enum, o
     * ponteiro basta. É só EXIBIÇÃO: a economia do presente (débito/crédito/split)
     * vive em gift_sends/token_ledger, esta linha não move dinheiro.
     *
     * nullOnDelete é defensivo: o catálogo é dado da Limen e nunca some, mas se um
     * presente fosse removido a mensagem vira texto simples em vez de FK órfã.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('gift_id')->nullable()->after('body')
                ->constrained('gifts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('gift_id');
        });
    }
};
