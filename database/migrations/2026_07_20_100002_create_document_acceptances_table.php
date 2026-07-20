<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Evidência jurídica de aceite dos documentos da performer (Política de
     * Conteúdo Proibido e Contrato de Performance).
     *
     * Append-only por convenção: um aceite nunca é editado nem apagado — versão
     * nova gera LINHA nova. É o histórico que dá o lastro ("em tal data, sob tal
     * versão do texto, deste IP"); sobrescrever a linha destruiria justamente o
     * que a tabela existe para provar.
     *
     * Nenhuma coluna de PII: IP e user-agent entram como HMAC (ver
     * App\Support\ClientFingerprint), nunca em texto puro. Servem para
     * corroborar um aceite contestado — o auditor com o IP em mãos refaz o
     * digest e compara —, não para rastrear navegação. Não há coluna de CPF
     * aqui e o teste `document_acceptances não tem coluna de CPF` guarda isso.
     */
    public function up(): void
    {
        Schema::create('document_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // 'content_policy' | 'performance_contract'. String, não enum de
            // banco: documento novo não deveria exigir ALTER TABLE.
            $table->string('document_type', 32);

            // Data de publicação do texto aceito (config/documents.php).
            $table->string('document_version', 32);

            $table->timestamp('accepted_at');

            // sha256 hex do HMAC. Nullable: request sem IP/UA resolvível (CLI,
            // console) grava o aceite mesmo assim — a evidência principal é a
            // linha, o fingerprint é corroboração.
            $table->char('ip_address_hash', 64)->nullable();
            $table->char('user_agent_hash', 64)->nullable();

            $table->timestamps();

            // Re-submeter a mesma versão não deve empilhar linhas: o unique faz
            // o segundo POST virar no-op idempotente em vez de ruído no
            // histórico. Versão diferente passa — é o re-aceite que queremos.
            $table->unique(['user_id', 'document_type', 'document_version'], 'doc_acceptances_user_type_version_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_acceptances');
    }
};
