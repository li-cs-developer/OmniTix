<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

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

    /*
    |--------------------------------------------------------------------------
    | OmniTix — internal service URLs
    |--------------------------------------------------------------------------
    |
    | These are read via `config('services.<name>.<key>')` from controllers
    | and services, NOT via `env()` — because once `php artisan config:cache`
    | has been run, `env()` returns `null` outside of the config files.
    |
    | Defaults target host-side dev where the sibling services run on
    | 127.0.0.1 (or host.docker.internal from inside a container). Inside
    | Kubernetes, the ConfigMap overrides these with the cluster-internal
    | DNS names (e.g. `http://go-inventory.omnitix.svc.cluster.local:8080`).
    |
    */

    'inventory' => [
        'http_url'  => env('INVENTORY_HTTP_URL', 'http://127.0.0.1:8080'),
        'grpc_host' => env('INVENTORY_GRPC_HOST', '127.0.0.1'),
        'grpc_port' => env('INVENTORY_GRPC_PORT', '50051'),
    ],

    'crypto' => [
        'http_url' => env('CRYPTO_HTTP_URL', 'http://127.0.0.1:50054'),
    ],

    'ledger' => [
        'http_url' => env('LEDGER_GRPC_HTTP_URL', 'http://127.0.0.1:8082'),
    ],

];