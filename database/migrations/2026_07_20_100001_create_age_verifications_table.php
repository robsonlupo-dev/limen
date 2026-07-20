<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registro de maioridade do MEMBRO no cadastro (ECA Digital).
     *
     * Tabela separada de `identity_verifications` de propósito: aquela é o KYC
     * da performer, guarda documento/selfie e exige revisão humana ou provedor
     * (Didit). Esta é o controle leve do membro — nenhuma coluna de documento,
     * nada para revisar.
     *
     * Não existe coluna de CPF: só o HMAC (ver App\Support\CpfHash). O teste
     * `users table não tem coluna cpf` guarda essa invariante.
     */
    public function up(): void
    {
        Schema::create('age_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // 'cpf_dob' = CPF estruturalmente válido + data de nascimento
            // declarada com 18+. NÃO é consulta a base oficial: o método fica
            // gravado justamente para que um registro futuro ('serpro') seja
            // distinguível deste numa auditoria, em vez de os dois virarem um
            // "verificado" indistinto.
            $table->string('method', 32);

            // sha256 hex. Nullable porque métodos futuros podem não passar por
            // CPF (documento, liveness).
            $table->char('cpf_hmac', 64)->nullable();

            $table->timestamp('verified_at');
            $table->timestamps();

            // Índice, não unique: detecta CPF repetido, mas quem decide se isso
            // bloqueia o cadastro é o PO — hoje não bloqueia.
            $table->index('cpf_hmac');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('age_verifications');
    }
};
