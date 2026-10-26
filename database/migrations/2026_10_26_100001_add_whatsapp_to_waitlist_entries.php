<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Opt-in de WhatsApp na tela de continuação da lista de espera
     * (feat/waitlist-continue-screen). Pré-lançamento ainda NÃO tem conta de
     * usuário — o número fica no próprio registro da lista de espera.
     *
     * - `whatsapp`             → E.164 normalizado (+5521995429525). Nullable: opcional.
     * - `whatsapp_consent_at`  → carimbo do consentimento LGPD (caixa desmarcada por padrão).
     * - `whatsapp_synced_at`   → idempotência do push ao Chatwoot.
     *
     * Fora do $fillable (escrita só pelo serviço, nunca por payload) — mesma
     * disciplina de invite_code/invite_token/confirmed_at da lista de espera.
     */
    public function up(): void
    {
        Schema::table('waitlist_entries', function (Blueprint $table) {
            $table->string('whatsapp', 20)->nullable()->after('email');
            $table->timestamp('whatsapp_consent_at')->nullable()->after('whatsapp');
            $table->timestamp('whatsapp_synced_at')->nullable()->after('whatsapp_consent_at');
        });
    }

    public function down(): void
    {
        Schema::table('waitlist_entries', function (Blueprint $table) {
            $table->dropColumn(['whatsapp', 'whatsapp_consent_at', 'whatsapp_synced_at']);
        });
    }
};
