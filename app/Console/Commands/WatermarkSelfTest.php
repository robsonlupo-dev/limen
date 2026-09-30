<?php

namespace App\Console\Commands;

use App\Services\WatermarkService;
use Illuminate\Console\Command;
use Intervention\Image\ImageManager;

/**
 * Self-test da marca d'água (Onda 4 §4.3). Roda o WatermarkService de verdade (GD + fonte
 * TTF) e escreve dois arquivos para conferência VISUAL — foto marcada e overlay PNG —,
 * SEM depender do flag `custom_order.watermark.enabled` (o flag só governa a entrega real).
 *
 * Uso no servidor ANTES de ligar a feature (dev==prod, e o caminho de vídeo não é coberto
 * pela suíte):
 *   php artisan custom-orders:watermark-selftest
 *   php artisan custom-orders:watermark-selftest /caminho/para/foto.jpg --out=/tmp
 * Abra os arquivos gerados; se a marca aparecer legível e sutil, pode ligar
 * CUSTOM_ORDER_WATERMARK=true + config:cache.
 */
class WatermarkSelfTest extends Command
{
    protected $signature = 'custom-orders:watermark-selftest {source? : JPEG de entrada (opcional; gera um de teste se ausente)} {--out=/tmp : pasta de saída}';

    protected $description = 'Gera amostras da marca d\'água (foto + overlay) para conferência visual.';

    public function handle(WatermarkService $watermark): int
    {
        $out = rtrim((string) $this->option('out'), '/');
        $text = WatermarkService::labelFor('Fã #5454');

        // 1) Foto: usa a fonte de entrada ou gera um retângulo cinza de teste.
        $source = $this->argument('source');
        if ($source !== null && is_file($source)) {
            $bytes = (string) file_get_contents($source);
        } else {
            $bytes = (string) ImageManager::gd()->create(1080, 1350)->fill('rgb(60,55,48)')->toJpeg(quality: 85);
            $this->info('Sem fonte: gerei uma foto de teste 1080x1350.');
        }

        try {
            $photo = $watermark->applyToPhotoBytes($bytes, $text);
            $photoPath = "{$out}/watermark-photo.jpg";
            file_put_contents($photoPath, $photo);
            $this->info("✅ Foto marcada: {$photoPath}");

            $overlayPath = "{$out}/watermark-overlay.png";
            $watermark->buildOverlayPng(1280, 720, $text, $overlayPath);
            $this->info("✅ Overlay 1280x720: {$overlayPath}");
        } catch (\Throwable $e) {
            $this->error('Falhou: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line("Texto usado: {$text}");
        $this->line('Abra os dois arquivos. Se a marca estiver legível e sutil, ligue CUSTOM_ORDER_WATERMARK=true + config:cache.');

        return self::SUCCESS;
    }
}
