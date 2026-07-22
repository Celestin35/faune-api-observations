<?php

namespace Tests\Feature;

use App\Services\Biodiversity\Data\OccurrenceQuery;
use App\Services\Biodiversity\Sources\GbifConnector;
use App\Services\Biodiversity\Sources\INaturalistConnector;
use App\Services\Biodiversity\Sources\ObisConnector;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ConnectorHttpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('biodiversity.min_interval_ms', 0);
        config()->set('biodiversity.retry_delay_multiplier', 0);
    }

    #[Test]
    public function gbif_count_uses_zero_results_and_a_wkt_radius(): void
    {
        Http::fake(fn (Request $request) => str_contains($request->url(), '/species/match')
            ? Http::response(['usageKey' => 2484918])
            : Http::response(['count' => 42, 'results' => []]));

        $count = app(GbifConnector::class)->count(new OccurrenceQuery(
            taxon: 'Tichodroma muraria', latitude: 45, longitude: 6, radiusKm: 10,
        ));

        self::assertSame(42, $count);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/occurrence/search')
            && $request['limit'] === 0
            && str_starts_with((string) $request['geometry'], 'POLYGON((')
            && $request['taxonKey'] === 2484918
            && $request->hasHeader('User-Agent')
        );
    }

    #[Test]
    public function inaturalist_uses_native_bbox_dates_and_france_place(): void
    {
        Http::fake(['api.inaturalist.org/*' => Http::response(['total_results' => 7, 'results' => []])]);

        app(INaturalistConnector::class)->count(new OccurrenceQuery(
            taxon: 'Tichodroma', from: '2026-07-01', to: '2026-07-20', country: 'FR',
            boundingBox: ['south' => 41.0, 'west' => -5.3, 'north' => 51.2, 'east' => 9.7],
        ));

        Http::assertSent(fn (Request $request): bool => $request['taxon_name'] === 'Tichodroma'
            && $request['d1'] === '2026-07-01'
            && $request['place_id'] === '6753'
            && (float) $request['swlat'] === 41.0
            && $request['per_page'] === 1
        );
    }

    #[Test]
    public function obis_retries_a_retriable_status_then_returns_total(): void
    {
        Http::fakeSequence('api.obis.org/*')
            ->push(['message' => 'temporary'], 503)
            ->push(['total' => 19615, 'results' => []], 200);

        $count = app(ObisConnector::class)->count(new OccurrenceQuery(taxon: 'Delphinus delphis'));

        self::assertSame(19615, $count);
        Http::assertSentCount(2);
    }
}
