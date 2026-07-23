<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lista negra antifraude. Guarda apenas DERIVADOS unidirecionais (HMAC do CPF e
 * do número do documento, chaveados pela APP_KEY — ver App\Support\CpfHash /
 * DocumentHash), nunca a PII crua: o objetivo é detectar recadastro de quem foi
 * banido, não reter documento. A entrada sobrevive ao encerramento LGPD da conta
 * porque o valor útil está nos HASHES, não na FK: o DeletionService faz
 * SOFT-delete de `users` (a linha não some) e apaga a PII de origem
 * (purgeKycRecords / scrubAgeVerification) sem tocar aqui. `banned_user_id` é
 * `nullOnDelete` por higiene de referência caso um dia haja DELETE físico — não
 * é o mecanismo que preserva a entrada.
 *
 * Únicos separados em cada hash: um CPF (ou documento) já listado não gera
 * segunda linha — a dedupe é por hash, e NULL múltiplo é permitido pelo MySQL
 * num índice único (contas sem KYC entram só com cpf_hash, document_hash null).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fraud_blacklist', function (Blueprint $table) {
            $table->id();
            $table->string('cpf_hash', 64)->nullable()->unique();
            $table->string('document_hash', 64)->nullable()->unique();
            $table->unsignedBigInteger('banned_user_id')->nullable();
            $table->unsignedBigInteger('banned_by')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->foreign('banned_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('banned_by')->references('id')->on('users')->nullOnDelete();
            $table->index('banned_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fraud_blacklist');
    }
};
