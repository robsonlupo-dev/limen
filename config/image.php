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
    | ~4 bytes por pixel, então é a ÁREA que amarra o consumo de memória.
    |
    | ── Por que 4 MP e não 50 (revisão de segurança, 29/07/2026) ────────────
    | O teto anterior era calibrado por "cabe uma câmera de celular", que é o
    | critério errado: o que importa é quanto o GD aloca ANTES de o re-encode
    | reduzir. 49 MP (um PNG 7000x7000, que comprime para poucas centenas de KB)
    | pedem ~200 MB dentro do `imagecreatefrom*` — e estourar `memory_limit` em
    | PHP é FATAL ERROR, não Throwable: não cai no catch do service, o worker
    | php-fpm morre, o cliente leva 502 e o temporário EM CLARO fica órfão em
    | /tmp. Em laço, derruba o pool.
    |
    | A saída é `scaleDown(1200, 1200)`, então nada acima de ~1,4 MP sobrevive
    | ao processamento de qualquer forma: aceitar 49 MP não comprava resolução
    | nenhuma, só superfície. 4 MP (2000x2000) são ~16 MB no GD — folga de sobra
    | para o teto de exibição e ordens de grandeza abaixo de qualquer
    | `memory_limit` de produção.
    */
    'max_pixels' => 4_000_000,

];
