<?php

namespace App\Jobs;

use App\Models\CollectionCoverage;
use App\Models\ImportJob;
use App\Models\MonitoringRule;
use App\Services\Biodiversity\Data\OccurrenceQuery;
use App\Services\Biodiversity\OccurrencePersister;
use App\Services\Biodiversity\SearchDefinition;
use App\Services\Biodiversity\SearchQueryFactory;
use App\Services\Biodiversity\Sources\GbifConnector;
use App\Services\Biodiversity\Sources\INaturalistConnector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ImportObservationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $tries = 2;

    public function __construct(public int $importJobId) {}

    public function handle(SearchQueryFactory $queryFactory, OccurrencePersister $persister,
        GbifConnector $gbif, INaturalistConnector $inaturalist): void
    {
        $import = ImportJob::findOrFail($this->importJobId);
        if ($import->status === 'cancelled') {
            return;
        }
        $import->update([
            'status' => 'running',
            'progress_stage' => 'saving',
            'progress_current' => $import->processed_count,
            'progress_total' => $import->estimated_count,
            'progress_message' => 'Récupération et enregistrement des observations.',
            'started_at' => $import->started_at ?? now(),
            'error_message' => null,
        ]);

        try {
            $definition = new SearchDefinition($import->taxon, $import->date_from->toDateString(),
                $import->date_to->toDateString(), $import->zone_data, [$import->source],
                $import->taxon_scope, $import->taxonomic_reference_version_id);
            $processed = $created = $updated = $unchanged = $failed = $estimated = 0;
            $queries = $queryFactory->forSource($definition, $import->source);
            $completedQueries = 0;
            foreach ($queries as $query) {
                if ($processed >= $import->limit) {
                    break;
                }
                if ($import->source === 'gbif') {
                    [$p, $c, $u, $n, $f, $e] = $this->importGbif(
                        $query, $import, $persister, $gbif, $processed,
                        fn (int $pageProcessed, int $pageCreated, int $pageUpdated, int $pageUnchanged, int $pageFailed, int $total) => $this->progress($import, $processed + $pageProcessed, $created + $pageCreated,
                            $updated + $pageUpdated, $unchanged + $pageUnchanged, $failed + $pageFailed, $total),
                    );
                } else {
                    [$p, $c, $u, $n, $f, $e] = $this->importINaturalist(
                        $query, $import, $persister, $inaturalist, $processed,
                        fn (int $pageProcessed, int $pageCreated, int $pageUpdated, int $pageUnchanged, int $pageFailed, int $total) => $this->progress($import, $processed + $pageProcessed, $created + $pageCreated,
                            $updated + $pageUpdated, $unchanged + $pageUnchanged, $failed + $pageFailed, $total),
                    );
                }
                $processed += $p;
                $created += $c;
                $updated += $u;
                $unchanged += $n;
                $failed += $f;
                $estimated += $e;
                $completedQueries++;
                $this->progress($import, $processed, $created, $updated, $unchanged, $failed);
            }
            $partial = $completedQueries < count($queries) || ($processed >= $import->limit && $estimated > $processed);
            $status = ($partial || $failed > 0) ? 'partial' : 'completed';
            $import->update(['status' => $status, 'estimated_count' => $estimated, 'processed_count' => $processed,
                'created_count' => $created, 'updated_count' => $updated, 'unchanged_count' => $unchanged,
                'failed_count' => $failed, 'progress_stage' => 'finished', 'progress_current' => $processed,
                'progress_total' => $estimated, 'progress_message' => $status === 'partial'
                    ? 'Import terminé partiellement.' : 'Import terminé.', 'finished_at' => now()]);
            CollectionCoverage::create([
                'data_collection_id' => $import->data_collection_id, 'taxon_id' => $import->taxon_id,
                'source' => $import->source, 'zone_hash' => $import->zone_hash,
                'covered_from' => $import->date_from, 'covered_to' => $import->date_to,
                'observation_count' => $processed, 'status' => $status, 'last_synced_at' => now(),
                'taxonomic_reference_version_id' => $import->taxonomic_reference_version_id,
                'taxon_scope' => $import->taxon_scope, 'taxon_label_snapshot' => $import->taxon_label_snapshot,
            ]);
            $this->completeMonitoring($import, null);
        } catch (\Throwable $exception) {
            $import->update(['status' => 'failed', 'progress_stage' => 'finished',
                'progress_message' => 'L’import a échoué.', 'error_message' => mb_substr($exception->getMessage(), 0, 4000),
                'finished_at' => now()]);
            $this->completeMonitoring($import, $exception->getMessage());
            report($exception);
        }
    }

    /** @return array{int,int,int,int,int,int} */
    private function importGbif(OccurrenceQuery $query, ImportJob $import, OccurrencePersister $persister,
        GbifConnector $connector, int $alreadyProcessed, callable $onProgress): array
    {
        $offset = $processed = $created = $updated = $unchanged = $failed = 0;
        $estimated = 0;
        do {
            $remaining = $import->limit - $alreadyProcessed - $processed;
            if ($remaining <= 0) {
                break;
            }
            $result = $connector->fetchPage($query, min(300, $remaining), $offset);
            $estimated = $result->total;
            foreach ($result->occurrences as $item) {
                try {
                    $outcome = $persister->persist($item, $import->data_collection_id, $import->monitoring_rule_id);
                    ${$outcome->status}++;
                } catch (\Throwable $exception) {
                    report($exception);
                    $failed++;
                }
                $processed++;
            }
            $pageCount = count($result->occurrences);
            $offset += $pageCount;
            $onProgress($processed, $created, $updated, $unchanged, $failed, $estimated);
            unset($result);
            gc_collect_cycles();
        } while ($pageCount > 0 && $offset < $estimated && $offset < 100000);

        return [$processed, $created, $updated, $unchanged, $failed, $estimated];
    }

    /** @return array{int,int,int,int,int,int} */
    private function importINaturalist(OccurrenceQuery $query, ImportJob $import, OccurrencePersister $persister,
        INaturalistConnector $connector, int $alreadyProcessed, callable $onProgress): array
    {
        $idAbove = null;
        $processed = $created = $updated = $unchanged = $failed = 0;
        $estimated = 0;
        do {
            $remaining = $import->limit - $alreadyProcessed - $processed;
            if ($remaining <= 0) {
                break;
            }
            $pageSize = max(10, min(200, (int) config('biodiversity.inaturalist_import_page_size', 200)));
            $requested = min($pageSize, $remaining);
            $result = $connector->fetchPage($query, $requested, $idAbove);
            if ($estimated === 0) {
                $estimated = $result->total;
            }
            foreach ($result->occurrences as $item) {
                try {
                    $outcome = $persister->persist($item, $import->data_collection_id, $import->monitoring_rule_id);
                    ${$outcome->status}++;
                } catch (\Throwable $exception) {
                    report($exception);
                    $failed++;
                }
                $processed++;
                $idAbove = max((int) ($idAbove ?? 0), (int) $item->sourceOccurrenceId);
            }
            $pageCount = count($result->occurrences);
            $onProgress($processed, $created, $updated, $unchanged, $failed, $estimated);
            unset($result);
            gc_collect_cycles();
            if ($pageCount === $requested && $processed < $import->limit) {
                usleep(max(0, (int) config('biodiversity.inaturalist_import_pause_ms')) * 1000);
            }
        } while ($pageCount === $requested && $processed < $import->limit);

        return [$processed, $created, $updated, $unchanged, $failed, $estimated];
    }

    private function progress(ImportJob $job, int $processed, int $created, int $updated, int $unchanged, int $failed,
        ?int $total = null): void
    {
        $job->update([
            'progress_stage' => 'saving', 'progress_current' => $processed,
            'progress_total' => $total ?: $job->estimated_count,
            'progress_message' => 'Récupération et enregistrement des observations.',
            'processed_count' => $processed, 'created_count' => $created, 'updated_count' => $updated,
            'unchanged_count' => $unchanged, 'failed_count' => $failed,
        ]);
    }

    private function completeMonitoring(ImportJob $import, ?string $error): void
    {
        if (! $import->monitoring_rule_id) {
            return;
        }
        $rule = MonitoringRule::find($import->monitoring_rule_id);
        if ($rule) {
            $rule->update([
                ...($error === null ? ['last_synced_at' => now()] : []),
                'next_sync_at' => now()->addMinutes($rule->frequency_minutes),
                'last_error' => $error,
            ]);
        }
    }
}
