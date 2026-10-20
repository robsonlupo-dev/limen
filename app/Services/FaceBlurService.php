<?php

namespace App\Services;

use RuntimeException;

/**
 * Rosto borrado (Onda B §4) — blur LOCALIZADO num oval posicionado pela performer, estilo
 * Fatal Model. A performer escolhe (nada é detectado por IA/ML: o servidor de 2 vCPU não
 * paga esse custo) e marca onde está o rosto; aqui o oval vira um MOSAICO IRREVERSÍVEL
 * (downscale→upscale, como a prévia do paywall), com borda suave, queimado nos bytes.
 *
 * Queimado = sem volta: não guardamos o original nem a REGIÃO (não registrar "onde está o
 * rosto" é melhor de privacidade). Por isso este blur NÃO é o produto "revelar rosto"
 * (Premium, futuro) — aquele precisaria manter o original e servir borrado por acesso.
 *
 * Aplicado no ContentStore DEPOIS do scan CSAM (o scan vê o rosto real) e antes da marca
 * d'água e da gravação, então o hash e a prévia já saem com o rosto coberto.
 */
class FaceBlurService
{
    /**
     * Borra um oval nos bytes de um JPEG e devolve os bytes resultantes. `$region` é
     * normalizado 0..1: ['cx','cy'] = centro, ['rx','ry'] = raios (fração de largura/altura).
     *
     * @param  array{cx:float,cy:float,rx:float,ry:float}  $region
     */
    public function applyToJpegBytes(string $jpegBytes, array $region): string
    {
        $img = @imagecreatefromstring($jpegBytes);
        if ($img === false) {
            throw new RuntimeException('Não foi possível decodificar a imagem para borrar o rosto.');
        }

        try {
            $w = imagesx($img);
            $h = imagesy($img);

            $cx = $this->clamp((float) ($region['cx'] ?? 0.5), 0.0, 1.0) * $w;
            $cy = $this->clamp((float) ($region['cy'] ?? 0.4), 0.0, 1.0) * $h;
            // Raio mínimo para não virar um ponto; máximo = cobre a imagem toda se ela quiser.
            $rx = max(4.0, $this->clamp((float) ($region['rx'] ?? 0.18), 0.02, 1.0) * $w);
            $ry = max(4.0, $this->clamp((float) ($region['ry'] ?? 0.24), 0.02, 1.0) * $h);

            // Caixa do oval, presa aos limites da imagem.
            $x0 = max(0, (int) floor($cx - $rx));
            $y0 = max(0, (int) floor($cy - $ry));
            $x1 = min($w - 1, (int) ceil($cx + $rx));
            $y1 = min($h - 1, (int) ceil($cy + $ry));
            $bw = $x1 - $x0 + 1;
            $bh = $y1 - $y0 + 1;

            if ($bw < 2 || $bh < 2) {
                return $jpegBytes; // região degenerada — nada a fazer
            }

            // Mosaico IRREVERSÍVEL: encolhe a caixa (~10 células no menor lado) e volta sem
            // suavizar (nearest), virando blocos grandes. ~10 células já torna o rosto
            // irreconhecível, como a prévia de 96px do paywall.
            $cells = 10;
            $smW = max(1, (int) round($bw / max(1, (int) round(min($bw, $bh) / $cells))));
            $smH = max(1, (int) round($bh / max(1, (int) round(min($bw, $bh) / $cells))));

            $small = imagecreatetruecolor($smW, $smH);
            imagecopyresampled($small, $img, 0, 0, $x0, $y0, $smW, $smH, $bw, $bh);
            $mosaic = imagecreatetruecolor($bw, $bh);
            imagecopyresized($mosaic, $small, 0, 0, 0, 0, $bw, $bh, $smW, $smH);
            imagedestroy($small);

            // Composita o mosaico de volta SÓ dentro do oval, com borda suave (feather) para
            // não deixar um corte duro. Imagem é truecolor → cor é o inteiro RGB direto,
            // sem alocação de paleta.
            $featherFrom = 0.72; // d²<0.72 = 100% mosaico; 0.72..1 esmaece até a borda
            for ($yy = 0; $yy < $bh; $yy++) {
                $py = $y0 + $yy;
                $dyN = ($py - $cy) / $ry;
                $dy2 = $dyN * $dyN;
                for ($xx = 0; $xx < $bw; $xx++) {
                    $px = $x0 + $xx;
                    $dxN = ($px - $cx) / $rx;
                    $d2 = $dxN * $dxN + $dy2;
                    if ($d2 > 1.0) {
                        continue; // fora do oval
                    }

                    $mc = imagecolorat($mosaic, $xx, $yy);

                    if ($d2 <= $featherFrom) {
                        imagesetpixel($img, $px, $py, $mc);

                        continue;
                    }

                    // Mistura mosaico↔original pela proximidade da borda.
                    $t = 1.0 - (($d2 - $featherFrom) / (1.0 - $featherFrom)); // 1→0
                    $oc = imagecolorat($img, $px, $py);
                    $r = (int) (((($mc >> 16) & 0xFF) * $t) + ((($oc >> 16) & 0xFF) * (1 - $t)));
                    $g = (int) (((($mc >> 8) & 0xFF) * $t) + ((($oc >> 8) & 0xFF) * (1 - $t)));
                    $b = (int) ((($mc & 0xFF) * $t) + (($oc & 0xFF) * (1 - $t)));
                    imagesetpixel($img, $px, $py, ($r << 16) | ($g << 8) | $b);
                }
            }
            imagedestroy($mosaic);

            ob_start();
            imagejpeg($img, null, (int) config('image.quality', 82));
            $out = (string) ob_get_clean();

            if ($out === '') {
                throw new RuntimeException('Falha ao reencodar a imagem com o rosto borrado.');
            }

            return $out;
        } finally {
            imagedestroy($img);
        }
    }

    private function clamp(float $v, float $min, float $max): float
    {
        return max($min, min($max, $v));
    }
}
