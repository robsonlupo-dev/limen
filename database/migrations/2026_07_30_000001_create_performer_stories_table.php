<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stories da Performer — Sprint 9C. Ver docs/SECURITY_ISSUES.md § 2.1 a § 2.10.
     *
     * ── `media_path` NÃO é cifrado, e é decisão, não esquecimento (§ 2.5) ────
     * A Foto Efêmera do membro cifra (`path_encrypted` + Crypt no disco) porque é
     * 1-para-POUCOS, pequena, e enviada sob expectativa de privacidade. Story é o
     * oposto em todos os eixos: 1-para-MUITOS, maior, e conteúdo que a performer
     * ESCOLHEU publicar. `Crypt::encryptString` carrega o arquivo inteiro na RAM
     * e não permite Range nem seek — N viewers concorrentes × tamanho do arquivo
     * é DoS auto-infligido numa feature desenhada para ter audiência simultânea.
     *
     * O que substitui a cifra é a camada de serving: disco `performer_stories`
     * com `serve => false` e autorização REAVALIADA a cada request (§ 2.3) — sem
     * URL assinada, que é bearer token colável no WhatsApp e evapora o paywall do
     * Nível 3. O risco que sobra é "quem tem SSH lê", que já é verdade para os
     * avatares. **Não é a mesma estratégia da foto do membro; não unifique.**
     *
     * ── Onde o TTL de 24h é aplicado (§ 2.8, mesma inversão do § 1.3) ───────
     * `expires_at` é conferido na LEITURA (`PerformerStory::isExpired()` e o
     * escopo `active()`), não pelo job: se o corte de acesso dependesse do GC, um
     * job parado não custaria disco — custaria a promessa do produto. O comando
     * `stories:purge` é garbage collection.
     *
     * ── `deleted_at` aqui é REPOUSO, ao contrário de `member_photos` ────────
     * A foto do membro termina em HARD delete porque a linha morta guardaria "o
     * membro X mandou 43 fotos, nestes horários" — retenção de PII de quem enviou.
     * Aqui a linha é da própria performer sobre o próprio conteúdo publicado, e é
     * ela que dá lastro ao § 2.4: "este story existiu, publicado em T por X".
     * Quem carrega correlação membro→performer é `story_views`, e essa sim morre
     * junto com os bytes.
     *
     * ── `visibility_level` é enum de BANCO de propósito ─────────────────────
     * Três níveis fechados (§ 2.2): `public`, `subscribers`, `exclusive`. O
     * contador do `exclusive` não existe — nem zero, `null` (decisão nº 3 do PO).
     * Nível novo exige migration, e é bom que exija: cada nível é uma decisão de
     * quem pode ver E de que sinal de audiência a performer recebe de volta.
     */
    public function up(): void
    {
        Schema::create('performer_stories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('performer_profile_id')->constrained('performer_profiles')->cascadeOnDelete();
            // TEXT e não VARCHAR: é caminho gerado pelo Store, não entrada de
            // usuário. Sem cifra — ver o docblock.
            $table->text('media_path');
            // Literal, e não a constante do model: migration é o registro do que
            // o banco aceitou naquele dia. Se o model mudar a lista, o histórico
            // aqui continua contando a verdade, e a mudança vira migration nova.
            $table->enum('visibility_level', ['public', 'subscribers', 'exclusive']);
            $table->timestamp('expires_at');
            $table->softDeletes();
            $table->timestamps();

            // As duas leituras quentes. O feed/indicador do catálogo (PR 3) pede
            // "os stories vivos desta performer"; o GC varre por prazo sobre
            // todos os perfis, daí o índice solto.
            $table->index(['performer_profile_id', 'expires_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performer_stories');
    }
};
