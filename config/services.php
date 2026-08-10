<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
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

    'proxy' => [
        'shared_secret' => env('PROXY_SHARED_SECRET'),
        'shared_secret_prev' => env('PROXY_SHARED_SECRET_PREV'),
    ],

    'glitchtip' => [
        'url'        => env('GLITCHTIP_URL', 'http://glitchtip-web:8000'),
        'public_url' => env('GLITCHTIP_PUBLIC_URL', 'https://sentry.ypsilon.dev'),
        'token'      => env('GLITCHTIP_API_TOKEN', ''),
        'org_slug'   => env('GLITCHTIP_ORG_SLUG', 'default'),
    ],

    'coolify' => [
        'instance_url' => env('COOLIFY_INSTANCE_URL', ''),
        'base_url'     => env('COOLIFY_INSTANCE_URL', env('COOLIFY_API_BASE', '')),
        'api_token'    => env('COOLIFY_API_TOKEN', ''),
        'app_uuid'     => env('COOLIFY_APP_UUID', ''),
        'proxy_app_uuid' => env('COOLIFY_PROXY_APP_UUID', ''),
        'ssh_host'     => env('SSH_HOST'),       // Docker host IP for server cleanup (auto-detected if null)
    ],

    'scanner' => [
        'scheduled_set_chunk_size' => (int) env('SCANNER_SCHEDULED_SET_CHUNK_SIZE', 10),
        'scheduled_inter_request_delay_ms' => (int) env('SCANNER_SCHEDULED_INTER_REQUEST_DELAY_MS', 250),
        'scheduled_deep_scan_enabled' => env('SCANNER_SCHEDULED_DEEP_SCAN_ENABLED', false),
        'target_set_count' => (int) env('SCANNER_TARGET_SET_COUNT', 100),
        'min_set_size' => (int) env('SCANNER_MIN_SET_SIZE', 15),
        'max_set_size' => (int) env('SCANNER_MAX_SET_SIZE', 50),
    ],

];
