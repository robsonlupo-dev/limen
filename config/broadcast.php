<?php

/**
 * Canal de transmissão da performer (roadmap social, Onda 2). NÃO confundir com
 * config/broadcasting.php (o driver de eventos do Laravel/Reverb) — este é a
 * configuração do RECURSO de canal (limites de negócio).
 *
 * Não mexe em token (recurso social/retenção). Limites aqui são a fonte única
 * (o service e o Form Request leem daqui).
 */
return [
    // Tamanho máximo do texto de um broadcast.
    'max_length' => (int) env('BROADCAST_MAX_LENGTH', 1000),

    // Teto de negócio: quantos broadcasts a performer pode publicar por janela de
    // 24h. Protege o servidor (2 vCPU) e evita spam ao seguidor. O throttle de rota
    // cobre o burst; este é o teto diário, conferido no service.
    'max_per_day' => (int) env('BROADCAST_MAX_PER_DAY', 10),

    // Quantos broadcasts a aba "Canais" do membro monta numa passada (teto de
    // segurança, não paginação — a v1 mostra os mais recentes).
    'feed_limit' => (int) env('BROADCAST_FEED_LIMIT', 100),
];
