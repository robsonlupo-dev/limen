<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Escalação de denúncia ao admin (feat/moderator-actions). O moderador não bane;
 * quando o caso pede ban, ele ESCALA — marca a denúncia com quem/quando, e ela
 * passa a alimentar a fila "Escalados ao admin". Ortogonal ao `status` (uma
 * denúncia pending pode estar escalada aguardando o admin).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->timestamp('escalated_at')->nullable()->after('reviewed_at');
            $table->foreignId('escalated_by')->nullable()->after('escalated_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('escalated_by');
            $table->dropColumn('escalated_at');
        });
    }
};
