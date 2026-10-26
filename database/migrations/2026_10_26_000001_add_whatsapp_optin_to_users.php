<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Opt-in de WhatsApp para aviso de lançamento (feat/signup-email-gate-and-whatsapp-optin).
     *
     * Colunas DEDICADAS, não reaproveitam `phone`: `phone` é um telefone qualquer
     * auto-declarado no cadastro (pode nem ser WhatsApp, e não carrega consentimento);
     * aqui o número é um canal de MARKETING com base legal própria (LGPD art. 7º/8º —
     * consentimento específico, informado e destacado). Misturar os dois apagaria a
     * fronteira entre "dado cadastral" e "canal que a pessoa aceitou receber aviso".
     *
     * - `whatsapp`             → número E.164 normalizado (ex.: +5521995429525).
     *   Nullable: o opt-in é OPCIONAL (decisão do PO — não obrigar o número).
     * - `whatsapp_consent_at`  → carimbo do consentimento. A PRESENÇA dele é o registro
     *   LGPD de que a pessoa marcou a caixa (desmarcada por padrão). Null = sem consentimento.
     * - `whatsapp_synced_at`   → quando o contato foi empurrado para o Chatwoot (CRM de
     *   operação, servidor separado). Idempotência do push: null = ainda não sincronizado.
     *
     * As três ficam FORA do $fillable (escrita só pelo WhatsappOptInService/forceFill,
     * nunca por payload) — mesma disciplina de `role`, `registration_ip_hash` e das
     * colunas de privacidade: um canal de contato aceito e o carimbo do consentimento
     * não podem entrar por mass assignment.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('whatsapp', 20)->nullable()->after('phone');
            $table->timestamp('whatsapp_consent_at')->nullable()->after('whatsapp');
            $table->timestamp('whatsapp_synced_at')->nullable()->after('whatsapp_consent_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['whatsapp', 'whatsapp_consent_at', 'whatsapp_synced_at']);
        });
    }
};
