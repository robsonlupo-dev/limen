<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('token_packages', function (Blueprint $table) {
            $table->unsignedInteger('bonus')->default(0)->after('tokens');
        });
    }

    public function down(): void
    {
        Schema::table('token_packages', function (Blueprint $table) {
            $table->dropColumn('bonus');
        });
    }
};
