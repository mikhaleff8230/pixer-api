<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'https://sancan.ru',
        'https://www.sancan.ru',
        'https://admin.sancan.ru',
        'https://seller.sancan.ru',
        'https://api.sancan.ru',
        'http://localhost:*',
        'http://127.0.0.1:*',
        'exp://*',
        'http://10.0.2.2:*',
    ],

    'allowed_origins_patterns' => [
        '/^https?:\/\/.*\.sancan\.ru$/',
        '/^chrome-extension:\/\/[a-p]{32}$/',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 86400,

    'supports_credentials' => true,

];
