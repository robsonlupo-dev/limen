<?php

namespace App\Services;

use App\Exceptions\ImageProcessingException;
use Illuminate\Http\UploadedFile;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Higienização de imagem enviada por usuário — re-encode que mata EXIF/GPS.
 *
 * Origem: docs/SECURITY_ISSUES.md § 1.4, decidido pelo PO em 24/07/2026.
 * Foto de celular carrega coordenadas GPS e identificador de dispositivo. Um
 * membro que manda uma "foto privada efêmera" entrega as coordenadas de casa
 * — permanentemente, num arquivo que a performer pode baixar antes do TTL.
 * O problema é MAIOR que o TTL: apagar depois não desfaz o download.
 *
 * O re-encode resolve dois vetores no mesmo passo, porque o arquivo servido
 * deixa de ser o arquivo enviado:
 *  1. EXIF/GPS — `imagejpeg()` do GD não escreve metadado nenhum;
 *  2. polyglot — o JPEG de saída é gerado a partir do bitmap, então qualquer
 *     payload escondido em segmento de metadado ou depois do EOI não
 *     sobrevive.
 * Complemento no transporte: `X-Content-Type-Options: nosniff` (já no
 * SecurityHeaders) e Content-Type derivado de re-sniff no SERVIDOR, nunca do
 * que o upload declarou.
 *
 * ── Serviço compartilhado, sem opinião sobre storage ─────────────────────
 * Consumidores: Foto Efêmera e Stories. Este service NÃO salva, NÃO cifra e
 * NÃO conhece disco: processa e devolve o caminho de um arquivo limpo em
 * disco temporário. Quem persiste é o Store de cada feature — a Foto Efêmera
 * ainda cifra por cima (Crypt), Stories não, e embutir essa escolha aqui
 * amarraria as duas.
 *
 * ── Contrapartida que a decisão traz junto (§ 1.4) ───────────────────────
 * Passamos a entregar arquivo controlado pelo atacante a uma biblioteca de
 * imagem — exatamente onde vivem os CVEs de leitura fora de limites e de
 * exaustão de memória. Por isso a validação de dimensões acontece ANTES de
 * qualquer decodificação, lendo só o header. Ver assertSafeDimensions().
 */
class ImageProcessingService
{
    /**
     * Formatos que entregamos ao decodificador.
     *
     * Allowlist, não denylist: são os quatro decodificadores mais exercitados
     * do GD. O que sobra (BMP, XBM, WBMP, ICO…) não tem uso no produto e só
     * somaria superfície de parsing por nada.
     */
    private const ALLOWED_TYPES = [
        IMAGETYPE_JPEG,
        IMAGETYPE_PNG,
        IMAGETYPE_GIF,
        IMAGETYPE_WEBP,
    ];

