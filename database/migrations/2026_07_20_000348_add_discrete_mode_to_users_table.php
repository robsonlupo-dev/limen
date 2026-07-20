<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Preferência do membro: some da lista de seguidores das performers
            // que ele segue. Perk de Black/Founders Circle — a elegibilidade é
            // conferida no toggle, não aqui, para que quem já ativou não seja
            // exposto de volta se a assinatura lapsar.
            $table->boolean('discrete_mode')->default(false)->after('preferred_world');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('discrete_mode');
        });
    }
};
