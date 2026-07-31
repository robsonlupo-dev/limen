<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Login passwordless por código OTP de 6 dígitos por e-mail — Sprint 11.
     *
     * Convive com o login por senha (não o substitui). A promessa central é a
     * decisão do PO: sem senha na jogada, um dump de banco não entrega login a
     * ninguém — e o código aqui é EFÊMERO (TTL de 5 min), de uso único, e
     * invalidado quando um novo é gerado.
     *
     * ── `code` é a credencial, e por isso o modelo o esconde (ver OtpCode) ──────
     * Fica em claro na coluna (string(6)), como o desenho do PO pede — é o mesmo
     * padrão do Seeking. A contenção do risco de "dump durante os 5 min" é o TTL
     * curto + os 5 palpites por código + os 3 códigos/hora, não a cifra: um código
     * de 6 dígitos hasheado ainda é força-bruta trivial offline, então a cifra
     * compraria pouco e o custo real está no tempo de vida. A comparação usa
     * `hash_equals` (tempo constante) mesmo assim.
     *
     * ── Só `created_at`, sem `updated_at` ──────────────────────────────────────
     * A linha nasce e é consumida; `used_at` e `attempts` são estado explícito de
     * domínio, não o carimbo genérico do ORM. O model roda com `$timestamps =
     * false` e o DB preenche `created_at` por default.
     */
    public function up(): void
    {
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // 6 dígitos numéricos, com zeros à esquerda preservados (string, não
            // int) — 000123 é um código válido.
            $table->string('code', 6);
            $table->timestamp('expires_at');
            // Uso único: marcado no acerto. Enquanto null, o código é consumível.
            $table->timestamp('used_at')->nullable();
            // Palpites errados. Aos MAX_ATTEMPTS o código é queimado (ver OtpCode).
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('created_at')->useCurrent();

            // Toda leitura é "o código vivo mais recente deste user" — a busca é
            // por user_id, ordenada por id desc. O índice cobre o where.
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_codes');
    }
};
