<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Localização opt-in da performer (Sprint 9).
 *
 * Duas colunas com destinos MUITO diferentes, e a diferença é a feature:
 *
 *  - `state` é PÚBLICO. Sai no catálogo, no card e no perfil. É grosso de
 *    propósito — 27 unidades, a menor delas com centenas de milhares de
 *    habitantes — então dizer "SP" não estreita ninguém a um lugar.
 *  - `city` é INTERNO. Não sai em resource nenhum, prop nenhuma, API nenhuma.
 *    Existe para uso futuro (matching por região, relatório agregado) e é
 *    guardado porque a performer o digitou, não porque alguém vá exibi-lo.
 *
 * **Nunca guardamos coordenadas.** Não há `lat`/`lng` aqui e não deve haver:
 * um par de floats é um ponto no mapa, e o produto inteiro depende de a
 * performer não ser localizável. Pelo mesmo motivo a tela NÃO usa a API de
 * geolocalização do navegador — os dois campos são digitados por ela.
 *
 * Tudo opcional: a performer pode salvar o perfil sem preencher nada, e pode
 * limpar depois. Opt-in significa que o estado ausente é o padrão, não uma
 * pendência a cobrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performer_profiles', function (Blueprint $table) {
            // Guarda a UF ('SP'), não o nome por extenso: é o mesmo padrão de
            // `languages`/`drinks`/`tier` — o banco fica com o slug estável e a
            // tela traduz. O tamanho segue a spec do PO; a UF usa 2.
            $table->string('state', 100)->nullable()->after('looking_for');
            $table->string('city', 100)->nullable()->after('state');

            // O filtro do catálogo consulta por `state` junto com o recorte de
            // publicCatalog(). Sem índice, cada filtro por UF varre a tabela.
            $table->index('state');
        });
    }

    public function down(): void
    {
        Schema::table('performer_profiles', function (Blueprint $table) {
            $table->dropIndex(['state']);
            $table->dropColumn(['state', 'city']);
        });
    }
};
