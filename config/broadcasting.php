<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    |
    | Sem BROADCAST_CONNECTION no ambiente cai em 'null' (nos testes é o que
    | queremos); o .env de dev/staging usa 'log'. O servidor Reverb ainda NÃO
    | está instalado nem configurado — a conexão 'reverb' abaixo existe para
    | quando BROADCAST_CONNECTION=reverb for ligado no servidor. Ver
    | docs/RETOMADA-CHAT-NOVO.md §5.2 e o cabeçalho de routes/channels.php.
    |
    */

    'default' => env('BROADCAST_CONNECTION', 'null'),

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST'),
                'port' => env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
            ],
            'client_options' => [
                // Guzzle client options: https://docs.guzzlephp.org/en/stable/request-options.html
            ],
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
