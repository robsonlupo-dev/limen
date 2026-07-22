<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performer_profiles', function (Blueprint $table) {
            $table->enum('tier', ['verificada', 'select', 'maison'])->nullable()->after('is_verified');
            $table->timestamp('tier_granted_at')->nullable()->after('tier');
            $table->unsignedBigInteger('tier_granted_by')->nullable()->after('tier_granted_at');

            $table->foreign('tier_granted_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('performer_profiles', function (Blueprint $table) {
            $table->dropForeign(['tier_granted_by']);
            $table->dropColumn(['tier', 'tier_granted_at', 'tier_granted_by']);
        });
    }
};
