<?php

use App\Exceptions\ImageProcessingException;
use App\Services\ImageProcessingService;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

// tests/Unit não é ligado ao TestCase pelo Pest.php (só Feature é), e o service
// lê config('image.*') — precisa da app de pé. Sem banco: nada aqui persiste.
uses(TestCase::class);

/**
 * Higienização de imagem (App\Services\ImageProcessingService).
 *
 * O que estes testes protegem é a propriedade de docs/SECURITY_ISSUES.md § 1.4:
 * o arquivo que sai do service não pode carregar a localização de quem enviou,
 * e o service não pode ser derrubado pelo arquivo que recebe.
 *
 * As fixtures são montadas byte a byte de propósito. Um JPEG "de verdade" com
 * GPS teria de ser versionado como binário — e um arquivo opaco no repo é uma
 * asserção que ninguém consegue ler: daqui a um ano ninguém sabe dizer se ele
 * ainda TEM as tags que o teste afirma remover. Montado em código, o que está
 * sendo removido está escrito.
 */

// ─── Fixtures ────────────────────────────────────────────────────────────────

/** Um RATIONAL do EXIF: numerador e denominador, big endian. */
function exifRational(int $num, int $den): string
{
    return pack('NN', $num, $den);
}

/**
 * JPEG com segmento APP1/EXIF carregando coordenadas GPS.
 *
 * É a foto de celular do § 1.4 reduzida ao essencial: as tags que entregam
 * onde a pessoa estava. Coordenadas de São Paulo (23°33'S, 46°38'W).
 */
function jpegWithGpsExif(int $width = 40, int $height = 30): string
{
    $latData = exifRational(23, 1).exifRational(33, 1).exifRational(0, 1);
    $lonData = exifRational(46, 1).exifRational(38, 1).exifRational(0, 1);

    // Offsets contados a partir do início do cabeçalho TIFF:
    //   0  cabeçalho TIFF (8 bytes)
    //   8  IFD0        — 1 entrada (12B) + contador (2B) + próximo IFD (4B) = 18
    //   26 GPS IFD     — 4 entradas (48B) + contador (2B) + próximo IFD (4B) = 54
    //   80 GPSLatitude  (3 rationals = 24B)
    //   104 GPSLongitude (3 rationals = 24B)
    $gpsIfdOffset = 26;
    $latOffset = 80;
    $lonOffset = 104;

    $tiff = "MM\x00\x2A".pack('N', 8);

    $tiff .= pack('n', 1);
    $tiff .= pack('nnN', 0x8825, 4, 1).pack('N', $gpsIfdOffset); // GPSInfo → GPS IFD
    $tiff .= pack('N', 0);

    $tiff .= pack('n', 4); // entradas da GPS IFD, ordenadas por tag
    $tiff .= pack('nnN', 0x0001, 2, 2)."S\x00\x00\x00";
    $tiff .= pack('nnN', 0x0002, 5, 3).pack('N', $latOffset);
    $tiff .= pack('nnN', 0x0003, 2, 2)."W\x00\x00\x00";
    $tiff .= pack('nnN', 0x0004, 5, 3).pack('N', $lonOffset);
    $tiff .= pack('N', 0);

    $tiff .= $latData.$lonData;

    $payload = "Exif\x00\x00".$tiff;
    $app1 = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

    return "\xFF\xD8".$app1.substr(rawJpeg($width, $height), 2);
}

/** JPEG limpo, direto do GD. */
function rawJpeg(int $width, int $height): string
{
    $img = imagecreatetruecolor($width, $height);
    imagefilledrectangle($img, 0, 0, $width - 1, $height - 1, imagecolorallocate($img, 10, 120, 200));
    ob_start();
    imagejpeg($img, null, 90);
    $bytes = ob_get_clean();
    imagedestroy($img);

    return $bytes;
}

/** PNG legítimo, com canal alfa (o JPEG de saída tem de achatar isso). */
function rawPng(int $width, int $height): string
{
    $img = imagecreatetruecolor($width, $height);
    imagesavealpha($img, true);
    imagefill($img, 0, 0, imagecolorallocatealpha($img, 200, 30, 60, 40));
    ob_start();
    imagepng($img);
    $bytes = ob_get_clean();
    imagedestroy($img);

    return $bytes;
}

/**
 * PNG que DECLARA dimensões enormes no IHDR sem carregar os pixels — a
 * imagem-bomba do § 1.4: 200 KB de arquivo, gigabytes de bitmap se decodificada.
 *
 * O IHDR é reescrito e o CRC recalculado (crc32() do PHP é o mesmo CRC-32 do
 * PNG), senão um decodificador estrito recusaria pelo checksum e o teste
 * passaria pelo motivo errado.
 */
