<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Motor de engajamento do catálogo de membros (performer → membro)
    |--------------------------------------------------------------------------
    | O catálogo de membros (a home da performer) tem duas ações por card:
    |
    |  - CORAÇÃO: sinal GRÁTIS e ILIMITADO. A performer curte; o membro vê quem
    |    curtiu SEM pagar, com a identidade da performer (ela não é anônima para
    |    o membro). Não move tokens — não tem custo, cota nem cooldown, só o
    |    throttle anti-flood da rota. Guardado em `performer_hearts`.
    |
    |  - MENSAGEM PERSONALIZADA: texto livre. A performer tem uma franquia diária
    |    de mensagens GRÁTIS (contador em `performer_message_quotas`); o membro vê
    |    QUE tem mensagem e DE QUEM, mas o CORPO fica bloqueado até ele abrir o
    |    chat pago (ChatAccessService — a MESMA economia M.13.1: 1-2 tokens por
    |    janela, performer recebe o crédito fixo de abertura). A performer NÃO
    |    paga para enviar; só o membro paga para LER.
    */

    // Franquia diária de mensagens grátis por performer (reseta à meia-noite).
    // Por-performer, não por par: é a franquia DELA, gasta em qualquer membro.
    'free_messages_per_day' => (int) env('MEMBER_FREE_MESSAGES_PER_DAY', 15),
];
