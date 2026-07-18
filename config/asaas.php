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

    // Optional source-IP allowlist for Asaas webhooks (defense in depth on top of
    // the asaas-access-token check). Off by default so staging/sandbox — where
    // Asaas may post from IPs outside the published production set — never breaks.
    // Turn on only in production. Sandbox uses extra IPs; keep this false there.
    'webhook_ip_allowlist_enabled' => env('ASAAS_WEBHOOK_IP_ALLOWLIST', false),

    // Official Asaas PRODUCTION webhook source IPs. Verify against the live list:
    // https://docs.asaas.com/docs/official-asaas-ips
    // Override via ASAAS_WEBHOOK_ALLOWED_IPS (comma-separated IPs and/or CIDRs).
    'webhook_allowed_ips' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'ASAAS_WEBHOOK_ALLOWED_IPS',
            '52.67.12.206,18.230.8.159,54.94.136.112,54.94.183.101',
        )),
    ))),
];
