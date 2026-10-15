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

    'mobile_legends' => [
        'validation_url' => env('ML_VALIDATION_URL', 'https://api.isan.eu.org/nickname/ml'),
    ],

    'digiflazz' => [
        'username' => env('DIGIFLAZZ_USERNAME'),
        'development_key' => env('DIGIFLAZZ_DEVELOPMENT_KEY'),
        'production_key' => env('DIGIFLAZZ_PRODUCTION_KEY'),
        'key' => env('DIGIFLAZZ_KEY'),
        'production' => env('DIGIFLAZZ_PRODUCTION', false),
        'price_list_url' => env('DIGIFLAZZ_PRICE_LIST_URL', 'https://api.digiflazz.com/v1/price-list'),
    ],

    'pakasir' => [
        'slug' => env('PAKASIR_SLUG'),
        'api_key' => env('PAKASIR_API_KEY'),
        'webhook_secret' => env('PAKASIR_WEBHOOK_SECRET'),
        'sandbox' => env('PAKASIR_SANDBOX', true),
        'base_url' => env('PAKASIR_BASE_URL', 'https://app.pakasir.com'),
        'redirect_url' => env('PAKASIR_REDIRECT_URL', 'https://botakstore.id/cek-order'),
    ],

    'recaptcha' => [
        'site_key' => env('RECAPTCHA_SITE_KEY'),
        'secret_key' => env('RECAPTCHA_SECRET_KEY'),
    ],

];
