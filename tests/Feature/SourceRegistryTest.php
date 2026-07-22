<?php

namespace Tests\Feature;

use App\Services\Biodiversity\SourceRegistry;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SourceRegistryTest extends TestCase
{
    #[Test]
    public function it_does_not_expose_unverified_connectors(): void
    {
        $registry = app(SourceRegistry::class);

        self::assertNull($registry->connector('taxref'));
        self::assertNull($registry->connector('ebird'));
        self::assertNull($registry->connector('geonature'));
        self::assertNotNull($registry->connector('gbif'));
    }
}
