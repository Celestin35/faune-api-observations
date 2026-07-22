<?php

namespace Tests\Feature;

use App\Models\Observation;
use App\Models\Taxon;
use App\Models\TaxonomicReferenceVersion;
use App\Models\TaxonSourceMapping;
use App\Models\TaxrefRecord;
use App\Services\Biodiversity\LocalObservationQuery;
use App\Services\Biodiversity\SearchDefinition;
use App\Services\Biodiversity\Taxref\TaxrefCanonicalizer;
use App\Services\Biodiversity\Taxref\TaxrefHierarchyBuilder;
use App\Services\Biodiversity\Taxref\TaxrefNameBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class TaxrefCanonicalizationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_fixture_is_canonicalized_named_hierarchized_and_activated_without_losing_history(): void
    {
        $version = TaxonomicReferenceVersion::create(['provider' => 'taxref', 'version' => 'test', 'status' => 'staging']);
        $historical = Taxon::create(['scientific_name' => 'Birdus testus', 'rank' => 'species']);
        $mapping = TaxonSourceMapping::create(['taxon_id' => $historical->id, 'source' => 'gbif', 'source_taxon_id' => '123', 'raw_data' => []]);
        $observation = Observation::create([
            'taxon_id' => $historical->id,
            'observed_at' => now(),
            'latitude' => 48.1173,
            'longitude' => -1.6778,
            'first_imported_at' => now(),
            'last_seen_at' => now(),
        ]);
        foreach ([
            [1, 1, null, 'Animalia', 'Animaux', 'accepted'],
            [2, 2, 1, 'Aves', 'Oiseaux', 'accepted'],
            [3, 3, 2, 'Birdus testus', 'Oiseau test', 'accepted'],
            [4, 3, 2, 'Oldus testus', '', 'synonym'],
        ] as [$cdNom, $cdRef, $parent, $name, $vernacular, $status]) {
            TaxrefRecord::create([
                'taxonomic_reference_version_id' => $version->id, 'cd_nom' => $cdNom, 'cd_ref' => $cdRef,
                'parent_cd_ref' => $parent, 'scientific_name' => $name, 'name_status' => $status,
                'raw_data' => ['RANG' => $cdRef === 3 ? 'ES' : 'CL', 'NOM_VERN' => $vernacular],
            ]);
        }
        $file = storage_path('framework/testing/local-taxa-decisions.csv');
        File::ensureDirectoryExists(dirname($file));
        File::put($file, "local_taxon_id,scientific_name,decision,taxref_cd_ref,reason\n{$historical->id},Birdus testus,map_taxref,3,Fixture sûre\n");

        $this->artisan('taxref:validate-local-decisions', [
            '--reference-version' => 'test', '--file' => $file,
        ])->assertSuccessful();

        $canonical = app(TaxrefCanonicalizer::class)->canonicalize($version, $file);
        $names = app(TaxrefNameBuilder::class)->build($version);
        $hierarchy = app(TaxrefHierarchyBuilder::class)->build($version);

        $this->assertSame(3, $canonical['canonical_taxa']);
        $this->assertSame(7, $names['total']);
        $this->assertSame(6, $hierarchy['paths']);
        $this->assertSame($historical->id, $observation->fresh()->taxon_id);
        $this->assertSame($historical->id, $mapping->fresh()->taxon_id);

        $birds = Taxon::query()->where('taxref_version_id', $version->id)->where('taxref_cd_ref', 2)->firstOrFail();
        $definition = new SearchDefinition(
            taxon: $birds,
            dateFrom: now()->toDateString(),
            dateTo: now()->toDateString(),
            zone: ['type' => 'radius', 'latitude' => 48.1173, 'longitude' => -1.6778, 'radius_km' => 1],
            sources: ['gbif'],
            taxonScope: 'subtree',
            taxonomicReferenceVersionId: $version->id,
        );
        $this->assertSame([$observation->id], app(LocalObservationQuery::class)->results($definition)->pluck('id')->all());

        $this->artisan('taxref:activate', ['--reference-version' => 'test'])->assertSuccessful();
        $this->assertSame('active', $version->fresh()->status);
    }
}
