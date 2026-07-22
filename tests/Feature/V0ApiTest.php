<?php

namespace Tests\Feature;

use App\Jobs\ImportObservationsJob;
use App\Models\DataCollection;
use App\Models\ExternalFetchJob;
use App\Models\GeographicArea;
use App\Models\ImportJob;
use App\Models\MonitoringRule;
use App\Models\Observation;
use App\Models\Taxon;
use App\Models\TaxonName;
use App\Models\TaxonomicReferenceVersion;
use App\Models\TaxrefRecord;
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
        self::assertTrue(Schema::hasColumns('geographic_areas', ['region_name', 'is_overseas', 'faune_portal']));
    }

    #[Test]
    public function taxon_search_is_local_side_effect_free_and_uses_the_stable_contract(): void
    {
        Http::fake();
        $version = TaxonomicReferenceVersion::create(['provider' => 'taxref', 'version' => '18', 'status' => 'active']);
        $taxon = Taxon::create([
            'taxref_version_id' => $version->id, 'taxref_cd_ref' => 3780,
            'scientific_name' => 'Tichodroma muraria', 'accepted_scientific_name' => 'Tichodroma muraria',
            'preferred_french_name' => 'Tichodrome échelette', 'rank' => 'species', 'taxonomic_status' => 'canonical',
        ]);
        $record = TaxrefRecord::create([
            'taxonomic_reference_version_id' => $version->id, 'taxon_id' => $taxon->id,
            'cd_nom' => 3780, 'cd_ref' => 3780, 'scientific_name' => 'Tichodroma muraria',
            'name_status' => 'accepted', 'raw_data' => ['RANG' => 'ES'],
        ]);
        $taxon->update(['current_taxref_record_id' => $record->id]);
        TaxonName::create([
            'taxon_id' => $taxon->id, 'taxonomic_reference_version_id' => $version->id,
            'taxref_record_id' => $record->id, 'name' => 'Tichodrome échelette',
            'normalized_name' => 'tichodrome echelette', 'name_type' => 'vernacular',
            'language_code' => 'fr', 'is_preferred' => true, 'source' => 'taxref',
        ]);
        $before = [Taxon::count(), TaxonName::count()];

        $this->getJson('/api/taxa/search?q=Tichodrome')->assertOk()
            ->assertJsonPath('data.0.acceptedScientificName', 'Tichodroma muraria')
            ->assertJsonPath('data.0.preferredFrenchName', 'Tichodrome échelette')
            ->assertJsonPath('data.0.reference.cdRef', 3780)
            ->assertJsonMissingPath('data.0.raw_data');

        self::assertSame($before, [Taxon::count(), TaxonName::count()]);
        Http::assertNothingSent();
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
        self::assertSame('2484918', $gbif[0]->sourceTaxonId);
        self::assertSame('14840', $inat[0]->sourceTaxonId);
    }

    #[Test]
    public function monitoring_stores_an_address_label_without_changing_the_spatial_hash(): void
    {
        $taxon = Taxon::create(['scientific_name' => 'Tichodroma muraria', 'rank' => 'species']);
        $payload = $this->searchPayload($taxon->id, [
            'type' => 'radius', 'address' => ' Rennes, France ', 'latitude' => 48.1173,
            'longitude' => -1.6778, 'radius_km' => 30,
        ]) + ['name' => 'Veille Rennes', 'window_minutes' => 10080, 'frequency_minutes' => 30];

        $this->postJson('/api/monitoring', $payload)->assertCreated()
            ->assertJsonPath('data.zone_data.address', 'Rennes, France')
            ->assertJsonPath('data.zone_data.latitude', 48.1173)
            ->assertJsonPath('data.zone_data.longitude', -1.6778);

        $factory = app(SearchDefinitionFactory::class);
        $otherLabel = $payload;
        $otherLabel['zone']['address'] = 'Même point, autre libellé';
        self::assertSame($factory->make($payload)->zoneHash(), $factory->make($otherLabel)->zoneHash());
    }

    #[Test]
    public function monitoring_accepts_only_configured_departments(): void
    {
        $this->seed(DatabaseSeeder::class);
        $taxonId = Taxon::where('scientific_name', 'Tichodroma muraria')->value('id');
        $payload = $this->searchPayload($taxonId, [
            'type' => 'departments', 'department_codes' => ['9', '22'],
        ]) + ['name' => 'Veille départements', 'window_minutes' => 10080, 'frequency_minutes' => 30];

        $this->postJson('/api/monitoring', $payload)->assertCreated()
            ->assertJsonPath('data.zone_type', 'departments')
            ->assertJsonPath('data.zone_data.department_codes.0', '09')
            ->assertJsonPath('data.zone_data.department_codes.1', '22');

        $payload['zone']['department_codes'] = ['99'];
        $this->postJson('/api/monitoring', $payload)->assertUnprocessable()
            ->assertJsonValidationErrors('zone.department_codes');
    }

    #[Test]
    public function geographic_reference_contains_the_101_french_departments_and_all_portals(): void
    {
        $this->seed(DatabaseSeeder::class);

        self::assertSame(101, GeographicArea::where('type', 'department')->count());
        self::assertFalse(GeographicArea::where('code', '20')->exists());
        self::assertTrue(GeographicArea::whereIn('code', ['2A', '2B'])->count() === 2);
        self::assertSame(96, GeographicArea::where('faune_portal', 'faune_france')->count());
        self::assertSame(2, GeographicArea::where('faune_portal', 'faune_antilles')->count());
        foreach (['973' => 'faune_guyane', '974' => 'faune_reunion', '976' => 'faune_mayotte'] as $code => $portal) {
            $area = GeographicArea::where('code', $code)->firstOrFail();
            self::assertTrue($area->is_overseas);
            self::assertSame($portal, $area->faune_portal);
            self::assertNotNull($area->region_name);
            self::assertNotNull($area->geometry_geojson);
        }
    }

    #[Test]
    public function gbif_and_inaturalist_can_query_all_101_departments(): void
    {
        $this->seed(DatabaseSeeder::class);
        $taxonId = Taxon::where('scientific_name', 'Tichodroma muraria')->value('id');
        $codes = GeographicArea::where('type', 'department')->orderBy('code')->pluck('code')->all();
        $definition = app(SearchDefinitionFactory::class)->make($this->searchPayload($taxonId, [
            'type' => 'departments', 'department_codes' => $codes,
        ]));

        foreach (['gbif', 'inaturalist'] as $source) {
            $queries = app(SearchQueryFactory::class)->forSource($definition, $source);
            self::assertCount(101, $queries);
            foreach ($queries as $query) {
                self::assertTrue($query->department !== null || $query->boundingBox !== null);
            }
        }
    }

    #[Test]
    public function faune_france_monitoring_is_limited_to_one_metropolitan_portal(): void
    {
        Queue::fake();
        $this->seed(DatabaseSeeder::class);
        $taxonId = Taxon::where('scientific_name', 'Tichodroma muraria')->value('id');
        $payload = $this->searchPayload($taxonId, [
            'type' => 'departments', 'department_codes' => ['09', '22'],
        ]) + ['name' => 'Veille Faune-France', 'window_minutes' => 10080, 'frequency_minutes' => 30];
        $payload['sources'] = ['faune-france'];

        $id = $this->postJson('/api/monitoring', $payload)->assertCreated()->json('data.id');
        $this->postJson("/api/monitoring/{$id}/sync")->assertAccepted();
        $job = ExternalFetchJob::firstOrFail();
        self::assertSame($id, $job->monitoring_rule_id);
        self::assertSame(['09', '22'], $job->payload['departments']);
        self::assertSame('383', $job->payload['taxon']['fauneFranceId']);
        Queue::assertNothingPushed();

        $payload['zone']['department_codes'] = ['971'];
        $this->postJson('/api/monitoring', $payload)->assertUnprocessable()->assertJsonValidationErrors('sources');
        $payload['zone']['department_codes'] = ['09', '971'];
        $this->postJson('/api/monitoring', $payload)->assertUnprocessable()->assertJsonValidationErrors('sources');

        $payload['sources'] = ['gbif', 'inaturalist'];
        $payload['zone']['department_codes'] = ['971'];
        $this->postJson('/api/monitoring', $payload)->assertCreated();
        self::assertSame(1, ExternalFetchJob::count());
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
