<?php

namespace Tests\Feature;

use App\Models\DataCollection;
use App\Models\Observation;
use App\Models\Taxon;
use App\Models\TaxonName;
use App\Models\TaxonomicReferenceVersion;
use App\Models\TaxonSourceMapping;
use App\Services\Biodiversity\Data\NormalizedOccurrence;
use App\Services\Biodiversity\OccurrencePersister;
use Database\Seeders\TaxonRankSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ObservationTaxonLocalizationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_gbif_name_with_authorship_is_linked_to_its_french_taxref_taxon(): void
    {
        [, $species] = $this->taxrefSpiderTaxa();

        $outcome = app(OccurrencePersister::class)->persist($this->gbifOccurrence());

        self::assertSame($species->id, $outcome->observation->taxon_id);
        self::assertSame('Épeire diadème', $outcome->observation->taxon->frenchName());
        $this->assertDatabaseMissing('taxa', [
            'scientific_name' => 'Araneus diadematus Clerck, 1757',
            'taxonomic_status' => 'local_unresolved',
        ]);
        $this->assertDatabaseHas('taxon_source_mappings', [
            'source' => 'gbif',
            'source_taxon_id' => '2039',
            'taxon_id' => $species->id,
            'match_type' => 'taxref_scientific_name',
        ]);
    }

    #[Test]
    public function existing_observations_can_be_reconciled_without_deleting_the_original_taxon(): void
    {
        [, $species] = $this->taxrefSpiderTaxa();
        $local = Taxon::create([
            'scientific_name' => 'Araneus diadematus Clerck, 1757',
            'vernacular_name' => 'European garden spider',
            'rank' => 'species',
            'classification' => ['kingdom' => 'Animalia', 'class' => 'Arachnida', 'species' => 'Araneus diadematus'],
            'taxonomic_status' => 'local_unresolved',
        ]);
        $observation = $this->observation($local);
        $mapping = TaxonSourceMapping::create([
            'taxon_id' => $local->id,
            'source' => 'gbif',
            'source_taxon_id' => '2039',
            'mapping_status' => 'candidate',
            'match_type' => 'exact_name',
            'is_preferred' => false,
            'raw_data' => [],
        ]);

        $this->artisan('biodiversity:reconcile-observation-taxa', ['--dry-run' => true])
            ->expectsOutput('Simulation terminée ; aucune donnée modifiée.')
            ->assertSuccessful();
        self::assertSame($local->id, $observation->fresh()->taxon_id);

        $this->artisan('biodiversity:reconcile-observation-taxa')->assertSuccessful();

        self::assertSame($species->id, $observation->fresh()->taxon_id);
        self::assertSame($species->id, $mapping->fresh()->taxon_id);
        self::assertSame('merged', $local->fresh()->status);
        self::assertSame($species->id, $local->fresh()->merged_into_taxon_id);
        $this->assertDatabaseHas('taxa', ['id' => $local->id]);
    }

    #[Test]
    public function the_map_translates_the_group_and_does_not_present_an_english_source_name_as_french(): void
    {
        $this->taxrefSpiderTaxa();
        $local = Taxon::create([
            'scientific_name' => 'Unresolved spider',
            'vernacular_name' => 'English common name',
            'rank' => 'species',
            'classification' => ['class' => 'Arachnida', 'species' => 'Unresolved spider'],
            'taxonomic_status' => 'local_unresolved',
        ]);
        $observation = $this->observation($local);
        $collection = DataCollection::create([
            'name' => 'Araignées',
            'zone_type' => 'france',
            'zone_data' => ['type' => 'france'],
            'zone_hash' => hash('sha256', 'Araignées'),
            'sources' => ['gbif'],
            'is_permanent' => true,
        ]);
        $observation->collections()->attach($collection, ['attached_at' => now()]);

        $this->getJson("/api/collections/{$collection->id}/observations/map")
            ->assertOk()
            ->assertJsonPath('data.0.taxon.frenchName', null)
            ->assertJsonPath('data.0.taxon.scientificName', 'Unresolved spider')
            ->assertJsonPath('data.0.taxonGroup.key', 'Arachnida')
            ->assertJsonPath('data.0.taxonGroup.label', 'Arachnides');
    }

    /** @return array{Taxon, Taxon} */
    private function taxrefSpiderTaxa(): array
    {
        $this->seed(TaxonRankSeeder::class);
        $version = TaxonomicReferenceVersion::create([
            'provider' => 'taxref',
            'version' => '18-test',
            'status' => TaxonomicReferenceVersion::STATUS_ACTIVE,
        ]);
        $class = Taxon::create([
            'taxref_version_id' => $version->id,
            'taxref_cd_ref' => 1,
            'scientific_name' => 'Arachnida',
            'accepted_scientific_name' => 'Arachnida',
            'preferred_french_name' => 'Arachnides',
            'rank' => 'class',
            'rank_code' => 'class',
            'taxonomic_status' => 'canonical',
        ]);
        $species = Taxon::create([
            'taxref_version_id' => $version->id,
            'taxref_cd_ref' => 2,
            'scientific_name' => 'Araneus diadematus',
            'accepted_scientific_name' => 'Araneus diadematus',
            'preferred_french_name' => 'Épeire diadème',
            'rank' => 'species',
            'rank_code' => 'species',
            'parent_id' => $class->id,
            'classification' => ['kingdom' => 'Animalia', 'class' => 'Arachnida'],
            'taxonomic_status' => 'canonical',
        ]);
        TaxonName::create([
            'taxon_id' => $species->id,
            'taxonomic_reference_version_id' => $version->id,
            'name' => 'Araneus diadematus',
            'normalized_name' => 'araneus diadematus',
            'name_type' => TaxonName::TYPE_ACCEPTED_SCIENTIFIC,
            'is_preferred' => true,
            'source' => 'taxref',
        ]);

        return [$class, $species];
    }

    private function observation(Taxon $taxon): Observation
    {
        return Observation::create([
            'taxon_id' => $taxon->id,
            'observed_at' => now(),
            'latitude' => 48.1,
            'longitude' => -1.6,
            'location_status' => 'approximate',
            'first_imported_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    private function gbifOccurrence(): NormalizedOccurrence
    {
        return new NormalizedOccurrence(
            source: 'gbif',
            sourceOccurrenceId: 'observation-1',
            sourceDatasetId: null,
            scientificName: 'Araneus diadematus Clerck, 1757',
            vernacularName: 'European garden spider',
            sourceTaxonId: '2039',
            classification: ['kingdom' => 'Animalia', 'class' => 'Arachnida', 'species' => 'Araneus diadematus'],
            observedAt: '2026-08-01',
            sourceCreatedAt: null,
            sourceUpdatedAt: null,
            publishedAt: null,
            latitude: 48.1,
            longitude: -1.6,
            coordinateUncertaintyM: 20,
            individualCount: 1,
            validationStatus: 'research',
            observerName: null,
            license: 'CC-BY',
            sourceUrl: null,
            media: [],
            rawData: ['key' => 'observation-1'],
        );
    }
}
