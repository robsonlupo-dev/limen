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

    /*
    |--------------------------------------------------------------------------
    | Marca de origem no VÍDEO (Onda B §4, peça 2c) — DESLIGADA por padrão
    |--------------------------------------------------------------------------
    |
    | Vídeo é o caminho CARO (overlay por frame via ffmpeg no `ProcessVideoContent`,
    | fila de 2 vCPU). A infra fica pronta e testável (`php artisan
    | origin-mark:video-selftest`), mas o interruptor nasce OFF: liga só no go-live,
    | depois do self-test, para não arriscar o servidor antes da hora. Estilo de
    | RODAPÉ (como o OnlyFans), não o ladrilho diagonal da foto — uma linha discreta
    | na base do vídeo. Fail-OPEN: se o overlay falhar, o vídeo publica sem marca (é
    | branding, não entrega paga — diferente da marca forense da encomenda, fail-closed).
    |
    */
    'video_enabled' => (bool) env('ORIGIN_MARK_VIDEO', false),

    'video' => [
        'opacity' => 0.50,        // 0..1 — texto branco do rodapé (mais visível que a foto: é "selo")
        'shadow_opacity' => 0.45, // sombra escura atrás (legível em fundo claro)
        'shadow_offset' => 2,     // px
        'size_ratio' => 0.030,    // fração do MENOR lado do vídeo (escala com a resolução)
        'margin_ratio' => 0.045,  // distância da linha até a base, fração da ALTURA
        // Posição horizontal: 'right' (padrão — centralizado ficava atrás dos
        // controles do player, achado do UAT), 'left' ou 'center'.
        'align' => env('ORIGIN_MARK_VIDEO_ALIGN', 'right'),
        'side_margin_ratio' => 0.04, // respiro até a borda lateral, fração da LARGURA
    ],

    // Default do toggle por performer quando a coluna ainda não foi escrita:
    // `true` = "Limen · @slug" (divulgação, padrão do Fatal Model); a performer
    // pode trocar para só "Limen" no Editar perfil. O @slug já é público (vai na
    // URL do perfil), então carimbá-lo não revela nada novo.
    'handle_default' => (bool) env('ORIGIN_MARK_HANDLE_DEFAULT', true),

];
