<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Teaser de mensagem bloqueada
    |--------------------------------------------------------------------------
    |
    | Quantas PALAVRAS do corpo aparecem em claro no gancho (teaser) da mensagem
    | que o membro ainda não pagou para ler. O resto é cortado no SERVIDOR — o
    | membro NUNCA recebe o corpo completo no payload antes de desbloquear o chat
    | (borrar via CSS vazaria no DevTools). Ajustável sem deploy de código.
    |
    | Piso de segurança embutido em App\Support\MessageTeaser (dona única): o
    | teaser nunca revela a mensagem INTEIRA — em mensagens curtas mostra no
    | máximo metade das palavras, independentemente deste número.
    |
    | O corte para no que vier PRIMEIRO: `words` palavras ou `chars` caracteres.
    | "O..."/"co..." (2 caracteres) não desperta curiosidade nenhuma — o teaser
    | existe para levar ao pagamento, então revela ~40 caracteres ou 8 palavras.
    |
    */
    'words' => (int) env('MESSAGE_TEASER_WORDS', 8),
    'chars' => (int) env('MESSAGE_TEASER_CHARS', 40),
];
