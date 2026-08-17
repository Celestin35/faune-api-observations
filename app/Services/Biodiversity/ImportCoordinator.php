<?php

namespace App\Services\Biodiversity;

use App\Jobs\ImportObservationsJob;
use App\Models\ExternalFetchJob;
use App\Models\GeographicArea;
use App\Models\ImportJob;
use App\Models\TaxonSourceMapping;

final class ImportCoordinator
{
    /** @return list<ImportJob> */
    public function create(SearchDefinition $definition, ?int $collectionId = null, ?int $monitoringRuleId = null, array $estimates = []): array
    {
        $execution = new ObservationQueryExecution(
            purpose: $monitoringRuleId === null ? 'one_off_import' : 'monitoring',
            collectionId: $collectionId,
            monitoringRuleId: $monitoringRuleId,
            importLimit: (int) config('biodiversity.import_limit_per_source', 10000),
            maxPages: (int) config('biodiversity.faune_france_max_pages', 100),
            pagePauseMs: (int) config('biodiversity.faune_france_page_pause_ms', 1500),
        );
        $jobs = [];
        foreach ($definition->sources as $source) {
            $job = ImportJob::create([
                'source' => $source, 'taxon_id' => $definition->taxon?->id,
                'data_collection_id' => $collectionId, 'monitoring_rule_id' => $monitoringRuleId,
                'date_from' => $definition->dateFrom, 'date_to' => $definition->dateTo,
                'zone_type' => $definition->zoneType(), 'zone_data' => $definition->zone,
                'zone_hash' => $definition->zoneHash(), 'status' => 'pending',
                'progress_stage' => 'queued', 'progress_current' => 0,
                'progress_total' => is_numeric($estimates[$source] ?? null) ? (int) $estimates[$source] : null,
                'progress_message' => 'En attente d’un worker.',
                'limit' => $execution->importLimit,
                'estimated_count' => is_numeric($estimates[$source] ?? null) ? (int) $estimates[$source] : null,
                'taxonomic_reference_version_id' => $definition->taxonomicReferenceVersionId,
                'taxon_scope' => $definition->taxonScope, 'taxon_label_snapshot' => $definition->taxonLabel(),
            ]);
            if ($source === 'faune-france') {
                $this->createFauneFranceJob($job, $definition, $execution);
            } else {
                ImportObservationsJob::dispatch($job->id);
            }
            $jobs[] = $job;
        }

        return $jobs;
    }

    private function createFauneFranceJob(
        ImportJob $import,
        SearchDefinition $definition,
        ObservationQueryExecution $execution,
    ): ExternalFetchJob {
        $mapping = TaxonSourceMapping::query()
            ->where('taxon_id', $definition->taxon?->id)
            ->where('source', 'faune_france')
            ->where('mapping_status', 'validated')
            ->where('is_preferred', true)
            ->whereNull('valid_to')
            ->firstOrFail();
        $spatialPayload = match ($definition->zoneType()) {
            'radius' => ['zone' => [
                'type' => 'radius',
                'latitude' => (float) $definition->zone['latitude'],
                'longitude' => (float) $definition->zone['longitude'],
                'radiusKm' => (float) $definition->zone['radius_km'],
                ...(! empty($definition->zone['address']) ? ['address' => $definition->zone['address']] : []),
            ]],
            'france' => ['departments' => GeographicArea::fauneFranceDepartmentCodes()],
            default => ['departments' => $definition->zone['department_codes']],
        };

        return ExternalFetchJob::create([
            'import_job_id' => $import->id,
            'monitoring_rule_id' => $execution->monitoringRuleId,
            'taxon_id' => $definition->taxon?->id,
            'taxon_source_mapping_id' => $mapping->id,
            'source' => 'faune-france',
            'status' => ExternalFetchJob::STATUS_PENDING,
            'payload' => [
                'taxon' => [
                    'fauneFranceId' => $mapping->source_taxon_id,
                    'scientificName' => $definition->taxon->scientific_name,
                    'vernacularName' => $definition->taxon->preferred_french_name
                        ?: $definition->taxon->vernacular_name
                        ?: $definition->taxon->scientific_name,
                    'rank' => 'species',
                ],
                'dateFrom' => $definition->dateFrom,
                'dateTo' => $definition->dateTo,
                ...$spatialPayload,
                'maxPages' => $execution->maxPages,
                'pagePauseMs' => $execution->pagePauseMs,
            ],
        ]);
    }
}
