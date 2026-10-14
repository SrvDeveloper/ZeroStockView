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

    'amazon_sp_api' => [
        'endpoint' => env('SP_API_ENDPOINT', 'https://sellingpartnerapi-fe.amazon.com'),
        'marketplace_id' => env('SP_API_MARKETPLACE_ID', 'A1VC38T7YXB528'),
        'client_id' => env('SP_API_CLIENT_ID'),
        'client_secret' => env('SP_API_CLIENT_SECRET'),
        'refresh_token' => env('SP_API_REFRESH_TOKEN'),
        'timeout' => env('SP_API_TIMEOUT', 30),
        'poll_attempts' => env('SP_API_POLL_ATTEMPTS', 40),
        'poll_interval_seconds' => env('SP_API_POLL_INTERVAL_SECONDS', 15),
        'retry_attempts' => env('SP_API_RETRY_ATTEMPTS', 3),
        'retry_delay_seconds' => env('SP_API_RETRY_DELAY_SECONDS', 2),
    ],

    'crosswalker' => [
        'base_url' => env('CROSSWALKER_API_URL') ?: env('CROSSWALKER_BASE_URL') ?: env('API_URL'),
        'api_key' => env('CROSSWALKER_API_KEY') ?: env('CROSSWALKER_TOKEN') ?: env('API_KEY'),
        'timeout' => env('CROSSWALKER_TIMEOUT', 10),
    ],

];
