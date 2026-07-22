<?php

return [
    'user_agent' => env('BIODIVERSITY_USER_AGENT', 'observations-api-audit/0.1 (local technical audit)'),
    'timeout_seconds' => (int) env('BIODIVERSITY_TIMEOUT_SECONDS', 15),
    'min_interval_ms' => (int) env('BIODIVERSITY_MIN_INTERVAL_MS', 500),
    'inaturalist_import_pause_ms' => (int) env('INATURALIST_IMPORT_PAUSE_MS', 1000),
    'retry_delay_multiplier' => (int) env('BIODIVERSITY_RETRY_DELAY_MULTIPLIER', 1),
    'import_limit_per_source' => min((int) env('BIODIVERSITY_IMPORT_LIMIT', 10000), 10000),
    'faune_france_token' => env('FAUNE_FRANCE_TOKEN'),
    'retention_days' => (int) env('BIODIVERSITY_RETENTION_DAYS', 365),
    'inaturalist_gbif_dataset_key' => env('INATURALIST_GBIF_DATASET_KEY', '50c9509d-22c7-4a22-a47d-8c48425ef4a7'),
    'sources' => [
        'gbif' => ['base_url' => 'https://api.gbif.org/v1'],
        'inaturalist' => ['base_url' => 'https://api.inaturalist.org/v1'],
        'obis' => ['base_url' => 'https://api.obis.org/v3', 'enabled' => (bool) env('OBIS_ENABLED', false)],
        'ebird' => ['base_url' => 'https://api.ebird.org/v2', 'api_key' => env('EBIRD_API_KEY')],
        'taxref' => ['base_url' => 'https://taxref.mnhn.fr/api'],
        'geonature' => ['base_url' => env('GEONATURE_BASE_URL')],
    ],
];
