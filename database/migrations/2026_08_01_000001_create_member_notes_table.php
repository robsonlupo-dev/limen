<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notas privadas da performer sobre um membro (Sprint 11).
 *
 * Inspirada nas "Notas dos membros" do Seeking: a performer anota observações
 * sobre um FanAlias para lembrar detalhes de conversas e preferências. É
 * anotação PRIVADA — o membro NUNCA vê. Ver App\Services\MemberNoteService.
 *
 * Uma nota por par (performer_profile_id, user_id): o UNIQUE é o que torna o
 * upsert seguro contra duplo-submit, do mesmo modo que `favorites` — ver
 * MemberNoteService::upsert(), que trata a corrida com lock + catch do UNIQUE.
 *
 * `content` guarda o texto CIFRADO (cast `encrypted` sobre a APP_KEY): é opinião
 * pessoal sobre alguém, PII sensível que não pode ficar legível no banco. O
 * blob do Crypt cresce ~1.3x sobre o claro e leva IV + MAC, então `text`
 * (64 KB) e não `string` — cabe folgado o teto de 5.000 caracteres do Form
 * Request.
 *
 * `updated_at` EXISTE, ao contrário de `favorites`: a nota é editada no lugar
 * (upsert), não é DELETE + INSERT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_notes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('performer_profile_id')->constrained('performer_profiles')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('content');
            $table->timestamps();

            // Uma nota por par. Também é o índice que cobre o `where
            // performer_profile_id` da leitura e o lookup do upsert.
            $table->unique(['performer_profile_id', 'user_id']);

            // A lista da performer sai ordenada por "editada mais recentemente".
            // O UNIQUE já cobre o `where performer_profile_id`, mas não a
            // ordenação por data dentro dele.
            $table->index(['performer_profile_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_notes');
    }
};
