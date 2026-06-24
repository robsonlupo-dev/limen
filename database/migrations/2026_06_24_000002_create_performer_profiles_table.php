<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performer_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('stage_name');
            $table->text('bio')->nullable();
            $table->enum('category', ['mulheres', 'homens', 'casais', 'trans', 'gls', 'swing'])->default('mulheres');
            $table->json('work_modes')->nullable();
            $table->enum('level', ['iniciante', 'estrela', 'premium', 'vip'])->default('iniciante');
            $table->unsignedTinyInteger('split_pct')->default(65);
            $table->unsignedInteger('rate_public')->default(60);
            $table->unsignedInteger('rate_private')->default(120);
            $table->unsignedInteger('rate_camera')->default(20);
            $table->boolean('is_live')->default(false);
            $table->boolean('is_verified')->default(false);
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->unsignedInteger('followers_count')->default(0);
            $table->string('avatar_path')->nullable();
            $table->string('cover_path')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performer_profiles');
    }
};
