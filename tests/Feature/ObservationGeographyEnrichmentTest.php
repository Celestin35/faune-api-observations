<?php

namespace Tests\Feature;

use App\Models\DataCollection;
use App\Models\Observation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ObservationGeographyEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function exact_coordinates_are_enriched_with_official_elevation_and_administrative_data(): void
    {
        Http::fake([
            'data.geopf.fr/altimetrie/*' => Http::response(['elevations' => [[
                'lon' => -0.4923731,
                'lat' => 42.8352058,
                'z' => 2072.1,
                'acc' => 'Variable suivant la source de mesure',
            ]]]),
            'geo.api.gouv.fr/communes*' => Http::response([[
                'nom' => 'Laruns',
                'code' => '64320',
                'codeDepartement' => '64',
                'departement' => ['code' => '64', 'nom' => 'Pyrénées-Atlantiques'],
                'region' => ['code' => '75', 'nom' => 'Nouvelle-Aquitaine'],
            ]]),
        ]);

        $observation = $this->observation('exact');

        $this->artisan('biodiversity:enrich-observation-geography', ['--limit' => 10])
            ->expectsOutput('1 observation(s) examinée(s), 1 altitude(s) et 1 localisation(s) administrative(s) ajoutées.')
            ->assertSuccessful();

        $observation->refresh();
        self::assertSame(2072.1, $observation->elevation_m);
        self::assertSame('IGN RGE ALTI', $observation->elevation_source);
        self::assertSame('Laruns', $observation->municipality_name);
        self::assertSame('64320', $observation->municipality_code);
        self::assertSame('Pyrénées-Atlantiques', $observation->department_name);
        self::assertSame('64', $observation->department_code);
        self::assertSame('Nouvelle-Aquitaine', $observation->region_name);
        self::assertSame('France', $observation->country_name);
        self::assertSame('FR', $observation->country_code);
        self::assertSame('official', $observation->geography_resolution_method);
        self::assertNotNull($observation->geography_enrichment_attempted_at);

        $this->getJson("/api/observations/{$observation->id}")
            ->assertOk()
            ->assertJsonPath('data.location.elevationM', 2072.1)
            ->assertJsonPath('data.location.elevationSource', 'IGN RGE ALTI')
            ->assertJsonPath('data.location.municipality', 'Laruns')
            ->assertJsonPath('data.location.department', 'Pyrénées-Atlantiques')
            ->assertJsonPath('data.location.region', 'Nouvelle-Aquitaine');

        $collection = DataCollection::create([
            'name' => 'Tichodromes',
            'zone_type' => 'france',
            'zone_data' => ['type' => 'france'],
            'zone_hash' => hash('sha256', 'Tichodromes'),
            'sources' => ['gbif'],
            'is_permanent' => true,
        ]);
        $observation->collections()->attach($collection, ['attached_at' => now()]);
        $this->getJson("/api/collections/{$collection->id}/observations/map")
            ->assertOk()
            ->assertJsonPath('data.0.elevationM', 2072.1);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'data.geopf.fr/altimetrie/')
            && $request['resource'] === 'ign_rge_alti_wld'
            && $request['lon'] === '-0.4923731'
            && $request['lat'] === '42.8352058');
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://geo.api.gouv.fr/communes?')
            && (float) $request['lon'] === -0.4923731
            && (float) $request['lat'] === 42.8352058);
    }

    #[Test]
    public function approximate_coordinates_get_administrative_data_but_never_an_elevation(): void
    {
        Http::fake([
            'geo.api.gouv.fr/communes*' => Http::response([[
                'nom' => 'Laruns',
                'code' => '64320',
                'codeDepartement' => '64',
                'departement' => ['code' => '64', 'nom' => 'Pyrénées-Atlantiques'],
                'region' => ['code' => '75', 'nom' => 'Nouvelle-Aquitaine'],
            ]]),
        ]);
        $observation = $this->observation('approximate');

        $this->artisan('biodiversity:enrich-observation-geography')->assertSuccessful();

        $observation->refresh();
        self::assertNull($observation->elevation_m);
        self::assertSame('Laruns', $observation->municipality_name);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/altimetrie/'));
    }

    #[Test]
    public function source_masked_coordinates_are_never_sent_to_enrichment_services(): void
    {
        Http::fake();
        $observation = $this->observation('source_masked');

        $this->artisan('biodiversity:enrich-observation-geography')->assertSuccessful();

        self::assertNull($observation->fresh()->elevation_m);
        Http::assertNothingSent();
    }

    #[Test]
    public function a_limited_batch_prioritizes_the_most_recent_imports(): void
    {
        Http::fake([
            'geo.api.gouv.fr/communes*' => Http::response([]),
        ]);
        $older = $this->observation('approximate');
        $newer = $this->observation('approximate');

        $this->artisan('biodiversity:enrich-observation-geography', ['--limit' => 1])->assertSuccessful();

        self::assertNull($older->fresh()->geography_enrichment_attempted_at);
        self::assertNotNull($newer->fresh()->geography_enrichment_attempted_at);
    }

    private function observation(string $locationStatus): Observation
    {
        return Observation::create([
            'observed_at' => now(),
            'latitude' => 42.8352058,
            'longitude' => -0.4923731,
            'coordinate_uncertainty_m' => $locationStatus === 'exact' ? 0 : 33,
            'location_status' => $locationStatus,
            'first_imported_at' => now(),
            'last_seen_at' => now(),
        ]);
    }
}
