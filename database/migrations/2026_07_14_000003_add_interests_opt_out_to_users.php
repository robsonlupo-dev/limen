<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Member preference: when true, performers cannot send interest to
            // this member (and get no signal that the opt-out exists).
            $table->boolean('interests_opt_out')->default(false)->after('preferred_world');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('interests_opt_out');
        });
    }
};
