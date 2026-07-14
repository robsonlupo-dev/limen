<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('waitlist_entries', function (Blueprint $table) {
            // Two-step signup splits the "world" capture by role:
            //   • performer → the single `world` they represent (existing column,
            //     enforced required at the request layer only when role=performer).
            //   • member    → `world_preferences`: the (private, multiple) worlds
            //     they want to receive Interesse Controlado from. Nullable JSON.
            $table->json('world_preferences')->nullable()->after('world');

            // Only meaningful for performers; required by the request when
            // world=casais ("performer = dois"), otherwise stays null.
            $table->string('performer_kind', 10)->nullable()->after('world_preferences');
        });
    }

    public function down(): void
    {
        Schema::table('waitlist_entries', function (Blueprint $table) {
            $table->dropColumn(['world_preferences', 'performer_kind']);
        });
    }
};
