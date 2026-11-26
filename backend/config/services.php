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

    'shipping' => [
        'external_enabled' => env('SHIPPING_EXTERNAL_SERVICES_ENABLED', false),
        'provider' => env('SHIPPING_PROVIDER', 'nominatim'),
        'farm_latitude' => env('SHIPPING_FARM_LATITUDE', 10.77197),
        'farm_longitude' => env('SHIPPING_FARM_LONGITUDE', 106.70135),
        'rate_per_km' => env('SHIPPING_RATE_PER_KM', 5000),
        'geocode_cache_ttl' => env('SHIPPING_GEOCODE_CACHE_TTL', 86400),
        'quote_ttl' => env('SHIPPING_QUOTE_TTL', 600),
        'connect_timeout' => env('SHIPPING_HTTP_CONNECT_TIMEOUT', 3),
        'timeout' => env('SHIPPING_HTTP_TIMEOUT', 8),
    ],

    'nominatim' => [
        'base_url' => env('NOMINATIM_BASE_URL', 'https://nominatim.openstreetmap.org'),
        'user_agent' => env('NOMINATIM_USER_AGENT', 'OrganicFarmEcommerce/1.0'),
    ],

    'osrm' => [
        'base_url' => env('OSRM_BASE_URL', 'https://router.project-osrm.org'),
    ],

];
