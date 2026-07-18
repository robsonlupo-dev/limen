<?php

return [
    'provider'       => env('KYC_PROVIDER', 'fake'),
    'client_id'      => env('KYC_CLIENT_ID', ''),
    'client_secret'  => env('KYC_CLIENT_SECRET', ''),
    'workflow_id'    => env('KYC_WORKFLOW_ID', ''),
    'webhook_secret' => env('KYC_WEBHOOK_SECRET', ''),
    'base_url'       => env('KYC_BASE_URL', 'https://apx.didit.me'),
    'auth_url'       => env('KYC_AUTH_URL', 'https://apx.didit.me/auth/v2/oauth2/token'),
];
