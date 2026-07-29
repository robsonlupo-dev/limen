<?php

/*
|--------------------------------------------------------------------------
| Processamento de imagem (App\Services\ImageProcessingService)
|--------------------------------------------------------------------------
|
| Config do Limen, NÃO do pacote: o projeto usa a lib standalone
| `intervention/image`, não o wrapper `intervention/image-laravel` (que
| publicaria um config/image.php próprio). Se um dia o wrapper entrar, este
| arquivo colide com o dele — renomeie este, não o outro.
|
| Origem da decisão: docs/SECURITY_ISSUES.md § 1.4 (EXIF/GPS).
|
*/

return [

    /*
    | Driver FIXO, nunca autodetect.
    |
    | Neste ambiente há GD e não há Imagick. Se produção tiver Imagick e o
    | driver fosse autodetectado, o MESMO upload produziria bytes diferentes
    | nos dois lugares — e a divergência apareceria como bug de imagem, não
    | como diferença de ambiente. Valor desconhecido faz o service lançar,
    | em vez de cair num padrão silencioso.
    */
    'driver' => 'gd',

    /*
    | Teto de exibição. `scaleDown` só REDUZ: imagem menor que isto passa
    | intacta, nunca é ampliada.
    */
    'max_width' => 1200,
    'max_height' => 1200,

    /* Qualidade do JPEG de saída (0-100). */
    'quality' => 80,

    /*
    | Remoção de metadados (EXIF/GPS).
    |
    | Sob GD o valor é ADVISORY, não um interruptor: `imagejpeg()` não escreve
    | EXIF nenhum, então o strip é estrutural — sai do re-encode, não da flag.
    | Pôr `false` aqui NÃO faz a EXIF voltar no driver gd. A flag é repassada
    | ao encoder para que a intenção continue explícita se o driver mudar para
    | Imagick, onde ela de fato decide.
    */
    'strip_metadata' => true,

    /*
    | ── Guardas de imagem-bomba (§ 1.4: "limite de dimensões, não só de bytes")
    |
    | Um PNG de 200 KB declara dimensões enormes no header e estoura a memória
    | do PHP dentro do `imagecreatefrom*` ANTES de qualquer validação de
    | tamanho de arquivo servir para algo. Os dois cortes são lidos do header
    | via `getimagesize()`, que não aloca o bitmap.
    */

    /* Corte por eixo. */
    'max_dimension' => 30000,

    /*
    | Corte por ÁREA — o que de fato fecha o exemplo do § 1.4.
    |
    | O corte por eixo sozinho aceita 30000x30000 (nenhum eixo o excede) = 900
    | megapixels, que é exatamente a imagem-bomba que o doc descreve. GD aloca
    | ~4 bytes por pixel, então o teto abaixo (50 MP ≈ 200 MB) é o que amarra o
    | consumo de memória. Cobre com folga qualquer câmera de celular (até
    | ~50 MP); acima disso é upload que não vem de uma foto.
    */
    'max_pixels' => 50_000_000,

];
