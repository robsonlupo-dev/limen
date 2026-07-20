<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('follows', function (Blueprint $table) {
            // Cópia do users.discrete_mode no momento do follow. Denormalizada de
            // propósito: a lista de seguidores filtra por ela sem join, e um dia
            // o modo poderá ser por performer. A leitura confere as DUAS (ver
            // FollowersController) — se divergirem, prevalece o mais discreto.
            $table->boolean('discrete_mode')->default(false)->after('performer_profile_id');

            // A lista da performer sempre filtra por (perfil, discrete_mode).
            $table->index(['performer_profile_id', 'discrete_mode']);
        });
    }

    public function down(): void
    {
        Schema::table('follows', function (Blueprint $table) {
            $table->dropIndex(['performer_profile_id', 'discrete_mode']);
            $table->dropColumn('discrete_mode');
        });
    }
};