    /**
     * Processa o upload e devolve o CAMINHO de um JPEG limpo em disco temporário.
     *
     * O arquivo retornado é responsabilidade do chamador: mova-o para o storage
     * definitivo e apague o temporário (o SO limpa o tmp, mas não na hora).
     * O caminho vem SEM extensão, de propósito — `tempnam()` cria o arquivo de
     * forma exclusiva, e inventar um `.jpg` por cima exigiria unlink+rename,
     * reabrindo uma janela de corrida por um dado que ninguém deve consultar:
     * o tipo se descobre por sniff do conteúdo, que é sempre `image/jpeg`.
     *
     * Dimensões alvo: por padrão o teto de exibição da config (`scaleDown`, que
     * só REDUZ e preserva a proporção). O chamador pode fixar outro alvo — a
     * capa da performer pede 1200x400 (3:1) em `$crop=true`, que usa `cover()`:
     * escala para PREENCHER a caixa e recorta o excesso, garantindo a proporção
     * exata (uma foto retrato vira uma faixa 3:1 sem distorção). As guardas de
     * imagem-bomba (assertSafeDimensions) valem igual nos dois modos.
     *
     * @throws ImageProcessingException entrada recusada ou indecodificável
     */
    public function process(
        UploadedFile $file,
        ?int $maxWidth = null,
        ?int $maxHeight = null,
        bool $crop = false,
    ): string {
        $source = $file->getRealPath();

        if ($source === false || ! is_readable($source)) {
            throw ImageProcessingException::notAnImage();
        }

        // ANTES de decodificar. Ver o docblock do método.
        $this->assertSafeDimensions($source);

        $target = tempnam(sys_get_temp_dir(), 'limen_img_');

        if ($target === false) {
            throw ImageProcessingException::unreadable();
        }

        $width = $maxWidth ?? (int) config('image.max_width');
        $height = $maxHeight ?? (int) config('image.max_height');

        try {
            $image = $this->manager()->read($source);

            if ($crop) {
                // Recorta para a proporção EXATA da caixa (preenche e apara).
                $image->cover($width, $height);
            } else {
                // Só REDUZ e mantém a proporção: imagem menor que o teto sai
                // com as dimensões originais, nunca ampliada (ampliar não
                // acrescenta informação e só multiplica bytes).
                $image->scaleDown($width, $height);
            }

            $image
                ->toJpeg(
                    quality: (int) config('image.quality'),
                    // Advisory sob GD — o strip aqui é estrutural, não vem da
                    // flag. Explícito para o dia em que o driver mudar.
                    strip: (bool) config('image.strip_metadata'),
                )
                ->save($target);
        } catch (ImageProcessingException $e) {
            @unlink($target);

            throw $e;
        } catch (Throwable $e) {
            // Header válido não garante corpo válido: arquivo truncado ou
            // deliberadamente corrompido só falha aqui. Não vaza o temporário.
            @unlink($target);

            throw ImageProcessingException::unreadable();
        }

        return $target;
    }

    /**
     * Recusa a imagem-bomba lendo SÓ o cabeçalho.
     *
     * `getimagesize()` lê os poucos bytes do header e devolve as dimensões
     * DECLARADAS — não aloca o bitmap. É o que permite barrar antes de gastar
     * memória: um PNG de 200 KB pode declarar dimensões que estouram o PHP
     * dentro do `imagecreatefrom*`, e nenhum limite de TAMANHO DE ARQUIVO
     * ajuda contra isso (§ 1.4).
     *
     * Dois cortes, e o segundo não é redundante: o corte por eixo sozinho
     * aceita 30000x30000 — nenhum eixo o excede — que são 900 megapixels, ou
     * seja, exatamente a bomba que o § 1.4 dá como exemplo. Quem amarra o
     * consumo é a ÁREA.
     *
     * @throws ImageProcessingException
     */
    private function assertSafeDimensions(string $path): void
    {
        // @ porque getimagesize() emite warning em arquivo não-imagem; o
        // retorno false já é a resposta que interessa.
        $info = @getimagesize($path);

        if ($info === false) {
            throw ImageProcessingException::notAnImage();
        }

        [$width, $height] = $info;

        if (! in_array($info[2] ?? null, self::ALLOWED_TYPES, true)) {
            throw ImageProcessingException::unsupportedFormat();
        }

        $maxDimension = (int) config('image.max_dimension');

        if ($width > $maxDimension || $height > $maxDimension) {
            throw ImageProcessingException::dimensionsTooLarge();
        }

        if ($width * $height > (int) config('image.max_pixels')) {
            throw ImageProcessingException::dimensionsTooLarge();
        }
    }

    /**
     * Instancia o ImageManager no driver da config — nunca por autodetect.
     *
     * `ImageManager::gd()` em vez do detector do pacote: com autodetect, um
     * ambiente com Imagick produziria bytes diferentes para o mesmo upload
     * (§ 1.4). Valor desconhecido LANÇA em vez de cair num padrão — fallback
     * silencioso é a própria falha que o driver fixo existe para evitar.
     *
     * @throws ImageProcessingException
     */
    private function manager(): ImageManager
    {
        $driver = (string) config('image.driver');

        return match ($driver) {
            'gd' => ImageManager::gd(),
            'imagick' => ImageManager::imagick(),
            default => throw ImageProcessingException::driverUnsupported($driver),
        };
    }
}
