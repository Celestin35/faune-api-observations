<?php

namespace Tests\Feature;

use App\Models\Taxon;
use App\Models\TaxonomicReferenceVersion;
use App\Models\TaxonPath;
use App\Models\TaxonRank;
use App\Models\TaxrefRecord;
use Database\Seeders\TaxonRankSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TaxrefFoundationTest extends TestCase
{
    use RefreshDatabase;

    private string $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TaxonRankSeeder::class);
        $this->fixture = base_path('tests/Fixtures/Taxref/taxref-foundation.csv');
    }

    #[Test]
    public function migrations_create_the_additive_taxonomic_foundation(): void
    {
        foreach (['taxonomic_reference_versions', 'taxon_ranks', 'taxref_records', 'taxon_names', 'taxon_paths'] as $table) {
            self::assertTrue(Schema::hasTable($table), $table);
        }
        self::assertTrue(Schema::hasColumns('taxa', [
            'taxref_version_id', 'taxref_cd_ref', 'rank_code', 'parent_id',
            'accepted_scientific_name', 'authorship', 'preferred_french_name',
            'status', 'merged_into_taxon_id', 'current_taxref_record_id',
        ]));
        $extensionMigration = file_get_contents(database_path('migrations/2026_07_22_000004_enable_taxonomic_search_extensions.php'));
        $nameMigration = file_get_contents(database_path('migrations/2026_07_22_000006_create_taxref_records_names_and_paths.php'));
        self::assertStringContainsString('CREATE EXTENSION IF NOT EXISTS pg_trgm', $extensionMigration);
        self::assertStringContainsString('CREATE EXTENSION IF NOT EXISTS unaccent', $extensionMigration);
        self::assertStringContainsString('gin (normalized_name gin_trgm_ops)', $nameMigration);
        self::assertStringNotContainsString('unaccent(normalized_name)', $nameMigration);
    }

    #[Test]
    public function rank_seeder_is_idempotent_and_subspecies_is_not_selectable(): void
    {
        $this->seed(TaxonRankSeeder::class);
        $this->seed(TaxonRankSeeder::class);

        self::assertSame(8, TaxonRank::query()->count());
        self::assertTrue(TaxonRank::query()->findOrFail('species')->selectable);
        self::assertFalse(TaxonRank::query()->findOrFail('subspecies')->selectable);
        self::assertSame('Embranchement', TaxonRank::query()->findOrFail('phylum')->label_fr);
    }

    #[Test]
    public function provider_and_version_are_unique(): void
    {
        $this->version('v-test');

        $this->expectException(QueryException::class);
        $this->version('v-test');
    }

    #[Test]
    public function a_provider_can_have_only_one_active_version(): void
    {
        $this->version('v1', TaxonomicReferenceVersion::STATUS_ACTIVE);

        $this->expectException(QueryException::class);
        $this->version('v2', TaxonomicReferenceVersion::STATUS_ACTIVE);
    }

    #[Test]
    public function taxon_parent_and_merge_constraints_are_enforced(): void
    {
        $parent = Taxon::query()->create(['scientific_name' => 'Animalia foundation', 'rank' => 'kingdom']);
        $child = Taxon::query()->create([
            'scientific_name' => 'Chordata foundation',
            'rank' => 'phylum',
            'rank_code' => 'phylum',
            'parent_id' => $parent->id,
        ]);

        self::assertTrue($child->parent->is($parent));
        self::assertTrue($parent->children->contains($child));

        try {
            $child->update(['merged_into_taxon_id' => $child->id]);
            self::fail('La contrainte devait refuser une auto-fusion.');
        } catch (QueryException) {
            self::assertNull($child->fresh()->merged_into_taxon_id);
        }

        $this->expectException(QueryException::class);
        Taxon::query()->create(['scientific_name' => 'Invalid parent foundation', 'parent_id' => 999999]);
    }

    #[Test]
    public function fixture_import_creates_staging_records_and_reports_statistics(): void
    {
        $exitCode = Artisan::call('taxref:import', [
            'file' => $this->fixture,
            '--reference-version' => 'foundation-fixture',
            '--published-on' => '2026-07-22',
            '--source-uri' => 'fixture://synthetic-taxref-foundation',
        ]);
        $output = Artisan::output();

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('Lignes lues : 10', $output);
        self::assertStringContainsString('Noms acceptés : 8', $output);
        self::assertStringContainsString('Synonymes : 1', $output);
        self::assertStringContainsString('Rangs reconnus : 8', $output);
        self::assertStringContainsString('Rangs inconnus : 1', $output);
        self::assertStringContainsString('Rangs TAXREF non mappés : ZZ=1', $output);
        self::assertStringContainsString('Lignes invalides : 1', $output);
        self::assertSame(9, TaxrefRecord::query()->count());

        $version = TaxonomicReferenceVersion::query()->where('version', 'foundation-fixture')->firstOrFail();
        self::assertSame(TaxonomicReferenceVersion::STATUS_STAGING, $version->status);
        self::assertNotNull($version->imported_at);
        self::assertSame(8, $version->records()->where('name_status', TaxrefRecord::STATUS_ACCEPTED)->count());
        self::assertSame(1, $version->records()->where('name_status', TaxrefRecord::STATUS_SYNONYM)->count());
        $unknown = $version->records()->where('cd_nom', 9)->firstOrFail();
        self::assertNull($unknown->rank_code);
        self::assertSame('ZZ', $unknown->raw_data['RANG']);
        self::assertSame(6, $version->records()->where('cd_nom', 7)->firstOrFail()->parent_cd_ref);
        self::assertFalse($version->records()->where('cd_nom', 10)->exists());
    }

    #[Test]
    public function dry_run_reports_the_fixture_without_writing(): void
    {
        $exitCode = Artisan::call('taxref:import', [
            'file' => $this->fixture,
            '--reference-version' => 'dry-run-fixture',
            '--dry-run' => true,
        ]);
        $output = Artisan::output();

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('Lignes lues : 10', $output);
        self::assertStringContainsString('Dry-run terminé : aucune écriture en base.', $output);
        self::assertSame(0, TaxonomicReferenceVersion::query()->count());
        self::assertSame(0, TaxrefRecord::query()->count());
    }

    #[Test]
    public function import_verifies_archive_and_file_checksums_and_records_source_metadata(): void
    {
        $archive = tempnam(sys_get_temp_dir(), 'taxref-archive-');
        self::assertIsString($archive);
        file_put_contents($archive, 'synthetic archive content');

        try {
            $exitCode = Artisan::call('taxref:import', [
                'file' => $this->fixture,
                '--reference-version' => 'source-metadata',
                '--archive' => $archive,
                '--sha256' => hash_file('sha256', $archive),
                '--file-sha256' => hash_file('sha256', $this->fixture),
                '--source-uri' => 'https://example.test/taxref.zip',
            ]);
        } finally {
            @unlink($archive);
        }

        self::assertSame(0, $exitCode, Artisan::output());
        $version = TaxonomicReferenceVersion::query()->where('version', 'source-metadata')->firstOrFail();
        self::assertSame('taxref-foundation.csv', $version->metadata['source_file']);
        self::assertSame(basename($archive), $version->metadata['source_archive']);
        self::assertSame('csv', $version->metadata['format']['type']);
        self::assertSame('comma', $version->metadata['format']['delimiter']);
        self::assertSame(10, $version->metadata['line_count']);
        self::assertGreaterThan(0, $version->metadata['import_statistics']['peak_memory_bytes']);
    }

    #[Test]
    public function import_streams_records_in_multiple_batches(): void
    {
        $file = $this->temporaryFixture(251);
        try {
            $exitCode = Artisan::call('taxref:import', [
                'file' => $file,
                '--reference-version' => 'multi-batch',
            ]);
        } finally {
            @unlink($file);
        }

        self::assertSame(0, $exitCode, Artisan::output());
        self::assertSame(251, TaxrefRecord::query()->count());
        self::assertSame(2, TaxonomicReferenceVersion::query()->firstOrFail()->metadata['import_statistics']['batches']);
    }

    #[Test]
    public function a_database_failure_marks_the_version_failed_and_rolls_back_records(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'taxref-duplicate-');
        self::assertIsString($file);
        file_put_contents($file, "CD_NOM,CD_REF,RANG,LB_NOM\n1,1,KD,Animalia\n1,1,KD,Animalia duplicate\n");
        try {
            $exitCode = Artisan::call('taxref:import', [
                'file' => $file,
                '--reference-version' => 'failed-fixture',
            ]);
        } finally {
            @unlink($file);
        }

        self::assertSame(1, $exitCode);
        self::assertSame(TaxonomicReferenceVersion::STATUS_FAILED, TaxonomicReferenceVersion::query()->firstOrFail()->status);
        self::assertSame(0, TaxrefRecord::query()->count());
    }

    #[Test]
    public function a_cd_nom_is_unique_inside_one_reference_version(): void
    {
        $version = $this->version('unique-record');
        $attributes = [
            'taxonomic_reference_version_id' => $version->id,
            'cd_nom' => 1,
            'cd_ref' => 1,
            'scientific_name' => 'Animalia unique',
            'rank_code' => 'kingdom',
            'name_status' => TaxrefRecord::STATUS_ACCEPTED,
            'raw_data' => [],
        ];
        TaxrefRecord::query()->create($attributes);

        $this->expectException(QueryException::class);
        TaxrefRecord::query()->create($attributes);
    }

    #[Test]
    public function taxon_paths_find_fixture_ancestors_descendants_and_self_depth(): void
    {
        $version = $this->version('paths-fixture');
        $animalia = Taxon::query()->create(['scientific_name' => 'Animalia paths', 'rank' => 'kingdom']);
        $chordata = Taxon::query()->create(['scientific_name' => 'Chordata paths', 'rank' => 'phylum', 'parent_id' => $animalia->id]);
        TaxonPath::query()->insert([
            ['taxonomic_reference_version_id' => $version->id, 'ancestor_taxon_id' => $animalia->id, 'descendant_taxon_id' => $animalia->id, 'depth' => 0],
            ['taxonomic_reference_version_id' => $version->id, 'ancestor_taxon_id' => $chordata->id, 'descendant_taxon_id' => $chordata->id, 'depth' => 0],
            ['taxonomic_reference_version_id' => $version->id, 'ancestor_taxon_id' => $animalia->id, 'descendant_taxon_id' => $chordata->id, 'depth' => 1],
        ]);

        $descendants = TaxonPath::query()->descendantsOf($version->id, $animalia->id)->orderBy('depth')->get();
        self::assertCount(2, $descendants);
        self::assertSame([0, 1], $descendants->pluck('depth')->all());
        self::assertSame(2, TaxonPath::query()->ancestorsOf($version->id, $chordata->id)->count());
    }

    #[Test]
    public function legacy_taxon_fields_remain_available_and_foundation_fields_stay_hidden_from_json(): void
    {
        $taxon = Taxon::query()->create([
            'scientific_name' => 'Tichodroma compatibility',
            'vernacular_name' => 'Tichodrome compatible',
            'rank' => 'species',
            'classification' => ['kingdom' => 'Animalia'],
            'accepted_scientific_name' => 'Tichodroma compatibility',
        ]);

        self::assertSame('species', $taxon->rank);
        self::assertSame(['kingdom' => 'Animalia'], $taxon->classification);
        self::assertArrayNotHasKey('accepted_scientific_name', $taxon->toArray());
        self::assertArrayNotHasKey('taxref_version_id', $taxon->toArray());
    }

    private function version(string $version, string $status = TaxonomicReferenceVersion::STATUS_STAGING): TaxonomicReferenceVersion
    {
        return TaxonomicReferenceVersion::query()->create([
            'provider' => 'taxref',
            'version' => $version,
            'status' => $status,
        ]);
    }

    private function temporaryFixture(int $rows): string
    {
        $file = tempnam(sys_get_temp_dir(), 'taxref-batches-');
        self::assertIsString($file);
        $handle = fopen($file, 'wb');
        self::assertIsResource($handle);
        fputcsv($handle, ['CD_NOM', 'CD_REF', 'RANG', 'LB_NOM'], ',', '"', '');
        foreach (range(1, $rows) as $id) {
            fputcsv($handle, [$id, $id, 'ES', "Taxon batch {$id}"], ',', '"', '');
        }
        fclose($handle);

        return $file;
    }
}
