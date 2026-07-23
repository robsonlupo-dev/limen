<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-worlds: a performer can belong to more than one world at once
 * (e.g. ['mulheres', 'trans']). `category` stays as the single primary world
 * — never removed — so every read path that still reads it keeps working while
 * data backfills. `worlds` is the new source of truth; null means "not yet
 * migrated" and callers fall back to [category] (PerformerProfile::activeWorlds).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performer_profiles', function (Blueprint $table) {
            $table->json('worlds')->nullable()->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('performer_profiles', function (Blueprint $table) {
            $table->dropColumn('worlds');
        });
    }
};
