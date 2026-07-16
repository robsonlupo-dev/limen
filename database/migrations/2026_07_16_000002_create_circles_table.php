<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reference table for the 5 Círculos (CIRCLES_SYSTEM_V4.md). Values are seeded
 * here so a fresh migrate (incl. RefreshDatabase in tests) always has them.
 * Prices/tokens/discounts are business config — edited via a new migration,
 * never by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('circles', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->unsignedInteger('price_cents');
            $table->unsignedInteger('monthly_tokens');
            $table->unsignedTinyInteger('discount_pct');
            $table->unsignedInteger('seat_limit')->nullable(); // null = ilimitado
            $table->boolean('invite_only')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        $now = now();
        DB::table('circles')->insert([
            ['slug' => 'explorador', 'name' => 'Círculo Explorador', 'price_cents' => 8990, 'monthly_tokens' => 75, 'discount_pct' => 10, 'seat_limit' => null, 'invite_only' => false, 'sort_order' => 1, 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'insider', 'name' => 'Círculo Insider', 'price_cents' => 18990, 'monthly_tokens' => 200, 'discount_pct' => 20, 'seat_limit' => null, 'invite_only' => false, 'sort_order' => 2, 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'prestige', 'name' => 'Círculo Prestige', 'price_cents' => 38990, 'monthly_tokens' => 500, 'discount_pct' => 30, 'seat_limit' => null, 'invite_only' => false, 'sort_order' => 3, 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'black', 'name' => 'Círculo Black', 'price_cents' => 74990, 'monthly_tokens' => 1200, 'discount_pct' => 40, 'seat_limit' => 500, 'invite_only' => false, 'sort_order' => 4, 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'founders_circle', 'name' => 'Founders Circle', 'price_cents' => 149000, 'monthly_tokens' => 2500, 'discount_pct' => 50, 'seat_limit' => 100, 'invite_only' => true, 'sort_order' => 5, 'active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('circles');
    }
};
