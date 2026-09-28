<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Timer da mensagem efêmera (roadmap social, Onda 3 — evolução do §2.2). Troca o
 * modelo "ver-uma-vez" (derivado de `read_at`) pelo "revelar → conta X seg → some"
 * (estilo Snapchat), agora que o servidor cresceu (CX33, 4 vCPU / 8 GB).
 *
 * `revealed_at` marca QUANDO o DESTINATÁRIO tocou para revelar a mensagem. É a fonte
 * única do consumo: **revelar = consumir**. Uma vez preenchido (imutável), a mensagem
 * está gasta e some da EXIBIÇÃO nas duas pontas em qualquer load seguinte — sem job
 * nem relógio no servidor. A contagem de X seg é client-side (só exibição); o corpo
 * FICA no banco para a moderação, como o resto do efêmero. Default null (não revelada).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->timestamp('revealed_at')->nullable()->after('ephemeral');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('revealed_at');
        });
    }
};
