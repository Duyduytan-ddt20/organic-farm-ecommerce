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

    'vnpay' => [
        'tmn_code' => env('VNP_TMN_CODE'),
        'hash_secret' => env('VNP_HASH_SECRET'),
        'url' => env('VNP_URL', 'https://sandbox.vnpayment.vn/paymentv2/vpcpay.html'),
        'return_url' => env('VNP_RETURN_URL')
            ?: rtrim(env('FRONTEND_URL', 'http://localhost:5173'), '/').'/thanh-toan/ket-qua',
    ],

    'momo' => [
        'partner_code' => env('MOMO_PARTNER_CODE'),
        'access_key' => env('MOMO_ACCESS_KEY'),
        'secret_key' => env('MOMO_SECRET_KEY'),
        'url' => env('MOMO_URL', 'https://test-payment.momo.vn/v2/gateway/api/create'),
        'ipn_url' => env('MOMO_IPN_URL')
            ?: rtrim(env('APP_URL', 'http://localhost'), '/').'/api/v1/payments/momo-ipn',
        'return_url' => env('MOMO_RETURN_URL')
            ?: rtrim(env('FRONTEND_URL', 'http://localhost:5173'), '/').'/thanh-toan/ket-qua',
    ],

    'shipping' => [
        'external_enabled' => env('SHIPPING_EXTERNAL_SERVICES_ENABLED', false),
        'provider' => env('SHIPPING_PROVIDER', 'nominatim'),
        'routing_provider' => env('SHIPPING_ROUTING_PROVIDER', 'osrm'),
        'farm_latitude' => env('SHIPPING_FARM_LATITUDE', 10.8278542),
        'farm_longitude' => env('SHIPPING_FARM_LONGITUDE', 106.721396),
        'rate_per_km' => env('SHIPPING_RATE_PER_KM', 5000),
        'geocode_cache_ttl' => env('SHIPPING_GEOCODE_CACHE_TTL', 86400),
        'quote_ttl' => env('SHIPPING_QUOTE_TTL', 600),
        'connect_timeout' => env('SHIPPING_HTTP_CONNECT_TIMEOUT', 5),
        'timeout' => env('SHIPPING_HTTP_TIMEOUT', 10),
    ],

    'nominatim' => [
        'base_url' => env('NOMINATIM_BASE_URL', 'https://nominatim.openstreetmap.org'),
        'user_agent' => env('NOMINATIM_USER_AGENT', 'OrganicFarmEcommerce/1.0'),
    ],

    'osrm' => [
        'base_url' => env('OSRM_BASE_URL', 'https://router.project-osrm.org'),
    ],

];