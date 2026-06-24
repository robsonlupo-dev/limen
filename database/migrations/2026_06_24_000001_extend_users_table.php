<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['consumer', 'performer', 'admin'])->default('consumer')->after('password');
            $table->string('phone')->nullable()->after('role');
            $table->timestamp('phone_verified_at')->nullable()->after('phone');
            $table->date('birthdate')->nullable()->after('phone_verified_at');
            $table->timestamp('age_verified_at')->nullable()->after('birthdate');
            $table->timestamp('lgpd_consent_at')->nullable()->after('age_verified_at');
            $table->string('terms_version')->nullable()->after('lgpd_consent_at');
            $table->enum('status', ['pending', 'active', 'suspended', 'banned'])->default('pending')->after('terms_version');
            $table->timestamp('last_login_at')->nullable()->after('status');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn([
                'role', 'phone', 'phone_verified_at', 'birthdate',
                'age_verified_at', 'lgpd_consent_at', 'terms_version',
                'status', 'last_login_at',
            ]);
        });
    }
};
