<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Public media / CDN base URL
    |--------------------------------------------------------------------------
    |
    | Used for public image links. Prefer CDN (e.g. https://img.sancan.ru).
    | Do not store this domain in the database — only relative keys or
    | regenerate URLs at read time via this value.
    |
    */
    'url' => env('MEDIA_URL', env('ASSETS_BASE_URL', env('AWS_URL'))),

    /*
    |--------------------------------------------------------------------------
    | Legacy S3 host (old bucket URLs still present in DB)
    |--------------------------------------------------------------------------
    */
    'legacy_hosts' => [
        's3.twcstorage.ru',
    ],

];
