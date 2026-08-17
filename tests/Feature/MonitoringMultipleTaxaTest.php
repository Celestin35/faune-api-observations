<?php

namespace Tests\Feature;

use App\Models\ExternalFetchJob;
use App\Models\ImportJob;
use App\Models\MonitoringRule;
use App\Models\Taxon;
use App\Models\TaxonomicReferenceVersion;
use App\Models\TaxonPath;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MonitoringMultipleTaxaTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function a_monitoring_accepts_multiple_groups_without_a_fixed_limit_and_creates_each_faune_job(): void
    {
        Queue::fake();
        CarbonImmutable::setTestNow('2026-08-18 12:00:00');
        $groups = collect([
            ['Aves', 'Oiseaux'],
            ['Mammalia', 'Mammifères'],
            ['Amphibia', 'Amphibiens'],
            ['Odonata', 'Odonates'],
            ['Orthoptera', 'Orthoptères'],
            ['Coleoptera', 'Coléoptères'],
        ])->map(fn (array $group) => Taxon::create([
            'scientific_name' => $group[0],
            'preferred_french_name' => $group[1],
            'rank' => 'class',
        ]));

        $response = $this->postJson('/api/monitoring', $this->payload($groups->all()))
            ->assertCreated()
            ->assertJsonCount(6, 'data.taxa');
        $rule = MonitoringRule::findOrFail($response->json('data.id'));
        self::assertCount(6, $rule->taxa);

        $this->postJson("/api/monitoring/{$rule->id}/sync")->assertAccepted();

        self::assertSame(7, ImportJob::count());
        self::assertSame(7, ExternalFetchJob::count());
        self::assertSame([1, 3, 4, 7, 8, 11, 21], ExternalFetchJob::query()->orderBy('id')->get()
            ->map(fn (ExternalFetchJob $job): int => $job->payload['filter']['taxonomicGroupId'])->all());
        self::assertTrue(ExternalFetchJob::all()->every(fn (ExternalFetchJob $job): bool => $job->payload['dateFrom'] === '2026-08-18'));
        $this->postJson("/api/monitoring/{$rule->id}/sync")->assertConflict();
    }

    #[Test]
    public function all_animals_and_overlapping_taxa_are_rejected(): void
    {
        $animalia = Taxon::create(['scientific_name' => 'Animalia', 'rank' => 'kingdom']);
        $this->postJson('/api/monitoring', $this->payload([$animalia], ['gbif']))
            ->assertUnprocessable()->assertJsonValidationErrors('taxa');

        $version = TaxonomicReferenceVersion::create([
            'provider' => 'taxref', 'version' => 'test', 'status' => TaxonomicReferenceVersion::STATUS_ACTIVE,
        ]);
        $birds = Taxon::create([
            'taxref_version_id' => $version->id,
            'scientific_name' => 'Aves',
            'accepted_scientific_name' => 'Aves',
            'rank' => 'class',
        ]);
        $milan = Taxon::create([
            'taxref_version_id' => $version->id,
            'scientific_name' => 'Milvus migrans',
            'accepted_scientific_name' => 'Milvus migrans',
            'rank' => 'species',
        ]);
        TaxonPath::insert([
            ['taxonomic_reference_version_id' => $version->id, 'ancestor_taxon_id' => $birds->id, 'descendant_taxon_id' => $birds->id, 'depth' => 0],
            ['taxonomic_reference_version_id' => $version->id, 'ancestor_taxon_id' => $milan->id, 'descendant_taxon_id' => $milan->id, 'depth' => 0],
            ['taxonomic_reference_version_id' => $version->id, 'ancestor_taxon_id' => $birds->id, 'descendant_taxon_id' => $milan->id, 'depth' => 1],
        ]);

        $payload = $this->payload([$birds, $milan], ['gbif']);
        $payload['taxa'][0]['taxon_scope'] = 'subtree';
        $this->postJson('/api/monitoring', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('taxa');
    }

    #[Test]
    public function synchronizations_resume_from_the_last_success_instead_of_reloading_the_whole_window(): void
    {
        Queue::fake();
        CarbonImmutable::setTestNow('2026-08-18 12:00:00');
        $species = Taxon::create(['scientific_name' => 'Milvus migrans', 'rank' => 'species']);
        $ruleId = $this->postJson('/api/monitoring', $this->payload([$species], ['gbif']))
            ->assertCreated()->json('data.id');

        $this->postJson("/api/monitoring/{$ruleId}/sync")->assertAccepted();
        $first = ImportJob::firstOrFail();
        self::assertSame('2026-08-18', $first->date_from->toDateString());
        $first->update(['status' => 'completed']);

        $rule = MonitoringRule::findOrFail($ruleId);
        $rule->update(['last_synced_at' => '2026-08-18 23:55:00']);
        CarbonImmutable::setTestNow('2026-08-19 00:05:00');
        $this->postJson("/api/monitoring/{$ruleId}/sync")->assertAccepted();

        $second = ImportJob::latest('id')->firstOrFail();
        self::assertSame('2026-08-18', $second->date_from->toDateString());
        self::assertSame('2026-08-19', $second->date_to->toDateString());
    }

    /** @param list<Taxon> $taxa @param list<string> $sources */
    private function payload(array $taxa, array $sources = ['faune-france']): array
    {
        return [
            'name' => 'Surveillance multi-groupes',
            'taxa' => array_map(fn (Taxon $taxon): array => [
                'taxon_id' => $taxon->id,
                'taxon_scope' => $taxon->rank === 'species' ? 'exact' : 'subtree',
            ], $taxa),
            'sources' => $sources,
            'zone' => [
                'type' => 'radius', 'address' => 'Rennes', 'latitude' => 48.1,
                'longitude' => -1.6, 'radius_km' => 30,
            ],
            'window_minutes' => 10080,
            'frequency_minutes' => 30,
            'is_active' => true,
        ];
    }
}
