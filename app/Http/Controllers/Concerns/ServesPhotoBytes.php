<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Response;

/**
 * Resposta binária de imagem privada — a mesma para todos os consumidores,
 * porque um segundo lugar montando estes cabeçalhos divergiria exatamente nos
 * que importam.
 *
 * Três hoje: a foto efêmera do lado do membro, a mesma do lado da performer e o
 * Story (Sprint 9C). O Story entra aqui e não num trait próprio porque o conjunto
 * de cabeçalhos é o mesmo pelas mesmas razões — sniff no servidor, `nosniff`,
 * `inline`, `no-store` —, e o `no-store` vale nele até com mais força: cache é
 * retenção, e um story de 24h guardado por um proxy sobreviveria ao TTL. O custo é
 * banda (todo viewer rebaixa o arquivo a cada request), e é custo aceito: o
 * conteúdo do Nível 3 num cache compartilhado seria paywall furado.
 */
trait ServesPhotoBytes
{
    /**
     * Tipos que aceitamos DEVOLVER. É allowlist e não adivinhação: o
     * `ImageProcessingService` sempre produz JPEG (o arquivo é gerado a partir
     * do bitmap), então qualquer outra coisa aqui significa que o que está no
     * disco não é o que gravamos — e a resposta certa para isso é não servir.
     */
    private const SERVABLE_MIME = 'image/jpeg';

    /**
     * ── Content-Type por re-sniff NO SERVIDOR (§ 1.4) ───────────────────────
     * Nunca do que o upload declarou e nunca do nome do arquivo: os dois são
     * escolhidos pelo cliente, e um `Content-Type: text/html` servido de volta é
     * XSS armazenado com o domínio da sessão. O sniff olha os bytes que estamos
     * prestes a entregar, depois de decifrados.
     *
     * `nosniff` já vem do middleware SecurityHeaders em toda resposta; repetido
     * aqui porque esta é a única em que o navegador recebe bytes controlados
     * pelo usuário, e um dia alguém pode afinar o middleware sem lembrar disto.
     *
     * `inline` e não `attachment`: a foto é para ser vista na tela, não baixada.
     * Isso não impede o download — não existe cabeçalho que impeça — e o § 1.1 é
     * explícito de que o TTL protege o arquivo, não a memória nem o print.
     *
     * O nome do arquivo é GENÉRICO. `original_filename` é do membro e costuma
     * ser tão descritivo quanto a foto ("selfie-do-quarto.jpg"): mandá-lo à
     * performer no `Content-Disposition` seria vazar por cabeçalho o que o
     * `$hidden` do model tira do JSON.
     *
     * `no-store` porque cache é retenção: uma foto de 24h no disco do navegador
     * (ou num proxy) sobrevive ao TTL, que é o problema inteiro da feature.
     *
     * `$filename` só troca o rótulo do `Content-Disposition` (foto.jpg / story.jpg)
     * e nunca vem do request: é literal do chamador, pela mesma razão que o nome
     * original do membro não sai daqui.
     */
    private function photoResponse(string $bytes, string $filename = 'foto.jpg'): Response
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        abort_unless($mime === self::SERVABLE_MIME, 404);

        return response($bytes, 200, [
            'Content-Type' => self::SERVABLE_MIME,
            'Content-Length' => (string) strlen($bytes),
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}
