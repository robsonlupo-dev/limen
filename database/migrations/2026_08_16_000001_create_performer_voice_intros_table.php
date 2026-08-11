<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Intro de voz da performer (feat/voice-intro). Clipe curto (≤20s) gravado/enviado
 * pela performer no perfil, isca de engajamento — o membro ouve e fica curioso.
 *
 * O áudio NÃO vai ao ar direto: é higienizado por ffmpeg num job assíncrono
 * (re-encode MP3 + strip de TODO metadado — ID3/GPS/device) e DEPOIS entra numa
 * fila de MODERAÇÃO HUMANA. Só `approved` é servível. Motivo (decisão de PO): o
 * áudio dribla o filtro de texto do chat — a performer poderia negociar
 * encontro/passar contato por voz (risco art. 228). A moderação humana é o
 * controle; anti-CSAM não se aplica a áudio.
 *
 * Ciclo de status:
 *  - processing → job de ffmpeg rodando (nasce aqui, como o vídeo do Sprint 16).
 *  - pending    → sanitizado, aguardando o moderador ouvir.
 *  - approved   → moderador liberou; aparece e toca no perfil.
 *  - rejected   → moderador recusou (com motivo); não vai ao ar.
 *  - failed     → o job de ffmpeg falhou (a performer regrava). Estado técnico,
 *                 distinto de `rejected` (recusa humana) — mesma disciplina do
 *                 pipeline de vídeo (PR #167).
 *
 * UMA intro ativa por performer (UNIQUE em performer_profile_id): regravar
 * SUBSTITUI a anterior (bytes + linha) no PerformerVoiceIntroService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performer_voice_intros', function (Blueprint $table) {
            $table->id();

            // Uma intro por performer. A FK cascadeOnDelete NÃO é o caminho do Hard
            // Delete (o perfil sai por soft-delete/anonimização — item 11 do
            // CLAUDE.md); a varredura explícita mora no DeletionService. O cascade
            // cobre só a remoção real do perfil, que não acontece no fluxo LGPD.
            $table->foreignId('performer_profile_id')
                ->unique()
                ->constrained('performer_profiles')
                ->cascadeOnDelete();

            // Layout do disco privado `performer_voice_intros`. Nasce vazio (o job
            // grava ao terminar). NUNCA sai em JSON ($hidden no model).
            $table->string('path')->default('');

            $table->enum('status', ['processing', 'pending', 'approved', 'rejected', 'failed'])
                ->default('processing');

            // Duração do MP3 higienizado (gravada pelo job). Usada só para exibição
            // ("0:14"); o gate de 20s é aplicado no upload/serviço.
            $table->unsignedSmallInteger('duration_seconds')->nullable();

            // SHA-256 dos bytes JÁ processados (prova, como o Story/Content). Casa
            // contra listas conhecidas e sobrevive ao arquivo. $hidden no model.
            $table->string('content_hash', 64)->nullable();

            // Autoria da moderação. moderated_by é o admin/moderador que decidiu;
            // ambos gravados por forceFill no serviço, nunca por mass assignment.
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();

            // Motivo da recusa (moderador) OU da falha técnica (job). Texto curto
            // mostrado à performer para ela regravar.
            $table->string('reject_reason')->nullable();

            $table->timestamps();

            // A fila de moderação lista por status (pending) na ordem de chegada.
            $table->index(['status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performer_voice_intros');
    }
};
