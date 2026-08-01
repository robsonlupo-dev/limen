<?php

/**
 * Boost pago (Sprint 11) — a performer gasta tokens para destacar o perfil no
 * topo do catálogo por tempo limitado. Dona única das regras:
 * App\Services\BoostService (que lê tudo daqui).
 *
 * Valores por env para staging/prod poderem calibrar preço, duração e escassez
 * sem deploy — a escassez (`max_active`) é o que dá valor ao destaque: se todos
 * pudessem boostar ao mesmo tempo, o "topo" deixaria de ser topo.
 */
return [

    // Custo do boost, em TOKENS (não em reais). A receita é indireta: a performer
    // precisa comprar tokens via PIX para ter o que gastar aqui.
    'cost_tokens' => (int) env('BOOST_COST_TOKENS', 50),

    // Duração do destaque, em horas. É o quanto `boosted_until` fica no futuro a
    // partir do instante do boost. Um boost já ativo não muda se este valor mudar
    // (guardamos o FIM, não o início — ver a migration).
    'duration_hours' => (int) env('BOOST_DURATION_HOURS', 6),

    // Teto de perfis boostados SIMULTANEAMENTE. Impede que o destaque vire o
    // estado normal de todo mundo — é o limite de vagas que o dashboard mostra
    // ("3 de 20 vagas"). Contado sobre os ativos (`boosted_until > now()`).
    'max_active' => (int) env('BOOST_MAX_ACTIVE', 20),

];
