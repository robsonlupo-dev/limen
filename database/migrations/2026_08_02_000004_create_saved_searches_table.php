<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Buscas salvas do MEMBRO — Sprint 12.
     *
     * O membro salva combinações de filtros do catálogo ("Fitness SP") para
     * reaplicar depois. Decisão do PO (R3 do Sprint 9): quem salva filtros é o
     * MEMBRO, nunca a performer — a direção segura, a mesma do
     * `CatalogFilterRequest` (membro filtra performers, nunca o inverso).
     *
     * ── PRIVACIDADE ─────────────────────────────────────────────────────────
     * A linha é do membro e só dele: a performer NÃO tem, e não pode ganhar, uma
     * rota ou relação que leia esta tabela — mesma disciplina de `favorites`. Os
     * `filters` são slugs de tag, enums de bebida/fumo, faixa de altura, UF, etc.
     * (o allowlist de `CatalogFilterRequest`), mais o texto de busca que o
     * PRÓPRIO membro digitou: nada de PII de terceiro, e nada que volte para
     * qualquer superfície da performer.
     *
     * ── Hard Delete ─────────────────────────────────────────────────────────
     * `cascadeOnDelete` na FK é a rede para um DELETE físico do usuário, mas ele
     * NÃO dispara no encerramento de conta: `anonymizeUser()` só soft-deleta o
     * `users` (item 11 do CLAUDE.md). Por isso o `DeletionService` varre esta
     * tabela explicitamente (`purgeSavedSearches`) — sem essa varredura a busca
     * salva sobreviveria à conta, como aconteceria com `favorites`/`otp_codes`.
     *
     * `filters` é JSON porque o conjunto de facetas cresce (o Sprint 9 já
     * dobrou), e um schema por faceta viraria migration a cada nova. O teto de
     * 10 buscas por membro é regra de aplicação (SavedSearch::MAX_SAVED),
     * imposta sob lock no SavedSearchService — não cabe no schema.
     */
    public function up(): void
    {
        Schema::create('saved_searches', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Nome dado pelo membro (ex.: "Fitness SP"). Teto de 100 no banco E
            // no Form Request — a segunda porta que aparecer não passa pelo Request.
            $table->string('name', 100);
            // Os filtros serializados — o allowlist do catálogo. Só chaves
            // conhecidas são persistidas (Arr::only no service): payload extra é
            // descartado antes de gravar, então o JSON nunca vira blob arbitrário.
            $table->json('filters');
            $table->timestamps();

            // A leitura quente é "as buscas DESTE membro", e o cap conta por
            // membro sob lock — o índice de user_id (criado pela FK) cobre as duas.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_searches');
    }
};
