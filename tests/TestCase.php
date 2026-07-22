<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        // Compose injects the local PostgreSQL and application credentials into
        // the app container. Override them before Laravel boots so a test run
        // cannot migrate the development database or use its inbound token.
        $environment = [
            'APP_ENV' => 'testing',
            'APP_KEY' => 'base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=',
            'CACHE_STORE' => 'array',
            'LOG_CHANNEL' => 'null',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'QUEUE_CONNECTION' => 'sync',
            'FAUNE_FRANCE_TOKEN' => 'test-faune-token',
            'BIODIVERSITY_MIN_INTERVAL_MS' => '0',
            'BIODIVERSITY_RETRY_DELAY_MULTIPLIER' => '0',
            'INATURALIST_IMPORT_PAUSE_MS' => '0',
        ];

        foreach ($environment as $name => $value) {
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }

        parent::setUp();
    }
}
