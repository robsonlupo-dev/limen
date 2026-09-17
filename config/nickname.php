<?php

return [
    // Tamanho do apelido (decisão do PO).
    'min_length' => 3,
    'max_length' => 20,

    // Trocas: no máximo uma a cada N dias. A performer constrói relação com o
    // nome; troca semanal quebra o vínculo e serve para fugir de bloqueio.
    'cooldown_days' => 7,

    // Telefone disfarçado: uma sequência de N+ dígitos CONSECUTIVOS (depois de
    // tirar separadores/espaços) é tratada como telefone e barrada. 5 pega o
    // número mais curto disfarçado sem barrar um ano ("bella1990" = 4 dígitos).
    'max_digit_run' => 5,

    // Palavras que permitem se passar pela plataforma/moderação. Casadas sobre a
    // forma NORMALIZADA (anti-desvio: leet/repetição/zero-width — ver
    // ChatContentFilter::normalizeForMatch), por SUBSTRING.
    'reserved' => [
        'limen', 'suporte', 'support', 'admin', 'administrador', 'administrator',
        'moderador', 'moderator', 'moderacao', 'atendimento', 'oficial', 'official',
        'staff', 'equipe', 'sistema', 'root',
    ],

    // Contato / redes sociais. Mesma normalização anti-desvio, por SUBSTRING —
    // "1nsta", "wh4ts", "zaap", "0nlyfans" caem aqui.
    'contact_keywords' => [
        'instagram', 'insta', 'whatsapp', 'whats', 'zap', 'telegram', 'tele',
        'onlyfans', 'privacy', 'privacybr', 'tiktok', 'snapchat', 'snap', 'kwai',
        'facebook', 'twitter', 'fanvue', 'gmail', 'hotmail', 'outlook',
    ],

    // Domínios/URL: presença de qualquer um destes barra (TLDs comuns + esquema).
    'url_markers' => [
        'http', 'www.', '.com', '.net', '.org', '.br', '.io', '.me', '.xxx', '.app', '.link',
    ],
];
