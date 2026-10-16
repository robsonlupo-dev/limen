<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Selo "Teste" (disciplina de UAT)
    |--------------------------------------------------------------------------
    |
    | O dev e a produção são a MESMA instância (ver CLAUDE.md), então a massa
    | sintética de UAT convive com o catálogo real. Para o testador distinguir
    | à primeira vista o que é conta de teste, as contas de teste recebem um
    | selo verde "Teste" no card e no perfil.
    |
    | É DERIVADO do e-mail — nenhuma coluna nova. Some sozinho no go-live: conta
    | real tem domínio real e não casa com nada aqui. O interruptor global
    | permite apagá-lo de uma vez no go-live sem mexer em código.
    |
    */

    'test_badge_enabled' => (bool) env('TEST_ACCOUNT_BADGE_ENABLED', true),

    // Domínios reservados dos seeders (LimenTestSeeder → @teste.limen.local,
    // UatSeeder → @uat.limen.test). E-mail nesses domínios nunca é de pessoa real.
    'test_domains' => [
        'uat.limen.test',
        'teste.limen.local',
        'limen.test',
        'limen.local',
    ],

    // E-mails avulsos marcados como teste (contas criadas à mão no UAT, fora dos
    // seeders). Lista separada por vírgula no .env; some no go-live com o resto.
    'test_emails' => array_values(array_filter(array_map(
        fn ($e) => mb_strtolower(trim($e)),
        explode(',', (string) env('TEST_ACCOUNT_EMAILS', '')),
    ))),

];
