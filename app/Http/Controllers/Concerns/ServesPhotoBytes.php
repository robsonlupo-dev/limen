<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Response;

/**
 * Resposta binária das fotos efêmeras — a mesma nos dois lados (membro e
 * performer), porque um segundo lugar montando estes cabeçalhos divergiria
 * exatamente nos que importam.
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
     */
    private function photoResponse(string $bytes): Response
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        abort_unless($mime === self::SERVABLE_MIME, 404);

        return response($bytes, 200, [
            'Content-Type' => self::SERVABLE_MIME,
            'Content-Length' => (string) strlen($bytes),
            'Content-Disposition' => 'inline; filename="foto.jpg"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}
