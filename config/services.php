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

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'coingecko' => [
        'proxy_url' => env('CG_PROXY_URL'),
        'proxy_key' => env('CG_PROXY_KEY'),
    ],

    'balance_alert' => [
        'auto_notify_enabled' => env('BALANCE_ALERT_AUTO_NOTIFY_ENABLED', false),
        'auto_notify_webhook_url' => env('BALANCE_ALERT_AUTO_NOTIFY_WEBHOOK_URL'),
        'auto_notify_prepare_threshold' => env('BALANCE_ALERT_AUTO_NOTIFY_PREPARE_THRESHOLD', 3),
        'auto_notify_rebalance_threshold' => env('BALANCE_ALERT_AUTO_NOTIFY_REBALANCE_THRESHOLD', 5),
        'auto_notify_force_threshold' => env('BALANCE_ALERT_AUTO_NOTIFY_FORCE_THRESHOLD', 7.5),
        'prepare_threshold' => env('BALANCE_ALERT_PREPARE_THRESHOLD', 3),
        'rebalance_threshold' => env('BALANCE_ALERT_REBALANCE_THRESHOLD', 5),
        'force_threshold' => env('BALANCE_ALERT_FORCE_THRESHOLD', 7.5),
    ],

];
