<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anti-CSAM MVP (Sprint 16) — bloqueador de go-live da auditoria. Hash perceptual
 * (phash) de TODA imagem no upload, conferido contra uma lista local de hashes
 * conhecidos. A lista sobe VAZIA por ora — a real vem de parceria com NCMEC/IWF
 * (requer CNPJ), importada por `csam:import-hashes`.
 *
 * Princípio nº 1 (segurança e idade primeiro): prevenção de conteúdo ilegal é
 * fundação. Match → bloqueia o upload, loga CRITICAL, sinaliza a conta.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Lista de hashes conhecidos (semente vazia). `hash` único: o mesmo hash de
        // duas fontes é uma linha só (a fonte da primeira importação fica).
        Schema::create('csam_hashes', function (Blueprint $table) {
            $table->id();
            $table->string('hash')->unique();
            $table->string('source')->default('manual'); // ncmec / iwf / manual / ...
            $table->timestamp('added_at')->useCurrent();
            $table->timestamps();
        });

        // Registro de CADA verificação (trilha). `matched` = bateu na lista;
        // `verified` = o serviço de hash estava disponível (false = fail-open, não
        // verificado). context = o que foi escaneado (avatar/cover/content/...).
        Schema::create('content_hash_checks', function (Blueprint $table) {
            $table->id();
            $table->string('context'); // avatar | cover | content | ...
            // Só peças de conteúdo permanente têm id; avatar/capa não → nullable,
            // sem FK (não amarra a uma tabela; a linha sobrevive à peça).
            $table->unsignedBigInteger('content_id')->nullable();
            // Quem enviou — para a sinalização da conta no match. Nullable + set null
            // para a trilha sobreviver à exclusão da conta.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('hash')->nullable(); // null quando não verificado
            $table->boolean('matched')->default(false);
            $table->boolean('verified')->default(true); // false = serviço indisponível (fail-open)
            $table->timestamp('checked_at')->useCurrent();
            $table->timestamps();

            $table->index('matched');
            $table->index('hash');
        });

        // Sinal de conta marcada para review imediato por match de CSAM. Fora do
        // $fillable (autoridade do servidor), como `status`.
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('csam_flagged_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('csam_flagged_at');
        });

        Schema::dropIfExists('content_hash_checks');
        Schema::dropIfExists('csam_hashes');
    }
};
