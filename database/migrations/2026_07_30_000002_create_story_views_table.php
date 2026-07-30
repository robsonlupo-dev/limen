<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quem viu qual story. Ver docs/SECURITY_ISSUES.md § 2.1, § 2.6 e § 2.7.
     *
     * ── Esta tabela NUNCA vira lista na tela (§ 2.1) ────────────────────────
     * "Quem viu meu story" é a armadilha arquitetural nº 1 da feature: seria uma
     * lista membro→performer SEM piso nenhum — exatamente o buraco que o painel
     * de visitantes existiu para tapar, e agravado, porque um membro pode ver
     * story sem nunca ter seguido (o piso é contado sobre follows).
     *
     * A decisão do PO (24/07/2026, nº 1) é **contador em faixa de membros
     * únicos e nada mais**. A tabela existe para o `DISTINCT`, não para exibir
     * linha. Quem der uma lista a partir daqui derruba o Piso de Anonimato
     * inteiro.
     *
     * ── O índice único É a regra de "cada membro conta 1 vez" ───────────────
     * `DISTINCT member_id ANTES da faixa`, nunca depois (decisão nº 1): faixar o
     * total de ABERTURAS devolveria comportamento do membro em vez de audiência,
     * e um membro que reabre 5 vezes empurraria sozinho a faixa de `Menos de 5`
     * para `5+`. O único no par (story, membro) faz o banco garantir isso —
     * reabrir não gera linha nova.
     *
     * ── Ausência de linha é o produto (§ 2.7) ───────────────────────────────
     * View de membro com Ghost Mode ou Modo Discreto **não é gravada**, e não
     * existe coluna `hidden`: guardar a view marcada como oculta deixaria o dado
     * a um JOIN de distância, e um bug de query viraria o vazamento exato que o
     * perk vende. É o item 9 do CLAUDE.md aplicado à terceira rota. O guard vive
     * no `PerformerStoryService`, não no controller.
     *
     * ── `viewed_at` não é exposto ───────────────────────────────────────────
     * Existe para retenção e para o desempate do `DISTINCT`; não sai em resposta
     * nenhuma. Não há tela onde ele possa virar relógio (não há lista de
     * viewers), então também não há faixa a derivar — mas a regra do item 12 do
     * CLAUDE.md continua valendo para qualquer superfície futura.
     *
     * Sem `timestamps()`: `viewed_at` é o instante do fato, e um `created_at`
     * ao lado seria uma segunda cópia da mesma informação.
     *
     * ── Retenção (§ 2.6) ────────────────────────────────────────────────────
     * Estruturalmente idêntica a `profile_visits`: mapa de interesses
     * membro→performer. As views morrem com o story (24h), em DELETE de verdade
     * no `stories:purge`. Hard delete nos dois sentidos do `DeletionService` —
     * membro que encerra e performer que encerra — é PR 5; os `cascadeOnDelete`
     * abaixo **não** cobrem esse caminho, porque os dois lados são soft-delete
     * (item 11 do CLAUDE.md, verbatim). Não escreva código contando com a FK.
     */
    public function up(): void
    {
        Schema::create('story_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('performer_story_id')->constrained('performer_stories')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('viewed_at');

            // Cada membro conta 1 vez, garantido pelo banco — ver o docblock.
            // Serve também como índice do contador: a contagem por story entra
            // pelo prefixo da chave.
            $table->unique(['performer_story_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_views');
    }
};
