<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Determine which cross-origin operations may execute in web browsers.
    | This is required when the frontend and API are served from different
    | origins (for example, Vercel frontend + separate API host).
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => [
        'GET',
        'POST',
        'PUT',
        'PATCH',
        'DELETE',
        'OPTIONS',
    ],

    'allowed_origins' => array_filter(array_map(
        static fn (string $origin) => rtrim(trim($origin), '/'),
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
    )),

    'allowed_origins_patterns' => array_filter(array_map(
        static fn (string $pattern) => trim($pattern),
        explode(',', (string) env('CORS_ALLOWED_ORIGIN_PATTERNS', ''))
    )),

    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'Origin',
        'X-Requested-With',
        'X-CSRF-TOKEN',
        'X-XSRF-TOKEN',
    ],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => filter_var(
        env('CORS_SUPPORTS_CREDENTIALS', true),
        FILTER_VALIDATE_BOOL
    ),

];
