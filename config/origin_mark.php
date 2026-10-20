<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Marca de origem (Onda B — roadmap Fatal Model §4)
    |--------------------------------------------------------------------------
    |
    | A marca de ORIGEM é branding + atribuição queimado em TODA foto da
    | performer no momento do upload (avatar/capa/stories vêm nas peças 2b/2c).
    | É discreta e imperceptível — reusa o MESMO motor e estilo da marca d'água
    | de entrega (`WatermarkService` + `config/custom_order.watermark.*`:
    | diagonal repetida, opacidade 0.11); aqui só muda o TEXTO ("Limen" ou
    | "Limen · @slug", escolha da performer em `performer_profiles.origin_mark_handle`).
    |
    | DIFERENTE da marca FORENSE (Fã #NNNN por espectador, §4.3): a origem é
    | IGUAL para todos e carimbada no upload — é divulgação, não identifica quem
    | vazou. Não há, nesta onda, marca por espectador.
    |
    | CUSTO: é um passo GD de uma vez por upload (não por request/exibição), em
    | uma única imagem — barato. O caminho de VÍDEO (overlay por frame via
    | ffmpeg, caro no servidor 2 vCPU) NÃO entra aqui: fica na peça 2c, em PR
    | próprio e desligado até o go-live.
    |
    | `enabled` nasce LIGADO (a marca de origem é o comportamento desejado, como
    | no Fatal Model). O gate `OriginMark::enabled()` ainda confere que a FONTE
    | existe antes de marcar: num box sem a fonte, a marca simplesmente não é
    | aplicada e o upload segue — nunca derruba todo upload de foto por causa de
    | branding (fail-open de branding; o forense da encomenda mantém o
    | fail-closed próprio dele).
    |
    */

    'enabled' => (bool) env('ORIGIN_MARK_ENABLED', true),

    // Default do toggle por performer quando a coluna ainda não foi escrita:
    // `true` = "Limen · @slug" (divulgação, padrão do Fatal Model); a performer
    // pode trocar para só "Limen" no Editar perfil. O @slug já é público (vai na
    // URL do perfil), então carimbá-lo não revela nada novo.
    'handle_default' => (bool) env('ORIGIN_MARK_HANDLE_DEFAULT', true),

];
