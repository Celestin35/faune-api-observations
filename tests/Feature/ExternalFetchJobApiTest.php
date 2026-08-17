<?php

namespace Tests\Feature;

use App\Models\ExternalFetchJob;
use App\Models\ExternalFetchJobBatch;
use App\Models\ImportJob;
use App\Models\MonitoringRule;
use App\Models\Observation;
use App\Models\ObservationSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ExternalFetchJobApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_rejects_a_missing_or_invalid_bot_token(): void
    {
        $this->getJson('/api/bot/jobs/next')->assertUnauthorized();
        $this->withToken('invalid')->getJson('/api/bot/jobs/next')->assertUnauthorized();
    }

    #[Test]
    public function it_returns_no_job_when_the_queue_is_empty(): void
    {
        $this->bot()->getJson('/api/bot/jobs/next')
            ->assertOk()
            ->assertExactJson(['job' => null]);
    }

    #[Test]
    public function it_returns_the_next_pending_faune_france_job_in_bot_format(): void
    {
        $job = $this->createJob();

        $this->bot()->getJson('/api/bot/jobs/next')
            ->assertOk()
            ->assertJsonPath('job.jobId', (string) $job->id)
            ->assertJsonPath('job.taxon.fauneFranceId', '383')
            ->assertJsonPath('job.departments.0', '09');
    }

    #[Test]
    public function it_requeues_a_stale_claim_before_returning_the_next_job(): void
    {
        $job = $this->createJob();
        $job->update([
            'status' => ExternalFetchJob::STATUS_CLAIMED,
            'claimed_at' => now()->subMinutes(10),
            'heartbeat_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
        ]);

        $this->bot()->getJson('/api/bot/jobs/next')
            ->assertOk()
            ->assertJsonPath('job.jobId', (string) $job->id);
        self::assertSame(ExternalFetchJob::STATUS_PENDING, $job->fresh()->status);
    }

    #[Test]
    public function claiming_is_atomic_and_only_succeeds_once(): void
    {
        $job = $this->createJob();

        $this->bot()->postJson("/api/bot/jobs/{$job->id}/claim")
            ->assertOk()
            ->assertJsonPath('job.jobId', (string) $job->id);
        $this->bot()->postJson("/api/bot/jobs/{$job->id}/claim")->assertConflict();
        $this->assertDatabaseHas('external_fetch_jobs', [
            'id' => $job->id,
            'status' => ExternalFetchJob::STATUS_CLAIMED,
        ]);
    }

    #[Test]
    public function it_imports_a_raw_batch_and_keeps_faune_france_fields(): void
    {
        $job = $this->claimedJob();

        $this->bot()->postJson("/api/bot/jobs/{$job->id}/results", $this->batch())
            ->assertOk()
            ->assertJsonPath('counts.created', 1)
            ->assertJsonPath('counts.updated', 0)
            ->assertJsonPath('counts.unchanged', 0)
            ->assertJsonPath('replayed', false);

        $source = ObservationSource::query()->where('source', 'faune-france')->where('source_occurrence_id', '987654')->firstOrFail();
        $observation = $source->observation;
        self::assertSame(45.1234567, $observation->latitude);
        self::assertSame(6.7654321, $observation->longitude);
        self::assertSame(3, $observation->individual_count);
        self::assertSame('Col du test', $observation->location_name);
        self::assertSame('Observation détaillée', $observation->remarks);
        self::assertSame('Tichodroma muraria', $observation->taxon->scientific_name);
        self::assertSame('383', $source->source_taxon_id);
        self::assertNotNull($observation->observed_at);
        self::assertSame('06:35', substr((string) $observation->getRawOriginal('observed_at'), 11, 5));
    }

    #[Test]
    public function a_group_search_imports_each_observations_actual_species(): void
    {
        $job = ExternalFetchJob::create([
            'source' => 'faune-france',
            'status' => ExternalFetchJob::STATUS_PENDING,
            'payload' => [
                'filter' => ['mode' => 'group', 'taxonomicGroupId' => 1, 'label' => 'Oiseaux'],
                'dateFrom' => '2026-06-22',
                'dateTo' => '2026-07-22',
                'departments' => ['09'],
                'maxPages' => 100,
                'pagePauseMs' => 1500,
            ],
        ]);
        $this->bot()->postJson("/api/bot/jobs/{$job->id}/claim")->assertOk();

        $observations = [
            $this->rawGroupObservation('1001', 'Rougegorge familier', 'Erithacus rubecula', 48.1, -1.6),
            $this->rawGroupObservation('1002', 'Milan noir', 'Milvus migrans', 48.2, -1.7),
        ];
        $this->bot()->postJson("/api/bot/jobs/{$job->id}/results", [
            'batchNumber' => 1,
            'isLastBatch' => true,
            'observations' => $observations,
        ])->assertOk()->assertJsonPath('counts.created', 2);

        self::assertSame(
            ['Erithacus rubecula', 'Milvus migrans'],
            Observation::query()->with('taxon')->orderBy('id')->get()->pluck('taxon.scientific_name')->all(),
        );
        self::assertSame(
            ['Rougegorge familier', 'Milan noir'],
            ObservationSource::query()->orderBy('id')->pluck('source_vernacular_name')->all(),
        );
        self::assertSame(
            ['701', '727'],
            ObservationSource::query()->orderBy('id')->pluck('source_taxon_id')->all(),
        );
    }

    #[Test]
    public function resending_the_same_batch_does_not_duplicate_observations(): void
    {
        $job = $this->claimedJob();
        $payload = $this->batch();

        $this->bot()->postJson("/api/bot/jobs/{$job->id}/results", $payload)
            ->assertOk()->assertJsonPath('counts.created', 1)->assertJsonPath('replayed', false);
        $this->bot()->postJson("/api/bot/jobs/{$job->id}/results", $payload)
            ->assertOk()->assertJsonPath('counts.created', 1)->assertJsonPath('replayed', true);

        self::assertSame(1, Observation::count());
        self::assertSame(1, ObservationSource::count());
        self::assertSame(1, ExternalFetchJobBatch::count());
    }

    #[Test]
    public function an_empty_group_result_completes_normally_with_zero_observations(): void
    {
        $job = $this->claimedJob();

        $this->bot()->postJson("/api/bot/jobs/{$job->id}/results", [
            'batchNumber' => 1,
            'isLastBatch' => true,
            'observations' => [],
        ])->assertOk()
            ->assertJsonPath('counts.created', 0)
            ->assertJsonPath('counts.updated', 0)
            ->assertJsonPath('counts.unchanged', 0);
        $this->bot()->postJson("/api/bot/jobs/{$job->id}/complete")->assertOk();

        self::assertSame(ExternalFetchJob::STATUS_COMPLETED, $job->fresh()->status);
        self::assertSame(0, ExternalFetchJobBatch::firstOrFail()->observation_count);
    }

    #[Test]
    public function faune_france_results_are_attached_to_the_originating_monitoring(): void
    {
        $monitoring = MonitoringRule::create([
            'name' => 'Veille Faune-France', 'zone_type' => 'departments',
            'zone_data' => ['type' => 'departments', 'department_codes' => ['09']],
            'zone_hash' => 'test', 'sources' => ['faune-france'], 'window_minutes' => 1440,
            'frequency_minutes' => 30, 'is_active' => true,
        ]);
        $job = $this->claimedJob();
        $job->update(['monitoring_rule_id' => $monitoring->id]);

        $this->bot()->postJson("/api/bot/jobs/{$job->id}/results", $this->batch())->assertOk();
        self::assertTrue(Observation::firstOrFail()->monitoringRules()->whereKey($monitoring->id)->exists());
        $this->bot()->postJson("/api/bot/jobs/{$job->id}/complete")->assertOk();
        self::assertNotNull($monitoring->fresh()->last_synced_at);
    }

    #[Test]
    public function it_completes_a_running_job(): void
    {
        $job = $this->claimedJob();
        $this->bot()->postJson('/api/bot/heartbeat', ['jobId' => $job->id])
            ->assertOk()->assertJsonPath('status', 'ok');
        $this->bot()->postJson("/api/bot/jobs/{$job->id}/complete")
            ->assertOk()->assertJsonPath('status', 'completed');

        $job->refresh();
        self::assertSame(ExternalFetchJob::STATUS_COMPLETED, $job->status);
        self::assertNotNull($job->started_at);
        self::assertNotNull($job->completed_at);
    }

    #[Test]
    public function heartbeat_exposes_faune_france_fetch_progress_on_the_import(): void
    {
        $import = ImportJob::create([
            'source' => 'faune-france', 'date_from' => '2026-06-01', 'date_to' => '2026-08-31',
            'zone_type' => 'france', 'zone_data' => ['type' => 'france'], 'zone_hash' => 'france',
            'status' => 'pending', 'limit' => 10000,
        ]);
        $job = $this->createJob();
        $job->update(['import_job_id' => $import->id]);
        $this->bot()->postJson("/api/bot/jobs/{$job->id}/claim")->assertOk();

        $this->bot()->postJson('/api/bot/heartbeat', [
            'jobId' => $job->id,
            'progress' => [
                'stage' => 'fetching',
                'current' => 42,
                'total' => 200,
                'message' => '2160 résultat(s) récupéré(s).',
            ],
        ])->assertOk();

        $import->refresh();
        self::assertSame('running', $import->status);
        self::assertSame('fetching', $import->progress_stage);
        self::assertSame(42, $import->progress_current);
        self::assertSame(200, $import->progress_total);
        self::assertSame('2160 résultat(s) récupéré(s).', $import->progress_message);
    }

    #[Test]
    public function it_records_a_job_failure(): void
    {
        $job = $this->claimedJob();

        $this->bot()->postJson("/api/bot/jobs/{$job->id}/fail", ['errorMessage' => 'Firefox indisponible'])
            ->assertOk()->assertJsonPath('status', 'failed');

        $job->refresh();
        self::assertSame(ExternalFetchJob::STATUS_FAILED, $job->status);
        self::assertSame('Firefox indisponible', $job->error_message);
        self::assertNotNull($job->failed_at);
    }

    private function bot()
    {
        return $this->withToken('test-bot-token');
    }

    private function createJob(): ExternalFetchJob
    {
        return ExternalFetchJob::create([
            'source' => 'faune-france',
            'status' => ExternalFetchJob::STATUS_PENDING,
            'payload' => [
                'taxon' => [
                    'fauneFranceId' => '383',
                    'scientificName' => 'Tichodroma muraria',
                    'vernacularName' => 'Tichodrome échelette',
                    'rank' => 'species',
                ],
                'dateFrom' => '2026-06-22',
                'dateTo' => '2026-07-22',
                'departments' => ['09'],
                'maxPages' => 100,
                'pagePauseMs' => 1500,
            ],
        ]);
    }

    private function claimedJob(): ExternalFetchJob
    {
        $job = $this->createJob();
        $this->bot()->postJson("/api/bot/jobs/{$job->id}/claim")->assertOk();

        return $job->fresh();
    }

    /** @return array<string, mixed> */
    private function batch(): array
    {
        return [
            'batchNumber' => 1,
            'isLastBatch' => true,
            'observations' => [[
                'listSubmenu' => ['title' => '<strong>Col du test</strong>', 'href' => '/index.php?m_id=54&id=987654'],
                'lat' => 45.12,
                'lon' => 6.76,
                'date_raw' => '2026-07-22T00:00:00+02:00',
                'birds_count' => '3',
                'remarks' => [['title' => 'Remarque', 'content' => '<em>Observation détaillée</em>']],
                'opt_observers' => [[
                    'opt_observer_info' => [[
                        'id_sighting' => 987654,
                        'timing' => '08:35',
                        'count' => 3,
                        'lat' => 45.1234567,
                        'lon' => 6.7654321,
                    ]],
                ]],
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function rawGroupObservation(string $sightingId, string $frenchName, string $scientificName, float $latitude, float $longitude): array
    {
        $speciesId = $scientificName === 'Erithacus rubecula' ? '701' : '727';

        return [
            'species_array' => [
                'id' => $speciesId,
                'name' => $frenchName,
                'latin_name' => $scientificName,
                'taxo_group' => 1,
            ],
            'listSubmenu' => ['title' => '<strong>Lieu test</strong>', 'href' => "/index.php?m_id=54&id={$sightingId}"],
            'date_raw' => '2026-07-22T00:00:00+02:00',
            'opt_observers' => [[
                'opt_observer_info' => [[
                    'id_sighting' => $sightingId,
                    'timing' => '08:35',
                    'count' => 1,
                    'lat' => $latitude,
                    'lon' => $longitude,
                ]],
            ]],
        ];
    }
}
