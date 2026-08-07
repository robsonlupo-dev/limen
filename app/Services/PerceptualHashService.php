<?php

namespace App\Services;

use RuntimeException;

/**
 * Hash PERCEPTUAL (dHash) de uma imagem — 64 bits que sobrevivem a re-encode e
 * pequenas edições, ao contrário do SHA-256 (que muda a cada byte). É o que
 * permite casar contra listas de hashes conhecidos mesmo depois do re-encode do
 * ImageProcessingService.
 *
 * dHash: reduz para 9×8 em tons de cinza e compara cada pixel ao vizinho à
 * direita → 8×8 = 64 bits. Simples, estável e sem dependência externa (GD, o
 * mesmo motor do ImageProcessingService). Falha ao decodificar → lança (o
 * chamador decide o fail-open).
 *
 * NÃO substitui PhotoDNA: é o hash LOCAL do MVP. A integração real (matching por
 * distância + a lista NCMEC/IWF) é follow-up.
 */
class PerceptualHashService
{
    /** @throws RuntimeException imagem indecodificável */
    public function hash(string $imageBytes): string
    {
        $image = @imagecreatefromstring($imageBytes);

        if ($image === false) {
            throw new RuntimeException('Não foi possível decodificar a imagem para hash perceptual.');
        }

        try {
            $width = 9;
            $height = 8;

            $small = imagecreatetruecolor($width, $height);
            imagecopyresampled($small, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));
            imagefilter($small, IMG_FILTER_GRAYSCALE);

            $bits = '';
            for ($y = 0; $y < $height; $y++) {
                for ($x = 0; $x < $width - 1; $x++) {
                    // Tons de cinza: R=G=B, então o byte baixo é o valor.
                    $left = imagecolorat($small, $x, $y) & 0xFF;
                    $right = imagecolorat($small, $x + 1, $y) & 0xFF;
                    $bits .= $left < $right ? '1' : '0';
                }
            }

            imagedestroy($small);

            // 64 bits → 16 hex (4 bits por nibble).
            $hex = '';
            foreach (str_split($bits, 4) as $nibble) {
                $hex .= dechex(bindec($nibble));
            }

            return $hex;
        } finally {
            imagedestroy($image);
        }
    }
}
