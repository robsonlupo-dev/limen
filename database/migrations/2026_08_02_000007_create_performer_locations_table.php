<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Múltiplas localizações da performer (Sprint 13) — evolui a localização única
 * do Sprint 9A (as colunas `state`/`city` de `performer_profiles`).
 *
 * Decisão do PO (Sprint 9A, R2), que continua valendo item a item:
 *  - SEM GPS: os dois campos são digitados, não há lat/lng no schema;
 *  - só o ESTADO é público (catálogo, card, perfil, filtro);
 *  - `city` é dado INTERNO, guardado porque a performer o digitou, e não sai em
 *    resource/prop/API nenhum;
 *  - localização nunca é exibida junto de `is_live` (regra do card/perfil).
 *
 * Esta tabela é a FONTE DA VERDADE da lista. As colunas `state`/`city` de
 * `performer_profiles` ficam como CACHE da localização PRIMÁRIA (a escolha
 * sancionada pelo enunciado da tarefa: "mantém como cache da primária"),
 * mantidas em sincronia só pelo `PerformerLocationService` — nunca há dois
 * donos escrevendo. Guardar o cache mantém verde a superfície do Sprint 9A que
 * lê a coluna e serve de fallback para linha sem localização migrada, no mesmo
 * espírito do fallback `worlds`/`category`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performer_locations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('performer_profile_id')
                ->constrained('performer_profiles')
                ->cascadeOnDelete();

            // `city` é interno e livre (a lista de municípios do Brasil não cabe
            // numa constante) — o que a mantém privada é não sair em resource
            // nenhum, não uma restrição de tipo. `state` é a UF (2 chars),
            // validada contra PerformerProfile::STATES na escrita.
            $table->string('city', 100)->nullable();
            $table->string('state', 2);

            // Exatamente uma linha por perfil carrega is_primary=true — invariante
            // imposta pelo PerformerLocationService, não pelo banco (um índice
            // parcial "único onde is_primary" não é portável entre MySQL e
            // SQLite, e a regra "exatamente 1" não é expressável por UNIQUE de
            // qualquer modo). É a primária que alimenta o cache state/city.
            $table->boolean('is_primary')->default(false);

            // Ordem de exibição ("SP · RJ · MG"). Primária em position 0.
            $table->smallInteger('position')->unsigned()->default(0);

            $table->timestamps();

            // Uma performer não repete o MESMO par estado+cidade. Nota: em
            // MySQL/SQLite duas linhas com `city` NULL e mesmo `state` NÃO
            // colidem por este índice (NULL é distinto no UNIQUE), então o
            // Service também deduplica explicitamente — o índice é a rede, não a
            // única defesa. Ver PerformerLocationService::setLocations().
            $table->unique(['performer_profile_id', 'state', 'city']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performer_locations');
    }
};
