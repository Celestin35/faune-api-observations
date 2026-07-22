<?php

namespace Tests\Unit;

use App\Services\Biodiversity\DeduplicationHints;
use App\Services\Biodiversity\Sources\GbifConnector;
use App\Services\Biodiversity\Sources\INaturalistConnector;
use App\Services\Biodiversity\Sources\ObisConnector;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ConnectorNormalizationTest extends TestCase
{
    #[Test]
    public function it_normalizes_gbif_and_keeps_media_as_links(): void
    {
        $record = $this->fixture('gbif')['results'][0];
        $occurrence = app(GbifConnector::class)->normalize($record);

        self::assertSame('https://www.inaturalist.org/observations/334329012', $occurrence->sourceOccurrenceId);
        self::assertSame('Tichodromidae', $occurrence->classification['family']);
        self::assertSame(4.0, $occurrence->coordinateUncertaintyM);
        self::assertSame('https://example.test/photo.jpg', $occurrence->media[0]['url']);
    }

    #[Test]
    public function it_normalizes_inaturalist_coordinates_taxonomy_and_photos(): void
    {
        $record = $this->fixture('inaturalist')['results'][0];
        $occurrence = app(INaturalistConnector::class)->normalize($record);

        self::assertSame('334329012', $occurrence->sourceOccurrenceId);
        self::assertSame(43.831119, $occurrence->latitude);
        self::assertSame(3.309553, $occurrence->longitude);
        self::assertSame('research', $occurrence->validationStatus);
        self::assertSame('https://example.test/square.jpg', $occurrence->media[0]['url']);
    }

    #[Test]
    public function it_normalizes_obis_and_aphia_id(): void
    {
        $record = $this->fixture('obis')['results'][0];
        $occurrence = app(ObisConnector::class)->normalize($record);

        self::assertSame('1406_14803', $occurrence->sourceOccurrenceId);
        self::assertSame('137094', $occurrence->sourceTaxonId);
        self::assertSame('Mammalia', $occurrence->classification['class']);
    }

    #[Test]
    public function it_exposes_the_same_inaturalist_hint_in_inaturalist_and_gbif(): void
    {
        $gbif = app(GbifConnector::class)->normalize($this->fixture('gbif')['results'][0]);
        $inaturalist = app(INaturalistConnector::class)->normalize($this->fixture('inaturalist')['results'][0]);
        $builder = new DeduplicationHints;

        self::assertContains('inaturalist:334329012', $builder->for($gbif));
        self::assertContains('inaturalist:334329012', $builder->for($inaturalist));
    }

    /** @return array<string, mixed> */
    private function fixture(string $name): array
    {
        return json_decode(
            file_get_contents(base_path("tests/Fixtures/Biodiversity/{$name}.json")),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }
}
