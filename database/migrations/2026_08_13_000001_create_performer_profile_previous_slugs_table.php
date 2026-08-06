<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Histórico de slugs vagos (UAT fix, fase 1). Trocar o nome artístico
     * regenera o slug (PerformerProfileService::update) e o antigo era
     * DESCARTADO — todo link vivo (card já renderizado, aba aberta, bookmark,
     * link compartilhado) passava a dar 404. Guardar o slug abandonado deixa a
     * show 301-redirecionar para o atual em vez de estourar 404.
     *
     * `slug` é UNIQUE global (como `performer_profiles.slug`): serve tanto para
     * desambiguar o redirect quanto para o generateSlug NÃO reciclar um slug que
     * ainda aponta para outra performer. O nome antigo continua fora de qualquer
     * URL NOVA — só quem já tinha o link é redirecionado —, então a disciplina de
     * "quem troca de identidade descarta o nome antigo" segue de pé para o
     * público que chega depois.
     */
    public function up(): void
    {
        Schema::create('performer_profile_previous_slugs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('performer_profile_id')
                ->constrained('performer_profiles')
                ->cascadeOnDelete();
            $table->string('slug')->unique();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performer_profile_previous_slugs');
    }
};
