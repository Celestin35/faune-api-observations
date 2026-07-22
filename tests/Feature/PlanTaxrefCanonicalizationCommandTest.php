<?php

namespace Tests\Feature;

use App\Models\Taxon;
use App\Models\TaxonomicReferenceVersion;
use App\Models\TaxrefRecord;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class PlanTaxrefCanonicalizationCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $output;

    protected function setUp(): void
    {
        parent::setUp();
        $this->output = storage_path('framework/testing/taxref-canonicalization-plan');
        File::deleteDirectory($this->output);
    }

    public function test_command_writes_all_reports_without_mutating_taxonomic_data(): void
    {
        $this->fixture();
        $before = $this->counts();
        $mutations = [];
        DB::listen(static function (QueryExecuted $query) use (&$mutations): void {
            if (preg_match('/^\s*(insert|update|delete|alter|create|drop|truncate)\b/i', $query->sql)) {
                $mutations[] = $query->sql;
            }
        });

        $this->artisan('taxref:plan-canonicalization', [
            '--reference-version' => '18',
            '--output' => $this->output,
            '--sample' => 2,
        ])->assertSuccessful();

        $this->assertSame($before, $this->counts());
        $this->assertSame([], $mutations);
        foreach ([
            'canonical-concepts-summary.json', 'scientific-name-homonyms.csv', 'existing-taxa-matches.csv',
            'existing-taxa-ambiguous.csv', 'existing-taxa-unresolved.csv', 'taxon-names-estimate.json',
            'hierarchy-estimate.json',
        ] as $file) {
            $this->assertFileExists($this->output.'/'.$file);
        }
        $summary = json_decode(File::get($this->output.'/canonical-concepts-summary.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $summary['scientific_name_homonyms']['groups']);
    }

    public function test_fail_on_ambiguity_returns_failure_after_writing_reports(): void
    {
        $this->fixture();

        $this->artisan('taxref:plan-canonicalization', [
            '--reference-version' => '18',
            '--output' => $this->output,
            '--fail-on-ambiguity' => true,
        ])->assertFailed();

        $this->assertFileExists($this->output.'/existing-taxa-unresolved.csv');
    }

    private function fixture(): void
    {
        $version = TaxonomicReferenceVersion::query()->create([
            'provider' => 'taxref', 'version' => '18', 'status' => 'staging',
        ]);
        foreach ([
            [1, null, 'Duplicata', 'Auteur A', 'accepted'],
            [2, null, 'Duplicata', 'Auteur B', 'accepted'],
            [3, 1, 'Ancien nom', 'Auteur C', 'synonym'],
        ] as [$cdNom, $parent, $name, $author, $status]) {
            TaxrefRecord::query()->create([
                'taxonomic_reference_version_id' => $version->id,
                'cd_nom' => $cdNom,
                'cd_ref' => $status === 'synonym' ? 1 : $cdNom,
                'parent_cd_ref' => $parent,
                'scientific_name' => $name,
                'authorship' => $author,
                'rank_code' => null,
                'name_status' => $status,
                'raw_data' => ['RANG' => 'ES', 'NOM_VERN' => $status === 'accepted' ? 'Nom commun' : ''],
            ]);
        }
        Taxon::query()->create(['scientific_name' => 'Inconnu local', 'rank' => 'species']);
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return [
            'taxa' => Taxon::query()->count(),
            'records' => TaxrefRecord::query()->count(),
            'names' => \DB::table('taxon_names')->count(),
            'paths' => \DB::table('taxon_paths')->count(),
        ];
    }
}
