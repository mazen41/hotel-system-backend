<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Configured to allow requests from the Next.js frontend.
    | Update FRONTEND_URL in .env for production.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'storage/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_filter([
        env('FRONTEND_URL', 'http://localhost:3000'),
        env('CORS_ALLOWED_ORIGINS'),
        'http://localhost:3000',
        'http://localhost:3001',
        'http://192.168.1.120:3001',
        'https://hotel-system-ten-roan.vercel.app',
        'https://hotel-sys.loop-pr.com',
    ]),

    'allowed_origins_patterns' => [
        '/https:\/\/hotel-system-ten-roan\.vercel\.app/',
        '/https:\/\/.*\.vercel\.app/',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 86400,

    'supports_credentials' => true,

];
