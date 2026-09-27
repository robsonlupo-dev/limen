<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Status do dia da performer (roadmap social, Onda 1a). Bolha curta sobre o
     * avatar ("aceitando chamadas até 23h", "live às 22h"), com contagem regressiva
     * opcional. UM status ativo por performer (UNIQUE) — set = upsert.
     *
     * Expira na LEITURA (como story/foto efêmera): `expires_at` no passado = não
     * aparece; um purge só faz faxina de linha. O `body` é texto PÚBLICO da
     * performer, validado pelos mesmos filtros anti-contato da bio (SafeProfileText).
     */
    public function up(): void
    {
        Schema::create('performer_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('performer_profile_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('body', 140);
            // Contagem regressiva opcional: alvo + rótulo curto ("Live", "Chamadas").
            $table->timestamp('countdown_at')->nullable();
            $table->string('countdown_label', 40)->nullable();
            // Auto-some: default now()+config horas, gravado no serviço.
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performer_statuses');
    }
};
