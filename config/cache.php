<?php

return [
    'default' => env('CACHE_STORE', 'array'),
    'stores' => [
        'array' => ['driver' => 'array', 'serialize' => false],
        'file' => ['driver' => 'file', 'path' => storage_path('framework/cache/data')],
    ],
    'prefix' => env('CACHE_PREFIX', 'observations_api_audit_cache'),
];
