<?php

namespace App\Services;

use App\Exceptions\ImageProcessingException;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use RuntimeException;

/**
 * Marca d'água em diagonal repetida (Onda 4 §4.3). Queima um texto curto — "Fã #NNNN ·
 * dd/mm/aaaa" (FanAlias + data, NUNCA dado real do membro) — semi-transparente e repetido
 * pela mídia, para rastrear/desencorajar vazamento de conteúdo entregue por encomenda.
 *
 * Duas saídas, mesmo padrão visual:
 *  - `applyToPhotoBytes`: recebe os bytes JÁ higienizados do JPEG e devolve os bytes com a
 *    marca (usado no ContentStore, DEPOIS do scan CSAM — a marca é overlay benigno nosso).
 *  - `buildOverlayPng`: gera um PNG TRANSPARENTE do tamanho do vídeo, para o ffmpeg
 *    sobrepor (o vídeo não passa pelo GD; a marca entra no transcode).
 *
 * Driver fixo (config image.driver), como o ImageProcessingService — nunca autodetect.
 * A fonte é um TTF de arquivo (GD exige); ausência da fonte LANÇA (fail-closed: sem marca,
 * sem entrega, quando a feature está ligada).
 */
class WatermarkService
{
    /** Aplica a marca sobre os bytes de um JPEG e devolve os bytes marcados. */
    public function applyToPhotoBytes(string $jpegBytes, string $text): string
    {
        $image = $this->manager()->read($jpegBytes);
        $this->stamp($image, $image->width(), $image->height(), $text);

        return (string) $image->toJpeg(quality: (int) config('image.quality', 82));
    }

    /** Gera um PNG transparente WxH com a marca, salvo em $destPath (para overlay no ffmpeg). */
    public function buildOverlayPng(int $width, int $height, string $text, string $destPath): void
    {
        $canvas = $this->manager()->create(max(1, $width), max(1, $height));
        $this->stamp($canvas, $width, $height, $text);
        $canvas->toPng()->save($destPath);

        if (! is_file($destPath) || filesize($destPath) === 0) {
            throw new RuntimeException('Falha ao gerar o overlay da marca d\'água.');
        }
    }

    /**
     * Desenha o texto em diagonal, repetido num ladrilho que cobre toda a mídia. Linhas
     * alternadas são desencontradas para não deixar "corredores" fáceis de cortar.
     */
    private function stamp(ImageInterface $image, int $w, int $h, string $text): void
    {
        $font = (string) config('custom_order.watermark.font');
        if (! is_file($font)) {
            throw new RuntimeException("Fonte da marca d'água não encontrada: {$font}");
        }

        $size = max(12, (int) round(min($w, $h) * (float) config('custom_order.watermark.size_ratio', 0.045)));
        $opacity = (float) config('custom_order.watermark.opacity', 0.22);
        $angle = (float) config('custom_order.watermark.angle', 30);
        $color = sprintf('rgba(255, 255, 255, %.3f)', max(0.0, min(1.0, $opacity)));

        // Passo do ladrilho: proporcional ao tamanho da fonte e ao comprimento do texto.
        $stepX = max(180, (int) round($size * (mb_strlen($text) * 0.62 + 4)));
        $stepY = max(120, (int) round($size * 3.2));

        $row = 0;
        for ($y = 0; $y <= $h + $stepY; $y += $stepY) {
            $shift = ($row % 2) * intdiv($stepX, 2);
            for ($x = -$shift; $x <= $w + $stepX; $x += $stepX) {
                $image->text($text, $x, $y, function ($f) use ($font, $size, $color, $angle) {
                    $f->filename($font);
                    $f->size($size);
                    $f->color($color);
                    $f->align('center');
                    $f->valign('middle');
                    $f->angle($angle);
                });
            }
            $row++;
        }
    }

    /** ImageManager no driver fixo da config (mesma disciplina do ImageProcessingService). */
    private function manager(): ImageManager
    {
        $driver = (string) config('image.driver');

        return match ($driver) {
            'gd' => ImageManager::gd(),
            'imagick' => ImageManager::imagick(),
            default => throw ImageProcessingException::driverUnsupported($driver),
        };
    }

    /**
     * Texto padrão da marca para uma encomenda: FanAlias (por par) + data de hoje. NUNCA
     * inclui id/nome reais do membro — o FanAlias já é o identificador forense por par.
     */
    public static function labelFor(string $fanAlias): string
    {
        return $fanAlias.' · '.now(ProfileVisitService::DISPLAY_TIMEZONE)->format('d/m/Y');
    }
}
