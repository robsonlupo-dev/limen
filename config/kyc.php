<?php

return [
    'provider'       => env('KYC_PROVIDER', 'fake'),
    'api_key'        => env('KYC_API_KEY', ''),
    'workflow_id'    => env('KYC_WORKFLOW_ID', ''),
    'webhook_secret' => env('KYC_WEBHOOK_SECRET', ''),
    'base_url'       => env('KYC_BASE_URL', 'https://verification.didit.me'),
];
