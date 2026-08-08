<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vídeo como conteúdo permanente (Sprint 16). O upload de vídeo é higienizado por
 * ffmpeg (re-encode H.264/AAC MP4 + strip de metadados) num job assíncrono; até o
 * job terminar a peça fica `status=processing` e NÃO é servível. Colunas novas:
 *  - kind ganha 'video' (era só 'photo').
 *  - status: processing → ready → failed (foto já nasce ready).
 *  - thumbnail_path: poster gerado do frame do 1º segundo (640px JPEG).
 *  - duration_seconds: gravado no processamento; usado no gate de duração.
 *  - failure_reason: mensagem para a performer quando o job falha.
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL: MODIFY para acrescentar 'video' ao enum de kind (INSTANT).
        DB::statement("ALTER TABLE performer_content MODIFY COLUMN kind ENUM('photo','video') NOT NULL DEFAULT 'photo'");

        Schema::table('performer_content', function (Blueprint $table) {
            // Foto existente nasce 'ready' — nenhum backfill necessário.
            $table->enum('status', ['ready', 'processing', 'failed'])->default('ready')->after('kind');
            $table->text('thumbnail_path')->nullable()->after('path');
            $table->unsignedInteger('duration_seconds')->nullable()->after('thumbnail_path');
            $table->string('failure_reason')->nullable()->after('duration_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('performer_content', function (Blueprint $table) {
            $table->dropColumn(['status', 'thumbnail_path', 'duration_seconds', 'failure_reason']);
        });

        DB::statement("ALTER TABLE performer_content MODIFY COLUMN kind ENUM('photo') NOT NULL DEFAULT 'photo'");
    }
};
