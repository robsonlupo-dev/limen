<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quem pode ver a foto efêmera de quem, e até quando. Uma linha por par
     * (foto, performer). Ver docs/SECURITY_ISSUES.md § 1.8.
     *
     * ── Metadado que sobreviveria ao conteúdo, e não pode ──────────────────
     * Em claro, esta tabela é **um mapa persistente de quem mostrou o rosto para
     * quem**. Dentro de um chat 1:1 já estabelecido o horário acrescenta pouco —
     * a conversa já identifica o par —, mas a retenção acrescenta muito: os
     * bytes vão embora no TTL e o mapa ficaria. Por isso a decisão do § 1.8:
     * **a linha de acesso morre junto com a foto**. Se um dia for preciso
     * contador para abuso, guarda-se o contador, não as linhas.
     *
     * Quem executa isso é o `member-photos:purge`, em DELETE de verdade. O
     * `cascadeOnDelete` abaixo **não** cobre: `member_photos` sai por soft
     * delete, e FK não dispara em UPDATE de `deleted_at` (item 11 do CLAUDE.md).
     * O cascade fica como rede para o hard delete de banco — nunca como o
     * mecanismo em que o produto confia.
     *
     * ── `viewed_at` ────────────────────────────────────────────────────────
     * Registrado, nunca exposto à performer (é a ação dela) e nunca em relógio
     * para o membro. Ver MemberPhotoAccess::presentForPerformer().
     *
     * ── `expires_at` próprio ───────────────────────────────────────────────
     * O acesso tem prazo próprio porque pode ser revogado antes da foto, mas
     * **nunca sobrevive a ela**: o grant carimba o menor entre os dois, e a
     * leitura confere os dois de novo (MemberPhotoService::readForPerformer()).
     */
    public function up(): void
    {
        Schema::create('member_photo_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_photo_id')->constrained('member_photos')->cascadeOnDelete();
            $table->foreignId('performer_profile_id')->constrained('performer_profiles')->cascadeOnDelete();
            $table->timestamp('granted_at');
            $table->timestamp('expires_at');
            $table->timestamp('viewed_at')->nullable();
            $table->timestamps();

            // Um acesso por par: reenviar a mesma foto para a mesma performer
            // renova a linha existente, não empilha uma segunda. Sem isto, o
            // "com quantas performers você compartilhou" (§ 1.1) contaria
            // grants em vez de pessoas.
            $table->unique(['member_photo_id', 'performer_profile_id']);

            // A leitura da performer entra por aqui: os acessos dela, dentro do
            // prazo.
            $table->index(['performer_profile_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_photo_access');
    }
};
