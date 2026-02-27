<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Price Feed Provider
    |--------------------------------------------------------------------------
    |
    | Supported values:
    | - cache
    | - exchange_rate_api
    | - cache_then_exchange_rate_api
    | - exchange_rate_api_then_cache
    |
    */
    'provider' => env('PRICE_FEED_PROVIDER', 'cache_then_exchange_rate_api'),

    'exchange_rate_api' => [
        'base_url' => env('PRICE_FEED_EXCHANGE_RATE_API_BASE_URL', 'https://open.er-api.com/v6/latest'),
        'api_key' => env('PRICE_FEED_EXCHANGE_RATE_API_KEY'),
        'request_timeout_seconds' => (float) env('PRICE_FEED_EXCHANGE_RATE_API_TIMEOUT_SECONDS', 2.0),
        'base_cache_ttl_seconds' => (int) env('PRICE_FEED_EXCHANGE_RATE_API_BASE_CACHE_TTL_SECONDS', 30),
        'synthetic_spread_bps' => (float) env('PRICE_FEED_SYNTHETIC_SPREAD_BPS', 1.0),
    ],
];
