<?php

return [
    // Which Asaas client to use: 'http' (real API) or 'fake' (in-memory stub).
    // Dev/staging default to 'fake' (synthetic data — see CLAUDE.md); production
    // sets 'http' with real credentials. Tests always use the fake regardless.
    'driver' => env('ASAAS_DRIVER', 'http'),

    'env' => env('ASAAS_ENV', 'sandbox'),
    'base_url' => env('ASAAS_BASE_URL', 'https://sandbox.asaas.com/api/v3'),
    'api_key' => env('ASAAS_API_KEY', ''),
    'webhook_token' => env('ASAAS_WEBHOOK_TOKEN', ''),
];
