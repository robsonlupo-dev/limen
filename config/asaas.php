<?php

return [
    // Which Asaas client to use: 'http' (real API) or 'fake' (in-memory stub).
    // Defaults to 'http' so an env that forgets the flag never issues fake,
    // unpayable charges. Dev sets ASAAS_DRIVER=fake explicitly (synthetic data —
    // see CLAUDE.md); staging/prod set 'http' with real credentials. Tests always
    // use the fake regardless of this value (see AppServiceProvider).
    'driver' => env('ASAAS_DRIVER', 'http'),

    'env' => env('ASAAS_ENV', 'sandbox'),
    'base_url' => env('ASAAS_BASE_URL', 'https://sandbox.asaas.com/api/v3'),
    'api_key' => env('ASAAS_API_KEY', ''),
    'webhook_token' => env('ASAAS_WEBHOOK_TOKEN', ''),
];
