<?php

namespace Tests\Feature;

use App\Models\ExternalFetchJob;
use App\Models\GeographicArea;
use App\Models\ImportJob;
use App\Models\Observation;
use App\Models\ObservationSource;
use App\Models\Taxon;
use App\Models\TaxonSourceMapping;
use App\Services\Biodiversity\FauneFrance\FauneFranceTaxonomicGroups;
use App\Services\Biodiversity\Inbound\FauneFranceRawObservationNormalizer;
use App\Services\Biodiversity\OccurrencePersister;
use App\Services\Biodiversity\SearchDefinitionFactory;
use App\Services\Biodiversity\SearchQueryFactory;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ObservationsExplorationEvolutionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function france_entire_is_a_native_national_query(): void
    {
        $taxon = Taxon::create(['scientific_name' => 'Corvus corone', 'rank' => 'species']);
        $payload = [
            'taxon_id' => $taxon->id,
            'taxon_scope' => 'exact',
            'sources' => ['gbif', 'inaturalist'],
            'zone' => ['type' => 'france'],
            'date_from' => '2026-01-01',
            'date_to' => '2026-08-17',
        ];

        $definition = app(SearchDefinitionFactory::class)->make($payload);

        self::assertSame(['type' => 'france'], $definition->zone);
        foreach (['gbif', 'inaturalist'] as $source) {
            $queries = app(SearchQueryFactory::class)->forSource($definition, $source);
            self::assertCount(1, $queries);
            self::assertSame('FR', $queries[0]->country);
        }
    }

    #[Test]
    public function faune_france_expands_france_entire_to_the_96_metropolitan_departments(): void
    {
        Queue::fake();
        $this->seed(DatabaseSeeder::class);
        $taxon = Taxon::where('scientific_name', 'Tichodroma muraria')->firstOrFail();
        $payload = [
            'name' => 'France métropolitaine',
            'taxon_id' => $taxon->id,
            'taxon_scope' => 'exact',
            'sources' => ['faune-france'],
            'zone' => ['type' => 'france'],
            'window_minutes' => 10080,
            'frequency_minutes' => 30,
        ];

        $monitoringId = $this->postJson('/api/monitoring', $payload)
            ->assertCreated()
            ->assertJsonPath('data.zone_type', 'france')
            ->json('data.id');
        $this->postJson("/api/monitoring/{$monitoringId}/sync")->assertAccepted();

        $departments = ExternalFetchJob::firstOrFail()->payload['departments'];
        self::assertCount(96, $departments);
        self::assertContains('2A', $departments);
        self::assertContains('2B', $departments);
        self::assertSame([], array_values(array_intersect(['971', '972', '973', '974', '976'], $departments)));
        self::assertSame($departments, GeographicArea::fauneFranceDepartmentCodes());
    }

    #[Test]
    public function exploration_and_monitoring_share_radius_department_and_period_criteria(): void
    {
        $taxon = Taxon::create(['scientific_name' => 'Corvus corone', 'rank' => 'species']);
        $factory = app(SearchDefinitionFactory::class);
        $common = [
            'taxon_id' => $taxon->id,
            'taxon_scope' => 'exact',
            'sources' => ['gbif', 'inaturalist'],
            'zone' => ['type' => 'radius', 'address' => 'Rennes', 'latitude' => 48.1, 'longitude' => -1.6, 'radius_km' => 30],
        ];

        $absolute = $factory->absoluteCriteria($common + ['date_from' => '2026-07-01', 'date_to' => '2026-07-23']);
        $sliding = $factory->slidingCriteria($common + ['window_minutes' => 60]);
        $resolved = $sliding->resolve(CarbonImmutable::parse('2026-07-23 12:00:00'));

        self::assertSame($absolute->zone, $sliding->zone);
        self::assertSame($absolute->sources, $sliding->sources);
        self::assertSame('absolute', $absolute->periodType);
        self::assertSame('sliding', $sliding->periodType);
        self::assertSame('2026-07-23', $resolved->dateFrom);
        self::assertSame('2026-07-23', $resolved->dateTo);

        $this->postJson('/api/monitoring', $common + [
            'name' => 'Corneille — Rennes',
            'frequency_minutes' => 30,
            'window_minutes' => 60,
        ])->assertCreated();
        $this->assertDatabaseHas('monitoring_rules', ['name' => 'Corneille — Rennes', 'window_minutes' => 60]);

        $this->postJson('/api/searches/estimate', [
            ...$common,
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-23',
            'zone' => ['type' => 'departments', 'department_codes' => []],
        ])->assertUnprocessable()->assertJsonValidationErrors('zone.department_codes');
    }

    #[Test]
    public function a_mapped_species_creates_linked_faune_france_jobs_without_an_estimate(): void
    {
        Queue::fake();
        $taxon = $this->mappedSpecies();
        $payload = $this->fauneSearchPayload($taxon);

        $this->postJson('/api/searches/estimate', $payload)->assertOk()
            ->assertJsonPath('external.faune-france.available', true)
            ->assertJsonPath('external.faune-france.estimable', false)
            ->assertJsonPath('external.faune-france.count', null);

        $response = $this->postJson('/api/imports', $payload + ['confirmed' => true])
            ->assertAccepted()
            ->assertJsonPath('data.0.source', 'faune-france');

        $import = ImportJob::findOrFail($response->json('data.0.id'));
        $external = ExternalFetchJob::where('import_job_id', $import->id)->firstOrFail();
        self::assertSame('species', $external->payload['filter']['mode']);
        self::assertSame('383', $external->payload['filter']['fauneFranceId']);
        self::assertSame('radius', $external->payload['zone']['type']);
        self::assertSame($import->id, $external->import_job_id);

        $this->patchJson("/api/imports/{$import->id}/cancel")->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
        self::assertSame(ExternalFetchJob::STATUS_CANCELLED, $external->fresh()->status);
    }

    #[Test]
    public function faune_france_accepts_supported_groups_but_rejects_an_unmapped_species(): void
    {
        Queue::fake();
        $birds = Taxon::create([
            'scientific_name' => 'Aves',
            'preferred_french_name' => 'Oiseaux',
            'rank' => 'class',
        ]);
        $payload = [
            ...$this->fauneSearchPayload($birds),
            'taxon_scope' => 'subtree',
        ];
        $this->postJson('/api/searches/estimate', $payload)
            ->assertOk()
            ->assertJsonPath('external.faune-france.available', true);
        $this->postJson('/api/imports', $payload + ['confirmed' => true])
            ->assertAccepted()
            ->assertJsonCount(1, 'data');

        $external = ExternalFetchJob::firstOrFail();
        self::assertSame([
            'mode' => 'group',
            'taxonomicGroupId' => 1,
            'label' => 'Oiseaux',
        ], $external->payload['filter']);
        self::assertNull($external->taxon_source_mapping_id);

        $groups = app(FauneFranceTaxonomicGroups::class);
        self::assertCount(26, $groups->forTaxon(new Taxon(['scientific_name' => 'Animalia'])));
        self::assertSame(
            [8, 9, 10, 11, 12, 18, 19, 20, 21, 22, 25, 26, 43, 51],
            collect($groups->forTaxon(new Taxon(['scientific_name' => 'Insecta'])))->pluck('id')->all(),
        );

        $unmapped = Taxon::create(['scientific_name' => 'Corvus corone', 'rank' => 'species']);
        $this->postJson('/api/searches/estimate', $this->fauneSearchPayload($unmapped))
            ->assertUnprocessable()->assertJsonValidationErrors('sources');
    }

    #[Test]
    public function faune_france_searches_all_animals_through_its_26_taxonomic_groups(): void
    {
        Queue::fake();
        $payload = [
            'taxon_scope' => 'subtree',
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-23',
            'sources' => ['faune-france'],
            'zone' => ['type' => 'radius', 'address' => 'Rennes', 'latitude' => 48.1, 'longitude' => -1.6, 'radius_km' => 30],
        ];

        $this->postJson('/api/searches/estimate', $payload)
            ->assertOk()
            ->assertJsonPath('external.faune-france.available', true);
        $this->postJson('/api/imports', $payload + ['confirmed' => true])
            ->assertAccepted()
            ->assertJsonCount(26, 'data');

        self::assertSame(26, ImportJob::count());
        self::assertSame(26, ExternalFetchJob::count());
        self::assertSame(
            [1, 3, 4, 6, 7, 8, 9, 10, 11, 12, 18, 19, 20, 21, 22, 25, 26, 27, 28, 29, 30, 31, 32, 33, 43, 51],
            ExternalFetchJob::query()->orderBy('id')->get()
                ->map(fn (ExternalFetchJob $job): int => $job->payload['filter']['taxonomicGroupId'])
                ->all(),
        );
        self::assertTrue(ExternalFetchJob::all()->every(
            fn (ExternalFetchJob $job): bool => $job->payload['filter']['mode'] === 'group'
        ));
    }

    #[Test]
    public function detail_and_list_are_paginated_and_never_expose_internal_raw_or_private_coordinates(): void
    {
        $taxon = Taxon::create([
            'scientific_name' => 'Tichodroma muraria',
            'preferred_french_name' => 'Tichodrome échelette',
            'rank' => 'species',
        ]);
        $observation = Observation::create([
            'taxon_id' => $taxon->id,
            'observed_at' => '2026-07-22 10:30:00',
            'temporal_precision' => 'datetime',
            'latitude' => 48.1,
            'longitude' => -1.6,
            'coordinate_uncertainty_m' => 1000,
            'location_status' => 'approximate',
            'department_name' => 'Ille-et-Vilaine',
            'geography_resolution_method' => 'source',
            'first_imported_at' => now(),
            'last_seen_at' => now(),
        ]);
        ObservationSource::create([
            'observation_id' => $observation->id,
            'source' => 'inaturalist',
            'source_occurrence_id' => 'private-test',
            'origin_key' => 'inaturalist:private-test',
            'raw_data' => ['private_geojson' => ['coordinates' => [1.234, 47.890]], 'secretLatitude' => 47.890],
            'canonical_identifiers' => ['internal:test'],
            'location_status' => 'source_masked',
            'public_latitude' => 48.1,
            'public_longitude' => -1.6,
            'observer_is_public' => false,
            'source_observer_name' => null,
        ]);
        Observation::create([
            'observed_at' => '2026-07-21',
            'first_imported_at' => now(),
            'last_seen_at' => now(),
        ]);

        $list = $this->getJson('/api/observations?per_page=1')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonMissingPath('data.0.sources.0.raw_data')
            ->json();
        self::assertStringNotContainsString('secretLatitude', json_encode($list, JSON_THROW_ON_ERROR));

        $detail = $this->getJson("/api/observations/{$observation->id}")->assertOk()
            ->assertJsonPath('data.taxon.frenchName', 'Tichodrome échelette')
            ->assertJsonPath('data.location.status', 'approximate')
            ->assertJsonPath('data.location.department', 'Ille-et-Vilaine')
            ->assertJsonPath('data.sources.0.location.status', 'source_masked')
            ->assertJsonMissingPath('data.sources.0.raw_data')
            ->assertJsonMissingPath('data.sources.0.canonical_identifiers')
            ->json();
        self::assertStringNotContainsString('secretLatitude', json_encode($detail, JSON_THROW_ON_ERROR));

        $this->getJson('/api/observations/999999')->assertNotFound();
    }

    #[Test]
    public function masked_faune_coordinates_are_not_persisted_but_precision_and_facts_are(): void
    {
        $taxon = $this->mappedSpecies();
        $raw = [
            'id_sighting' => 'hidden-383',
            'date_raw' => '2026-07-22',
            'is_hidden' => 1,
            'listSubmenu' => ['title' => '<b>Lieu protégé</b>'],
            'opt_observers' => [[
                'opt_observer_info' => [[
                    'id_sighting' => 'hidden-383',
                    'lat' => 48.123,
                    'lon' => -1.456,
                    'precision' => 'precise',
                    'count' => '2',
                ]],
            ]],
        ];
        $item = app(FauneFranceRawObservationNormalizer::class)->normalize($raw, [
            'fauneFranceId' => '383',
            'scientificName' => 'Tichodroma muraria',
            'vernacularName' => 'Tichodrome échelette',
            'rank' => 'species',
        ]);
        $outcome = app(OccurrencePersister::class)->persist($item);

        self::assertSame('source_masked', $item->locationStatus);
        self::assertNull($item->latitude);
        self::assertNull($outcome->observation->latitude);
        self::assertSame(2, $outcome->observation->individual_count);
        self::assertSame('Lieu protégé', $outcome->observation->locality_name);
        self::assertFalse($outcome->observation->sources->first()->observer_is_public);
    }

    private function mappedSpecies(): Taxon
    {
        $taxon = Taxon::create([
            'scientific_name' => 'Tichodroma muraria',
            'preferred_french_name' => 'Tichodrome échelette',
            'rank' => 'species',
        ]);
        TaxonSourceMapping::create([
            'taxon_id' => $taxon->id,
            'source' => 'faune_france',
            'source_taxon_id' => '383',
            'mapping_status' => 'validated',
            'match_type' => 'manual',
            'confidence' => 1,
            'is_preferred' => true,
            'raw_data' => [],
        ]);

        return $taxon;
    }

    /** @return array<string, mixed> */
    private function fauneSearchPayload(Taxon $taxon): array
    {
        return [
            'taxon_id' => $taxon->id,
            'taxon_scope' => 'exact',
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-23',
            'sources' => ['faune-france'],
            'zone' => ['type' => 'radius', 'address' => 'Rennes', 'latitude' => 48.1, 'longitude' => -1.6, 'radius_km' => 30],
        ];
    }
}
