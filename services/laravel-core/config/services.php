<?php
return [
    'postmark' => ['key' => env('POSTMARK_API_KEY')],
    'resend' => ['key' => env('RESEND_API_KEY')],
    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],
    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    // ─── OmniTix internal services ──────────────────────
    'inventory' => ['http_url' => env('INVENTORY_HTTP_URL', 'http://go-inventory.omnitix.svc.cluster.local:8080')],
    'crypto' => ['http_url' => env('CRYPTO_HTTP_URL', 'http://rust-crypto.omnitix.svc.cluster.local:50054')],
    'ledger' => ['http_url' => env('LEDGER_GRPC_HTTP_URL', 'http://kotlin-ledger.omnitix.svc.cluster.local:8082')],
];
