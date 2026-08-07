<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preferências de som de notificação do usuário (Sprint 16). JSON por-usuário
 * com os toggles de som (message/tip/live). Nullable e sem default no banco
 * (MySQL não aceita default literal em coluna JSON): NULL ≡ "nunca escolheu",
 * e o modelo (User::notificationSoundPreferences) resolve isso como todos ON —
 * o "{} default" da spec vale por construção, na leitura, não no schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('notification_preferences')->nullable()->after('interests_opt_out');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notification_preferences');
        });
    }
};
