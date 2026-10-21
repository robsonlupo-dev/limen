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
     * Overlay de RODAPÉ (Onda B §4, peça 2c) — a marca de ORIGEM no vídeo, estilo OnlyFans:
     * uma única linha centralizada perto da base, não o ladrilho diagonal. PNG transparente
     * WxH para o ffmpeg esticar com scale2ref (como o overlay diagonal) — construído na
     * proporção do vídeo, o rodapé fica proporcional depois do stretch. Estilo em
     * `config/origin_mark.video.*`.
     */
    public function buildFooterOverlayPng(int $width, int $height, string $text, string $destPath): void
    {
        $w = max(1, $width);
        $h = max(1, $height);
        $canvas = $this->manager()->create($w, $h);
        $this->stampFooter($canvas, $w, $h, $text);
        $canvas->toPng()->save($destPath);

        if (! is_file($destPath) || filesize($destPath) === 0) {
            throw new RuntimeException('Falha ao gerar o overlay de rodapé da marca de origem.');
        }
    }

    /**
     * Uma linha perto da base: sombra escura + texto branco, sem rotação. Posição
     * horizontal configurável — padrão à DIREITA (achado do UAT: centralizado, a
     * marca ficava escondida atrás dos controles do player; canto é onde o
     * OnlyFans põe a dele e onde legenda/controle não cobrem).
     */
    private function stampFooter(ImageInterface $image, int $w, int $h, string $text): void
    {
        $font = (string) config('custom_order.watermark.font');
        if (! is_file($font)) {
            throw new RuntimeException("Fonte da marca não encontrada: {$font}");
        }

        $size = max(12, (int) round(min($w, $h) * (float) config('origin_mark.video.size_ratio', 0.030)));
        $opacity = (float) config('origin_mark.video.opacity', 0.50);
        $shadowOpacity = (float) config('origin_mark.video.shadow_opacity', 0.45);
        $shadowOffset = max(0, (int) config('origin_mark.video.shadow_offset', 2));
        $margin = (int) round($h * (float) config('origin_mark.video.margin_ratio', 0.045));
        $sideMargin = (int) round($w * (float) config('origin_mark.video.side_margin_ratio', 0.04));

        $white = sprintf('rgba(255, 255, 255, %.3f)', max(0.0, min(1.0, $opacity)));
        $shadow = sprintf('rgba(0, 0, 0, %.3f)', max(0.0, min(1.0, $shadowOpacity)));

        // 'right' (padrão) | 'left' | 'center' — valor desconhecido cai em 'right'.
        $align = (string) config('origin_mark.video.align', 'right');
        if ($align === 'left') {
            $x = min($w, $sideMargin);
        } elseif ($align === 'center') {
            $x = intdiv($w, 2);
        } else {
            $align = 'right';
            $x = max(0, $w - $sideMargin);
        }
        $y = max(0, $h - $margin);    // perto da base

        $base = function ($f) use ($font, $size, $align) {
            $f->filename($font);
            $f->size($size);
            $f->align($align);
            $f->valign('bottom');
        };

        if ($shadowOffset > 0) {
            $image->text($text, $x + $shadowOffset, $y + $shadowOffset, function ($f) use ($base, $shadow) {
                $base($f);
                $f->color($shadow);
            });
        }
        $image->text($text, $x, $y, function ($f) use ($base, $white) {
            $base($f);
            $f->color($white);
        });
    }

    /**
     * Desenha o texto em diagonal, repetido num ladrilho que cobre toda a mídia. Linhas
     * alternadas são desencontradas para não deixar "corredores" fáceis de cortar.
     *
     * Marca LEVE e legível em qualquer fundo (ajuste de UAT): cada repetição é um texto
     * BRANCO bem transparente (aparece nas áreas escuras) desenhado por cima de uma SOMBRA
     * escura sutil deslocada (aparece nas áreas claras, onde o branco sumiria). O passo do
     * ladrilho vem da CAIXA REAL do texto já girado — nunca encavala, seja qual for o
     * tamanho do apelido (o chute por nº de caracteres cruzava as linhas com texto longo).
     */
    private function stamp(ImageInterface $image, int $w, int $h, string $text): void
    {
        $font = (string) config('custom_order.watermark.font');
        if (! is_file($font)) {
            throw new RuntimeException("Fonte da marca d'água não encontrada: {$font}");
        }

        $size = max(12, (int) round(min($w, $h) * (float) config('custom_order.watermark.size_ratio', 0.028)));
        $angle = (float) config('custom_order.watermark.angle', 30);
        $opacity = (float) config('custom_order.watermark.opacity', 0.11);
        $shadowOpacity = (float) config('custom_order.watermark.shadow_opacity', 0.16);
        $shadowOffset = max(0, (int) config('custom_order.watermark.shadow_offset', 2));
        $gapX = (float) config('custom_order.watermark.gap_x', 1.6);
        $gapY = (float) config('custom_order.watermark.gap_y', 2.6);

        $white = sprintf('rgba(255, 255, 255, %.3f)', max(0.0, min(1.0, $opacity)));
        $shadow = sprintf('rgba(0, 0, 0, %.3f)', max(0.0, min(1.0, $shadowOpacity)));

        [$spanX, $spanY] = $this->rotatedSpan($font, $size, $angle, $text);
        $stepX = max(180, (int) ceil($spanX * $gapX));
        $stepY = max(140, (int) ceil($spanY * $gapY));

        // Config comum das duas passadas (sombra e branco): mesma fonte/tamanho/ângulo,
        // ancorado no CENTRO do ponto (align+valign) para a rotação girar em torno dele.
        $base = function ($f) use ($font, $size, $angle) {
            $f->filename($font);
            $f->size($size);
            $f->align('center');
            $f->valign('middle');
            $f->angle($angle);
        };

        $row = 0;
        for ($y = -(int) ($stepY / 2); $y <= $h + $stepY; $y += $stepY) {
            $shift = ($row % 2) * intdiv($stepX, 2);
            for ($x = -$shift; $x <= $w + $stepX; $x += $stepX) {
                if ($shadowOffset > 0) {
                    $image->text($text, $x + $shadowOffset, $y + $shadowOffset, function ($f) use ($base, $shadow) {
                        $base($f);
                        $f->color($shadow);
                    });
                }
                $image->text($text, $x, $y, function ($f) use ($base, $white) {
                    $base($f);
                    $f->color($white);
                });
            }
            $row++;
        }
    }

    /**
     * Dimensões (largura × altura) que o texto OCUPA depois de girado — base para espaçar o
     * ladrilho sem sobreposição. Usa o bbox exato do GD (`imagettfbbox`, fonte TTF) quando
     * disponível; senão estima pela geometria (largura ≈ nº de chars × tamanho; altura ≈
     * 1,3 × tamanho). Superestimar só deixa a marca mais arejada — nunca sobreposta.
     *
     * @return array{0: float, 1: float}
     */
    private function rotatedSpan(string $font, int $size, float $angleDeg, string $text): array
    {
        $tw = null;
        $th = null;

        if (function_exists('imagettfbbox')) {
            $bb = @imagettfbbox($size, 0, $font, $text);
            if (is_array($bb)) {
                $tw = max($bb[2], $bb[4]) - min($bb[0], $bb[6]);
                $th = max($bb[1], $bb[3]) - min($bb[5], $bb[7]);
            }
        }

        if ($tw === null || $th === null) {
            $tw = mb_strlen($text) * $size * 0.62;
            $th = $size * 1.3;
        }

        $rad = deg2rad($angleDeg);
        $spanX = abs($tw * cos($rad)) + abs($th * sin($rad));
        $spanY = abs($tw * sin($rad)) + abs($th * cos($rad));

        return [$spanX, $spanY];
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
