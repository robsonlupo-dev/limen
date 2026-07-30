<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hash do conteúdo enviado — a prova que sobrevive ao arquivo.
     *
     * ── Por que a foto passou a precisar dele ───────────────────────────────
     * O Stories ganhou esta coluna (§ 2.4, parte 1) para que o encerramento de
     * conta pudesse **preservar evidência SEM preservar conteúdo**. A foto
     * efêmera tinha o mesmo furo pela porta mais poderosa de todas: o
     * `DeletionService` apagava a foto denunciada em `forceDelete()` junto com o
     * resto do titular, então encerrar a conta era o botão de destruir a prova
     * contra si — o mesmo botão que o revoke e o GC já recusam a dar.
     *
     * Sem hash não havia como fechar isso de forma decente. Preservar a LINHA
     * sem os bytes deixaria `user_id`, `size_bytes` e `created_at` — ou seja, a
     * retenção de metadado que a feature existe para NÃO ter, e nada sobre o
     * conteúdo. Preservar os BYTES manteria o rosto de quem acabou de exercer o
     * direito de exclusão. O hash é a terceira saída, e é a mesma que o story já
     * usa: a plataforma sustenta "este arquivo EXATO foi enviado em T por X" se
     * ele reaparecer, e pode casar contra listas de hash conhecidas — que é o que
     * de fato bloqueia o re-upload.
     *
     * ── SHA-256 do arquivo PROCESSADO, não do upload ────────────────────────
     * Calculado sobre os bytes que a plataforma gravou, depois do re-encode que
     * mata EXIF e polyglot, e **antes do `Crypt`**: o ciphertext muda a cada
     * gravação (IV aleatório), então hashear o que está no disco daria um valor
     * diferente para o mesmo conteúdo — inútil para matching. É a única
     * diferença de mecânica em relação ao story, cujo disco não é cifrado.
     *
     * `char(64)` porque SHA-256 em hex tem comprimento fixo; nullable porque as
     * fotos anteriores a esta migration não têm como ser preenchidas
     * retroativamente — os bytes existem, mas cifrados, e decifrar toda a tabela
     * num `up()` seria pagar o volume inteiro por um backfill que o TTL resolve
     * sozinho em no máximo 7 dias. Linha antiga com hash nulo é histórico, não
     * erro; a preservação por denúncia registra o que tem.
     *
     * ── Índice ─────────────────────────────────────────────────────────────
     * Mesmo uso do story: "este hash já apareceu?", busca por valor sobre a
     * tabela toda. Simples e não único — o mesmo arquivo enviado duas vezes é o
     * caso que se quer VER, não recusar no banco.
     */
    public function up(): void
    {
        Schema::table('member_photos', function (Blueprint $table) {
            $table->char('content_hash', 64)->nullable()->after('path_encrypted');

            $table->index('content_hash');
        });
    }

    public function down(): void
    {
        Schema::table('member_photos', function (Blueprint $table) {
            $table->dropIndex(['content_hash']);
            $table->dropColumn('content_hash');
        });
    }
};
