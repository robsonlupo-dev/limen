<?php

use App\Models\PerformerContent;
use App\Models\PerformerProfile;
use App\Models\User;
use App\Services\FaceBlurService;
use App\Services\PerformerContentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

/**
 * Rosto borrado (Onda B §4): blur de oval localizado (GD puro, sem fonte TTF — roda em
 * qualquer ambiente) + o flag `face_blurred` na peça. Prefixo `fb`.
 */

/** JPEG não-uniforme: branco com um bloco preto no centro (onde ficaria o rosto). */
function fbImageBytes(int $w = 400, int $h = 500): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
    imagefilledrectangle($im, (int) ($w * 0.35), (int) ($h * 0.30), (int) ($w * 0.65), (int) ($h * 0.60), imagecolorallocate($im, 0, 0, 0));
    ob_start();
    imagejpeg($im, null, 92);
    $bytes = (string) ob_get_clean();
    imagedestroy($im);

    return $bytes;
}

/** Brilho (0..255) de um pixel de um recurso GD. */
function fbBrightness($im, int $x, int $y): int
{
    $rgb = imagecolorat($im, $x, $y);

    return (int) round(((($rgb >> 16) & 0xFF) + (($rgb >> 8) & 0xFF) + ($rgb & 0xFF)) / 3);
}

/**
 * Conta pixels cujo brilho mudou mais que $thresh entre dois JPEGs, numa caixa.
 *
 * @return int
 */
function fbDiffCount(string $jpegA, string $jpegB, int $x0, int $y0, int $x1, int $y1, int $thresh = 40): int
{
    $a = imagecreatefromstring($jpegA);
    $b = imagecreatefromstring($jpegB);
    $n = 0;
    for ($y = $y0; $y <= $y1; $y++) {
        for ($x = $x0; $x <= $x1; $x++) {
            if (abs(fbBrightness($a, $x, $y) - fbBrightness($b, $x, $y)) > $thresh) {
                $n++;
            }
        }
    }
    imagedestroy($a);
    imagedestroy($b);

    return $n;
}

function fbPerformer(): PerformerProfile
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);
    static $n = 0;
    $n++;

    return $user->performerProfile()->create([
        'stage_name' => 'Blur'.$n,
        'slug' => PerformerProfile::generateSlug('Blur'.$n),
        'category' => 'mulheres',
        'worlds' => ['mulheres'],
        'is_verified' => true,
        'level' => 'iniciante',
        'split_pct' => 65,
    ]);
}

it('borra o oval e devolve um JPEG de mesmas dimensões, mudando os bytes', function () {
    $src = fbImageBytes(400, 500);

    $out = app(FaceBlurService::class)->applyToJpegBytes($src, ['cx' => 0.5, 'cy' => 0.45, 'rx' => 0.25, 'ry' => 0.30]);

    $info = getimagesizefromstring($out);
    expect($info)->not->toBeFalse()
        ->and($info[2])->toBe(IMAGETYPE_JPEG)
        ->and($info[0])->toBe(400)
        ->and($info[1])->toBe(500)
        ->and($out)->not->toBe($src);
});

it('mexe DENTRO do oval e deixa o canto (fora do oval) intacto', function () {
    $src = fbImageBytes(400, 500);
    $out = app(FaceBlurService::class)->applyToJpegBytes($src, ['cx' => 0.5, 'cy' => 0.45, 'rx' => 0.25, 'ry' => 0.30]);

    // Dentro da caixa do oval (as bordas do bloco viram mosaico) → muitas mudanças.
    expect(fbDiffCount($src, $out, 110, 90, 290, 360))->toBeGreaterThan(50);

    // Canto superior esquerdo (branco, fora do oval) → nada muda além de ruído de JPEG.
    expect(fbDiffCount($src, $out, 0, 0, 30, 30))->toBe(0);
});

it('região degenerada (raio mínimo) não quebra e devolve um JPEG válido', function () {
    $src = fbImageBytes(80, 80);
    $out = app(FaceBlurService::class)->applyToJpegBytes($src, ['cx' => 0.5, 'cy' => 0.5, 'rx' => 0.02, 'ry' => 0.02]);

    expect(getimagesizefromstring($out)[2])->toBe(IMAGETYPE_JPEG);
});

it('publish com região marca face_blurred; sem região, não', function () {
    $svc = app(PerformerContentService::class);
    $profile = fbPerformer();

    $withBlur = $svc->publish(
        $profile,
        UploadedFile::fake()->image('a.jpg', 600, 800),
        PerformerContent::LEVEL_EXCLUSIVE,
        50,
        false,
        ['cx' => 0.5, 'cy' => 0.4, 'rx' => 0.18, 'ry' => 0.24],
    );
    expect($withBlur->fresh()->face_blurred)->toBeTrue();

    $noBlur = $svc->publish(
        $profile,
        UploadedFile::fake()->image('b.jpg', 600, 800),
        PerformerContent::LEVEL_EXCLUSIVE,
        50,
    );
    expect($noBlur->fresh()->face_blurred)->toBeFalse();
});
