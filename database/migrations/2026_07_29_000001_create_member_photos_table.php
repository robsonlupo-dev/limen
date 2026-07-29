<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Foto efêmera do membro — o arquivo que ele mostra para uma performer com
     * prazo de validade. Ver docs/SECURITY_ISSUES.md § 1.1 a § 1.11.
     *
     * ── O que esta tabela NÃO é ─────────────────────────────────────────────
     * Não é feature de privacidade. É **des-anonimização consentida** (§ 1.1):
     * o rosto é uma chave de join GLOBAL, e duas performers que receberam foto
     * do mesmo membro desfazem, comparando as imagens fora da plataforma, o
     * isolamento cross-perfil que o FanAlias existe para dar. O TTL protege o
     * arquivo — não a memória de quem viu, nem o print. A UI avisa isso no
     * envio, e nenhuma copy pode dizer "a performer não guarda sua foto".
     *
     * ── Onde o TTL é aplicado ───────────────────────────────────────────────
     * `expires_at` é conferido na LEITURA (MemberPhotoService), não pelo job
     * (§ 1.3): se o corte de acesso dependesse do GC, um job parado não custaria
     * disco — custaria privacidade. O comando `member-photos:purge` é garbage
     * collection, e o precedente é ChatAccess/PurgeExpiredChatAccess.
     *
     * ── `deleted_at` é estado INTERMEDIÁRIO, não repouso ────────────────────
     * A foto termina em HARD delete: `MemberPhotoService::destroy()` apaga os
     * bytes, confirma que saíram e só então larga a linha, de vez. A linha morta
     * não é neutra — guarda `user_id`, `size_bytes` e `created_at`, ou seja "o
     * membro X mandou 43 fotos, nestes horários" —, e guardá-la reporia em
     * metadado a retenção que a foto efêmera existe para não ter.
     *
     * `deleted_at` fica para o estado do MEIO: linha apagada com os bytes ainda
     * no disco. É o que a varredura `withTrashed()` do GC recolhe — sem a
     * coluna, esse arquivo sairia do alcance de qualquer rodada futura.
     *
     * A consequência a não esquecer é a do item 11 do CLAUDE.md, verbatim: as
     * FKs `cascadeOnDelete` de `member_photo_access` **não podem ser o
     * mecanismo** — no caminho do soft delete elas não disparam. Quem apaga o
     * acesso é código, sempre, nos dois caminhos.
     *
     * ── Colunas cifradas ────────────────────────────────────────────────────
     * `path_encrypted` e `original_filename` saem cifradas pela APP_KEY (cast
     * `encrypted` no model). O caminho carrega o id do titular e o nome original
     * costuma ser tão descritivo quanto a foto ("selfie-quarto.jpg"): um dump do
     * banco não deve entregar nem um nem outro. Por isso `original_filename` é
     * TEXT e não VARCHAR(255) — o ciphertext de um nome de 255 bytes passa
     * folgado de 255 caracteres, e em modo estrito o INSERT falharia.
     */
    public function up(): void
    {
        Schema::create('member_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('path_encrypted');
            $table->text('original_filename')->nullable();
            $table->unsignedInteger('size_bytes');
            $table->timestamp('expires_at');
            $table->softDeletes();
            $table->timestamps();

            // Cobre as duas leituras quentes e é a MESMA: o cap de 5 ativas no
            // submit (§ 1.6) e a lista do próprio membro contam por titular
            // dentro do prazo. O GC varre por `expires_at` sobre todos os
            // titulares, daí o índice solto.
            $table->index(['user_id', 'expires_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_photos');
    }
};
