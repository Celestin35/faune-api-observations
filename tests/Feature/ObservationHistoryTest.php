<?php

namespace Tests\Feature;

use App\Models\DataCollection;
use App\Models\MonitoringRule;
use App\Models\Observation;
use App\Models\Taxon;
use App\Models\TaxonomicReferenceVersion;
use Database\Seeders\TaxonRankSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ObservationHistoryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_saved_search_exposes_only_its_observations_in_list_and_map_views(): void
    {
        $this->seed(TaxonRankSeeder::class);
        $version = TaxonomicReferenceVersion::create(['provider' => 'taxref', 'version' => 'map-test', 'status' => 'active']);
        $birds = Taxon::create([
            'taxref_version_id' => $version->id,
            'taxref_cd_ref' => 1,
            'scientific_name' => 'Aves',
            'preferred_french_name' => 'Oiseaux',
            'rank' => 'class',
            'rank_code' => 'class',
        ]);
        $milan = Taxon::create([
            'taxref_version_id' => $version->id,
            'taxref_cd_ref' => 2,
            'scientific_name' => 'Milvus migrans',
            'preferred_french_name' => 'Milan noir',
            'rank' => 'species',
            'rank_code' => 'species',
            'parent_id' => $birds->id,
        ]);
        $version->paths()->create([
            'ancestor_taxon_id' => $birds->id,
            'descendant_taxon_id' => $milan->id,
            'depth' => 1,
        ]);
        $search = $this->collection('Milan noir — été dernier');
        $included = $this->observation('2026-07-15 10:00:00', 47.1, 2.2);
        $included->update(['taxon_id' => $milan->id]);
        $excluded = $this->observation('2026-07-16 10:00:00', 48.1, 3.2);
        $included->collections()->attach($search, ['attached_at' => now()]);

        $this->getJson("/api/collections/{$search->id}/observations")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $included->id);

        $this->getJson("/api/collections/{$search->id}/observations/map")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $included->id)
            ->assertJsonPath('data.0.taxonGroup.key', 'Aves')
            ->assertJsonPath('data.0.taxonGroup.label', 'Oiseaux')
            ->assertJsonPath('meta.truncated', false);
    }

    #[Test]
    public function monitoring_history_is_limited_to_two_months_in_the_api_and_pruned_from_storage(): void
    {
        Carbon::setTestNow('2026-08-17 12:00:00');
        $monitoring = $this->monitoring('Milan noir');
        $expired = $this->observation('2026-05-01 10:00:00');
        $recent = $this->observation('2026-08-01 10:00:00');
        $protected = $this->observation('2026-05-02 10:00:00');
        $collection = $this->collection('Recherche conservée');
        $expired->monitoringRules()->attach($monitoring, ['detected_at' => now()->subMonths(2)->subDay()]);
        $recent->monitoringRules()->attach($monitoring, ['detected_at' => now()->subMonth()]);
        $protected->monitoringRules()->attach($monitoring, ['detected_at' => now()->subMonths(3)]);
        $protected->collections()->attach($collection, ['attached_at' => now()]);

        $this->getJson("/api/monitoring/{$monitoring->id}/observations")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $recent->id);

        $this->artisan('biodiversity:prune-monitoring-history')
            ->expectsOutput('2 détection(s) de surveillance supprimées.')
            ->assertSuccessful();

        $this->assertDatabaseMissing('observations', ['id' => $expired->id]);
        $this->assertDatabaseHas('observations', ['id' => $recent->id]);
        $this->assertDatabaseHas('observations', ['id' => $protected->id]);
        $this->assertDatabaseCount('monitoring_rule_observations', 1);
        Carbon::setTestNow();
    }

    #[Test]
    public function deleting_a_saved_search_removes_only_observations_that_have_no_other_history(): void
    {
        $search = $this->collection('À supprimer');
        $other = $this->collection('À conserver');
        $orphan = $this->observation('2026-07-01 10:00:00');
        $shared = $this->observation('2026-07-02 10:00:00');
        $orphan->collections()->attach($search, ['attached_at' => now()]);
        $shared->collections()->attach($search, ['attached_at' => now()]);
        $shared->collections()->attach($other, ['attached_at' => now()]);

        $this->deleteJson("/api/collections/{$search->id}")->assertNoContent();

        $this->assertDatabaseMissing('data_collections', ['id' => $search->id]);
        $this->assertDatabaseMissing('observations', ['id' => $orphan->id]);
        $this->assertDatabaseHas('observations', ['id' => $shared->id]);
    }

    private function collection(string $name): DataCollection
    {
        return DataCollection::create([
            'name' => $name,
            'zone_type' => 'france',
            'zone_data' => ['type' => 'france'],
            'zone_hash' => hash('sha256', $name),
            'sources' => ['gbif'],
            'is_permanent' => true,
        ]);
    }

    private function monitoring(string $name): MonitoringRule
    {
        return MonitoringRule::create([
            'name' => $name,
            'zone_type' => 'france',
            'zone_data' => ['type' => 'france'],
            'zone_hash' => hash('sha256', $name),
            'sources' => ['gbif'],
            'window_minutes' => 1440,
            'frequency_minutes' => 30,
            'is_active' => true,
        ]);
    }

    private function observation(string $observedAt, float $latitude = 47.0, float $longitude = 2.0): Observation
    {
        return Observation::create([
            'observed_at' => $observedAt,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'location_status' => 'approximate',
            'first_imported_at' => now(),
            'last_seen_at' => now(),
        ]);
    }
}
