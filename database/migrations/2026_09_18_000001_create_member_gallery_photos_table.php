<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Galeria de fotos do MEMBRO (feat/member-gallery-and-profile, Opção B). Até 4
 * fotos opt-in que o membro sobe para o próprio perfil; a performer as vê SÓ se o
 * membro ligar o perfil visível (`users.profile_visible`) e SÓ as `approved`.
 *
 * NÃO confundir com `member_photos` (foto EFÊMERA do chat, cifrada e com TTL,
 * membro→performer). Esta é a foto de PERFIL: disco privado `local` sem cifra
 * (como o avatar do membro), servida por rota assinada chaveada num token OPACO,
 * e — por ser rosto de usuário em site adulto — SÓ vai ao ar depois de moderação
 * humana (mesmo gate da intro de voz da performer: pending → approved/rejected).
 *
 * Ciclo de status:
 *  - pending  → subiu, sanitizada + anti-CSAM no upload, aguardando o moderador.
 *  - approved → moderador liberou; aparece no perfil/catálogo para a performer.
 *  - rejected → moderador recusou (com motivo); os BYTES são purgados na hora, a
 *               linha fica só para o membro ver o motivo e reenviar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_gallery_photos', function (Blueprint $table) {
            $table->id();

            // Dono da foto. cascadeOnDelete cobre só a remoção REAL da linha do
            // usuário; o Hard Delete LGPD (soft-delete/anonimização — item 11 do
            // CLAUDE.md) NÃO dispara o cascade, então o DeletionService varre a
            // galeria explicitamente (purgeGalleryPhotos + collectFilePaths).
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Layout no disco privado `local` (o mesmo do avatar do membro). NUNCA
            // sai em JSON ($hidden no model); a foto é servida pela rota assinada.
            $table->string('path')->default('');

            // Token OPACO de serving (48 chars aleatórios). A URL assinada é
            // chaveada AQUI, nunca no id/user_id — não vaza identificador
            // enumerável nem o member_id que o FanAlias esconde. UNIQUE.
            $table->string('token', 64)->unique();

            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');

            // A foto principal: vira o thumbnail do card do catálogo. UMA por
            // membro (garantido no MemberGalleryService, não por constraint — o
            // parcial-unique não é portável entre os bancos da suíte).
            $table->boolean('is_primary')->default(false);

            // SHA-256 dos bytes JÁ sanitizados (prova/dedup, como Story/Content).
            // $hidden no model.
            $table->string('content_hash', 64)->nullable();

            // Autoria da moderação: gravadas por forceFill no serviço, nunca por
            // mass assignment (mesma disciplina da intro de voz).
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();

            // Motivo da recusa, mostrado ao membro para reenviar.
            $table->string('reject_reason')->nullable();

            $table->timestamps();

            // A fila de moderação lista pendentes na ordem de chegada.
            $table->index(['status', 'id']);
            // A galeria do membro e a contagem de slots (pending+approved).
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_gallery_photos');
    }
};
