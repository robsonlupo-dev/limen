<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hash do conteúdo publicado — a prova que sobrevive ao arquivo.
     * Ver docs/SECURITY_ISSUES.md § 2.4, parte 1.
     *
     * ── Por que existe ──────────────────────────────────────────────────────
     * O achado mais sério da feature é que o auto-delete de 24h é destruição de
     * prova embutida no produto: o `DeletionService` preserva `reports` de
     * propósito — "apagá-la porque o denunciado pediu exclusão daria ao infrator
     * um botão para destruir a prova contra si" —, e Stories daria esse botão a
     * toda performer, automático, a cada 24 horas.
     *
     * O hash é a resposta da decisão: **preserva evidência SEM preservar
     * conteúdo**, que era a pergunta original. Com ele a plataforma sustenta
     * "este arquivo EXATO foi publicado em T por X" se o arquivo reaparecer, e
     * pode casar publicações futuras contra listas de hash conhecidas — que é o
     * que de fato permite bloquear o re-upload. Guardar os bytes "para revisar"
     * NÃO é a solução para CSAM: isso exige fluxo definido de takedown e
     * notificação à autoridade.
     *
     * ── SHA-256 do arquivo PROCESSADO, não do upload ────────────────────────
     * O hash é calculado sobre os bytes que a plataforma gravou, depois do
     * re-encode que mata EXIF e polyglot. É o único conjunto de bytes que existe
     * do nosso lado — hashear o upload original produziria um valor que não
     * corresponde a arquivo nenhum no disco, e que qualquer troca de metadado
     * mudaria.
     *
     * `char(64)` porque SHA-256 em hex tem comprimento fixo; nullable porque as
     * linhas anteriores a esta migration não têm como ser preenchidas
     * retroativamente (os bytes de um story vencido já não existem). Linha antiga
     * com hash nulo é histórico, não erro.
     *
     * ── O hash perceptual do § 2.4 NÃO entra aqui ───────────────────────────
     * A decisão pede "SHA-256 + perceptual". O criptográfico casa o arquivo
     * IDÊNTICO; o perceptual casaria o recorte/recompressão, e é o que serve
     * contra re-upload evasivo. Ele depende de uma biblioteca que o projeto não
     * tem (pHash/aHash) e de decidir o limiar de similaridade — trabalho com
     * risco de falso positivo próprio, que não cabe junto. Fica como coluna nova
     * quando entrar; esta não muda.
     *
     * ── Índice ─────────────────────────────────────────────────────────────
     * O uso previsto é "este hash já apareceu?" — busca por valor, sobre toda a
     * tabela. Índice simples, não único: o mesmo arquivo publicado duas vezes
     * (pela mesma performer ou por duas) é exatamente o caso que se quer VER, não
     * recusar no banco.
     */
    public function up(): void
    {
        Schema::table('performer_stories', function (Blueprint $table) {
            $table->char('content_hash', 64)->nullable()->after('media_path');

            $table->index('content_hash');
        });
    }

    public function down(): void
    {
        Schema::table('performer_stories', function (Blueprint $table) {
            $table->dropIndex(['content_hash']);
            $table->dropColumn('content_hash');
        });
    }
};
