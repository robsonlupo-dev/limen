<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waitlist_entries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            // 'performer' | 'member' — captured on the pre-launch landing page.
            $table->string('role', 20);
            // Optional "mundo" the person is interested in (mulheres/homens/...).
            $table->string('world', 20)->nullable();
            // Explicit 18+ confirmation captured at submission (principle 1:
            // idade primeiro). created_at doubles as the consent timestamp.
            $table->boolean('age_confirmed')->default(false);
            // Where the signup came from, for future campaign attribution.
            $table->string('source', 40)->default('landing');
            $table->timestamps();

            // One row per (email, role): re-submitting the same interest is
            // idempotent (updateOrCreate) instead of creating duplicates.
            $table->unique(['email', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist_entries');
    }
};
