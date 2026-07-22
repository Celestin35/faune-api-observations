<?php

namespace Tests\Feature;

use App\Jobs\ImportObservationsJob;
use App\Models\DataCollection;
use App\Models\ImportJob;
use App\Models\MonitoringRule;
use App\Models\Observation;
use App\Models\Taxon;
use App\Services\Biodiversity\Data\NormalizedOccurrence;
use App\Services\Biodiversity\OccurrencePersister;
use App\Services\Biodiversity\PersistOutcome;
use App\Services\Biodiversity\SearchDefinitionFactory;
use App\Services\Biodiversity\SearchQueryFactory;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class V0ApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('biodiversity.min_interval_ms', 0);
        config()->set('biodiversity.retry_delay_multiplier', 0);
    }

    #[Test]
    public function migrations_create_the_v0_schema_and_declare_postgis(): void
    {
        foreach (['taxa', 'observations', 'observation_sources', 'geographic_areas', 'monitoring_rules', 'data_collections', 'collection_coverages', 'import_jobs', 'source_sync_states'] as $table) {
            self::assertTrue(Schema::hasTable($table), $table);
        }
        $migration = file_get_contents(database_path('migrations/2026_07_21_000001_create_observations_v0_tables.php'));
        self::assertStringContainsString('CREATE EXTENSION IF NOT EXISTS postgis', $migration);
        self::assertStringContainsString('USING GIST (geometry)', $migration);
    }

    #[Test]
    public function taxon_search_combines_gbif_and_inaturalist_resolution(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'api.gbif.org')) {
                return Http::response(['results' => [
                    ['key' => 2484918, 'canonicalName' => 'Tichodroma muraria',
                        'rank' => 'SPECIES', 'kingdom' => 'Animalia', 'species' => 'Tichodroma muraria'],
                    ['key' => 9999999, 'canonicalName' => 'Tichodroma muraria',
                        'rank' => 'SPECIES', 'kingdom' => 'Animalia', 'species' => 'Tichodroma muraria'],
                ]]);
            }

            return Http::response(['results' => [['id' => 14840, 'name' => 'Tichodroma muraria', 'rank' => 'species',
                'preferred_common_name' => 'Wallcreeper']]]);
        });
        $this->getJson('/api/taxa/search?q=Tichodroma%20muraria')->assertOk()
            ->assertJsonPath('data.0.scientific_name', 'Tichodroma muraria')
            ->assertJsonCount(2, 'data.0.mappings');
        self::assertSame(1, Taxon::where('scientific_name', 'Tichodroma muraria')->first()->mappings()->where('source', 'gbif')->count());
    }

    #[Test]
    public function estimates_gbif_inaturalist_overlap_and_local_count(): void
    {
        $taxon = Taxon::create(['scientific_name' => 'Tichodroma muraria', 'rank' => 'species']);
        $this->persist('faune-france', 'local-1');
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/species/match')) {
                return Http::response(['usageKey' => 2484918]);
            }
            if (str_contains($request->url(), 'api.gbif.org')) {
                return Http::response(['count' => ($request['datasetKey'] ?? null) ? 3 : 8, 'results' => []]);
            }

            return Http::response(['total_results' => 10, 'results' => []]);
        });
        $this->postJson('/api/searches/estimate', $this->searchPayload($taxon->id))->assertOk()
            ->assertJsonPath('local.count', 1)->assertJsonPath('external.gbif', 8)
            ->assertJsonPath('external.inaturalist', 10)->assertJsonPath('overlap.inaturalist_in_gbif', 3)
            ->assertJsonPath('overlap.estimated_inaturalist_missing_from_gbif', 7);
    }

    #[Test]
    public function builds_radius_and_multiple_department_queries(): void
    {
        $this->seed(DatabaseSeeder::class);
        $factory = app(SearchDefinitionFactory::class);
        $queries = app(SearchQueryFactory::class);
        $radius = $factory->make($this->searchPayload(Taxon::where('scientific_name', 'Tichodroma muraria')->value('id')));
        self::assertSame(30.0, $queries->forSource($radius, 'gbif')[0]->radiusKm);
        $department = $factory->make($this->searchPayload($radius->taxon->id, ['type' => 'departments', 'department_codes' => ['09', '64', '65']]));
        $gbif = $queries->forSource($department, 'gbif');
        $inat = $queries->forSource($department, 'inaturalist');
        self::assertCount(3, $gbif);
        self::assertSame('FRA.11.1_1', $gbif[0]->department);
        self::assertSame('30195', $inat[0]->department);
    }

    #[Test]
    public function imports_gbif_pages_with_a_hard_limit(): void
    {
        $taxon = Taxon::create(['scientific_name' => 'Tichodroma muraria', 'rank' => 'species']);
        $job = $this->importRecord($taxon, 'gbif', 301);
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/species/match')) {
                return Http::response(['usageKey' => 2484918]);
            }
            $records = (int) ($request['offset'] ?? 0) === 0
                ? array_map(fn (int $id): array => $this->gbifRecord($id), range(1, 300))
                : [$this->gbifRecord(301)];

            return Http::response(['count' => 301, 'results' => $records]);
        });
        app()->call([new ImportObservationsJob($job->id), 'handle']);
        self::assertSame(301, $job->fresh()->processed_count);
        self::assertSame('completed', $job->fresh()->status);
        self::assertSame(301, Observation::count());
        Http::assertSent(fn (Request $request): bool => ($request['offset'] ?? null) === 300);
    }

    #[Test]
    public function imports_inaturalist_with_a_hard_limit(): void
    {
        $taxon = Taxon::create(['scientific_name' => 'Tichodroma muraria', 'rank' => 'species']);
        $job = $this->importRecord($taxon, 'inaturalist', 1);
        Http::fake(['api.inaturalist.org/*' => Http::response(['total_results' => 4, 'results' => [$this->inatRecord(11)]])]);
        app()->call([new ImportObservationsJob($job->id), 'handle']);
        self::assertSame(1, $job->fresh()->processed_count);
        self::assertSame('partial', $job->fresh()->status);
    }

    #[Test]
    public function import_is_idempotent_and_one_observation_can_have_multiple_sources(): void
    {
        $first = $this->persist('inaturalist', '777', 'https://www.inaturalist.org/observations/777');
        $second = $this->persist('inaturalist', '777', 'https://www.inaturalist.org/observations/777');
        $third = $this->persist('gbif', 'gbif-777', 'https://www.inaturalist.org/observations/777');
        self::assertSame('created', $first->status);
        self::assertSame('unchanged', $second->status);
        self::assertSame('updated', $third->status);
        self::assertSame(1, Observation::count());
        self::assertSame(2, Observation::first()->sources()->count());
    }

    #[Test]
    public function creates_a_permanent_collection_and_a_monitoring_then_syncs_it(): void
    {
        Queue::fake();
        $taxon = Taxon::create(['scientific_name' => 'Animalia', 'rank' => 'kingdom']);
        $payload = $this->searchPayload($taxon->id) + ['name' => 'Collection durable', 'is_permanent' => true];
        $this->postJson('/api/collections', $payload)->assertCreated()->assertJsonPath('data.is_permanent', true);
        $monitor = $this->searchPayload($taxon->id) + ['name' => 'Veille test', 'window_minutes' => 1440, 'frequency_minutes' => 30];
        $id = $this->postJson('/api/monitoring', $monitor)->assertCreated()->json('data.id');
        $this->postJson("/api/monitoring/{$id}/sync")->assertAccepted();
        Queue::assertPushed(ImportObservationsJob::class, 2);
        self::assertSame(1, MonitoringRule::count());
    }

    #[Test]
    public function cleanup_dry_run_changes_nothing_and_protects_historical_or_permanent_data(): void
    {
        $old = $this->persist('faune-france', 'expired')->observation;
        $old->update(['first_imported_at' => now()->subYears(2)]);
        $protected = $this->persist('faune-france', 'permanent')->observation;
        $protected->update(['first_imported_at' => now()->subYears(2)]);
        $collection = DataCollection::create(['name' => 'Permanent', 'zone_type' => 'radius', 'zone_data' => ['type' => 'radius'], 'zone_hash' => 'x', 'sources' => [], 'is_permanent' => true]);
        $protected->collections()->attach($collection, ['attached_at' => now()]);
        $historical = $this->persist('faune-france', 'historical', null, '2010-01-01T12:00:00Z')->observation;
        $this->artisan('biodiversity:cleanup --dry-run')->expectsOutput('1 observation(s) seraient supprimées.')->assertSuccessful();
        self::assertSame(3, Observation::count());
        $this->artisan('biodiversity:cleanup')->assertSuccessful();
        self::assertSame(2, Observation::count());
        self::assertTrue($protected->fresh()->exists);
        self::assertTrue($historical->fresh()->exists);
    }

    private function searchPayload(int $taxonId, ?array $zone = null): array
    {
        return ['taxon_id' => $taxonId, 'date_from' => '2026-01-01', 'date_to' => '2026-12-31', 'sources' => ['gbif', 'inaturalist'],
            'zone' => $zone ?? ['type' => 'radius', 'latitude' => 48.1173, 'longitude' => -1.6778, 'radius_km' => 30]];
    }

    private function persist(string $source, string $id, ?string $url = null, string $date = '2026-07-01T12:00:00Z'): PersistOutcome
    {
        return app(OccurrencePersister::class)->persist(new NormalizedOccurrence($source, $id, null, 'Tichodroma muraria', null, null,
            ['kingdom' => 'Animalia', 'species' => 'Tichodroma muraria'], $date, null, null, null, 48.1173, -1.6778, 20, 1, 'research', 'Test', 'CC-BY', $url, [], ['id' => $id]));
    }

    private function importRecord(Taxon $taxon, string $source, int $limit): ImportJob
    {
        return ImportJob::create(['source' => $source, 'taxon_id' => $taxon->id, 'date_from' => '2026-01-01', 'date_to' => '2026-12-31',
            'zone_type' => 'radius', 'zone_data' => ['type' => 'radius', 'latitude' => 48.1, 'longitude' => -1.6, 'radius_km' => 30],
            'zone_hash' => 'test', 'status' => 'pending', 'limit' => $limit]);
    }

    private function gbifRecord(int $id): array
    {
        return ['key' => $id, 'occurrenceID' => (string) $id, 'scientificName' => 'Tichodroma muraria', 'taxonKey' => 2484918,
            'eventDate' => '2026-07-01', 'decimalLatitude' => 48.1, 'decimalLongitude' => -1.6];
    }

    private function inatRecord(int $id): array
    {
        return ['id' => $id, 'observed_on' => '2026-07-01', 'taxon' => ['id' => 14840, 'name' => 'Tichodroma muraria', 'rank' => 'species'],
            'geojson' => ['coordinates' => [-1.6, 48.1]], 'uri' => "https://www.inaturalist.org/observations/{$id}"];
    }
}