function pngDeclaring(int $width, int $height): string
{
    $png = rawPng(4, 4);

    // 8 bytes de assinatura, 4 de comprimento, 4 do tipo "IHDR", 13 de dados.
    $ihdrData = substr($png, 16, 13);
    $patched = pack('N', $width).pack('N', $height).substr($ihdrData, 8);
    $crc = pack('N', crc32('IHDR'.$patched));

    return substr($png, 0, 16).$patched.$crc.substr($png, 33);
}

/** Embrulha bytes num UploadedFile em modo de teste (pula is_uploaded_file). */
function uploadFrom(string $bytes, string $name, string $mime): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'limen_fixture_');
    file_put_contents($path, $bytes);

    return new UploadedFile($path, $name, $mime, null, true);
}

function processor(): ImageProcessingService
{
    return app(ImageProcessingService::class);
}

// ─── Strip de EXIF/GPS — a razão de o service existir ────────────────────────

it('remove as tags EXIF/GPS da foto enviada', function () {
    $upload = uploadFrom(jpegWithGpsExif(), 'foto.jpg', 'image/jpeg');

    // A fixture PRECISA carregar GPS, senão o teste abaixo não prova nada.
    $before = @exif_read_data($upload->getRealPath());
    expect($before)->toBeArray()
        ->and($before)->toHaveKeys(['GPSLatitude', 'GPSLongitude'])
        ->and($before['GPSLatitude'])->toBe(['23/1', '33/1', '0/1']);

    $processed = processor()->process($upload);

    $after = @exif_read_data($processed);

    // Sem NENHUMA tag GPS. Não basta a latitude sumir: GPSDateStamp e
    // GPS_IFD_Pointer sozinhos já dizem que a foto foi tirada com localização
    // ligada, e o ponteiro remanescente seria a pista de que o strip é parcial.
    $gpsTags = array_filter(
        array_keys($after ?: []),
        fn (string $tag) => str_starts_with($tag, 'GPS'),
    );

    expect($gpsTags)->toBeEmpty();

    @unlink($processed);
});

// ─── Formato de saída ────────────────────────────────────────────────────────

it('re-encoda PNG como JPEG', function () {
    $processed = processor()->process(uploadFrom(rawPng(300, 200), 'foto.png', 'image/png'));

    expect(getimagesize($processed)[2])->toBe(IMAGETYPE_JPEG);

    @unlink($processed);
});

it('devolve um arquivo com Content-Type de JPEG, sniffado do conteúdo', function () {
    // O tipo é derivado do arquivo, nunca do que o upload declarou — por isso
    // a fixture mente no nome e no mime e o resultado tem de ser JPEG mesmo assim.
    $processed = processor()->process(uploadFrom(rawPng(120, 120), 'x.png', 'text/plain'));

    expect(mime_content_type($processed))->toBe('image/jpeg')
        ->and(getimagesize($processed)['mime'])->toBe('image/jpeg');

    @unlink($processed);
});

// ─── Redimensionamento ───────────────────────────────────────────────────────

it('reduz a imagem que excede o teto, mantendo a proporção', function () {
    $processed = processor()->process(uploadFrom(rawJpeg(2400, 1200), 'grande.jpg', 'image/jpeg'));

    [$width, $height] = getimagesize($processed);

    expect($width)->toBe(1200)
        ->and($height)->toBe(600); // 2:1 preservado

    @unlink($processed);
});

it('não amplia a imagem menor que o teto', function () {
    $processed = processor()->process(uploadFrom(rawJpeg(640, 480), 'pequena.jpg', 'image/jpeg'));

    [$width, $height] = getimagesize($processed);

    expect($width)->toBe(640)
        ->and($height)->toBe(480);

    @unlink($processed);
});

it('reduz pelo eixo mais alto quando a imagem é retrato', function () {
    $processed = processor()->process(uploadFrom(rawJpeg(900, 1800), 'retrato.jpg', 'image/jpeg'));

    [$width, $height] = getimagesize($processed);

    expect($height)->toBe(1200)
        ->and($width)->toBe(600);

    @unlink($processed);
});

// ─── Imagem-bomba: barrada no header, ANTES de decodificar ───────────────────

it('recusa a imagem que estoura o teto por eixo', function () {
    $upload = uploadFrom(pngDeclaring(30001, 1), 'bomba.png', 'image/png');

    // A fixture precisa realmente DECLARAR o tamanho, senão o corte não é o
    // que está sendo exercitado.
    expect(getimagesize($upload->getRealPath())[0])->toBe(30001);

    try {
        processor()->process($upload);
        $this->fail('A imagem-bomba deveria ter sido recusada.');
    } catch (ImageProcessingException $e) {
        // O motivo é a prova de que a recusa veio do guard de header e não do
        // decodificador engasgando: o corpo desta fixture tem 4x4 pixels, então
        // uma decodificação daria UNREADABLE.
        expect($e->reason)->toBe(ImageProcessingException::DIMENSIONS_TOO_LARGE);
    }
});

it('recusa a imagem que passa nos eixos mas estoura a área', function () {
    // 30000x30000 não excede eixo nenhum — é exatamente o exemplo do § 1.4, e
    // são 900 megapixels (~3,6 GB no GD). Quem barra é o corte por área.
    $upload = uploadFrom(pngDeclaring(30000, 30000), 'bomba-area.png', 'image/png');

    expect(getimagesize($upload->getRealPath()))->toMatchArray([0 => 30000, 1 => 30000]);

    try {
        processor()->process($upload);
        $this->fail('A bomba por área deveria ter sido recusada.');
    } catch (ImageProcessingException $e) {
        expect($e->reason)->toBe(ImageProcessingException::DIMENSIONS_TOO_LARGE);
    }
});

it('aceita a imagem que fica logo abaixo dos cortes', function () {
    // Espelho dos dois testes acima: os cortes não podem estar recusando tudo.
    $processed = processor()->process(uploadFrom(rawJpeg(1500, 900), 'ok.jpg', 'image/jpeg'));

    expect(getimagesize($processed)[2])->toBe(IMAGETYPE_JPEG);

    @unlink($processed);
});

// ─── Entrada hostil que não é imagem ─────────────────────────────────────────

it('recusa arquivo que não é imagem', function () {
    $upload = uploadFrom('<?php echo "payload"; ?>', 'foto.jpg', 'image/jpeg');

    try {
        processor()->process($upload);
        $this->fail('Um não-imagem deveria ter sido recusado.');
    } catch (ImageProcessingException $e) {
        expect($e->reason)->toBe(ImageProcessingException::NOT_AN_IMAGE);
    }
});

it('recusa formato fora da allowlist', function () {
    // BMP é lido pelo getimagesize e pelo GD, mas não entra: menos formato,
    // menos superfície de parsing.
    $img = imagecreatetruecolor(20, 20);
    ob_start();
    imagebmp($img);
    $bmp = ob_get_clean();
    imagedestroy($img);

    try {
        processor()->process(uploadFrom($bmp, 'foto.bmp', 'image/bmp'));
        $this->fail('BMP deveria ter sido recusado.');
    } catch (ImageProcessingException $e) {
        expect($e->reason)->toBe(ImageProcessingException::UNSUPPORTED_FORMAT);
    }
});

it('recusa imagem corrompida sem deixar arquivo temporário para trás', function () {
    // Header íntegro e dimensões dentro dos cortes, corpo que não bate com o
    // que o header promete: passa pelo guard e só falha no decodificador. É o
    // par do teste da bomba — mesma fixture, tamanho declarado modesto — e
    // junto com ele prova a ORDEM: header primeiro, decodificação depois.
    $before = count(glob(sys_get_temp_dir().'/limen_img_*') ?: []);

    try {
        processor()->process(uploadFrom(pngDeclaring(100, 100), 'corrompida.png', 'image/png'));
        $this->fail('Imagem corrompida deveria ter sido recusada.');
    } catch (ImageProcessingException $e) {
        expect($e->reason)->toBe(ImageProcessingException::UNREADABLE);
    }

    // A falha vem DEPOIS do tempnam(), então este é o caminho em que o
    // temporário vazaria se o catch não o apagasse.
    expect(count(glob(sys_get_temp_dir().'/limen_img_*') ?: []))->toBe($before);
});

// ─── Driver fixo ─────────────────────────────────────────────────────────────

it('lança em driver desconhecido em vez de cair num padrão silencioso', function () {
    // Fallback silencioso é a própria falha que o driver fixo evita: produção
    // com Imagick e dev com GD produziriam bytes diferentes para o mesmo upload.
    config(['image.driver' => 'quicktime']);

    try {
        processor()->process(uploadFrom(rawJpeg(100, 100), 'foto.jpg', 'image/jpeg'));
        $this->fail('Driver desconhecido deveria ter lançado.');
    } catch (ImageProcessingException $e) {
        expect($e->reason)->toBe(ImageProcessingException::DRIVER_UNSUPPORTED);
    }
});

it('usa o driver gd por padrão', function () {
    expect(config('image.driver'))->toBe('gd');
});
