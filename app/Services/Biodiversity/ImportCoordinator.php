<?php

namespace App\Services\Biodiversity;

use App\Jobs\ImportObservationsJob;
use App\Models\ExternalFetchJob;
use App\Models\GeographicArea;
use App\Models\ImportJob;
use App\Models\TaxonSourceMapping;
use App\Services\Biodiversity\FauneFrance\FauneFranceTaxonomicGroups;

final class ImportCoordinator
{
    public function __construct(private readonly FauneFranceTaxonomicGroups $fauneFranceGroups) {}

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
            if ($source === 'faune-france') {
                foreach ($this->fauneFranceFilters($definition) as $filter) {
                    $job = $this->createImport(
                        $source,
                        $definition,
                        $execution,
                        $collectionId,
                        $monitoringRuleId,
                        null,
                        'En attente du bot Faune-France · '.$filter['label'].'.',
                        $this->fauneFranceTaxonLabel($definition, $filter),
                    );
                    $this->createFauneFranceJob($job, $definition, $execution, $filter);
                    $jobs[] = $job;
                }

                continue;
            }

            $estimate = is_numeric($estimates[$source] ?? null) ? (int) $estimates[$source] : null;
            $job = $this->createImport(
                $source,
                $definition,
                $execution,
                $collectionId,
                $monitoringRuleId,
                $estimate,
            );
            ImportObservationsJob::dispatch($job->id);
            $jobs[] = $job;
        }

        return $jobs;
    }

    private function createImport(
        string $source,
        SearchDefinition $definition,
        ObservationQueryExecution $execution,
        ?int $collectionId,
        ?int $monitoringRuleId,
        ?int $estimate,
        string $progressMessage = 'En attente d’un worker.',
        ?string $taxonLabelSnapshot = null,
    ): ImportJob {
        return ImportJob::create([
            'source' => $source, 'taxon_id' => $definition->taxon?->id,
            'data_collection_id' => $collectionId, 'monitoring_rule_id' => $monitoringRuleId,
            'date_from' => $definition->dateFrom, 'date_to' => $definition->dateTo,
            'zone_type' => $definition->zoneType(), 'zone_data' => $definition->zone,
            'zone_hash' => $definition->zoneHash(), 'status' => 'pending',
            'progress_stage' => 'queued', 'progress_current' => 0,
            'progress_total' => $estimate,
            'progress_message' => $progressMessage,
            'limit' => $execution->importLimit,
            'estimated_count' => $estimate,
            'taxonomic_reference_version_id' => $definition->taxonomicReferenceVersionId,
            'taxon_scope' => $definition->taxonScope,
            'taxon_label_snapshot' => $taxonLabelSnapshot ?? $definition->taxonLabel(),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function fauneFranceFilters(SearchDefinition $definition): array
    {
        $rank = $definition->taxon?->rank_code ?: $definition->taxon?->rank;
        if ($definition->taxon !== null && $rank === 'species') {
            $mapping = TaxonSourceMapping::query()
                ->where('taxon_id', $definition->taxon->id)
                ->where('source', 'faune_france')
                ->where('mapping_status', 'validated')
                ->where('is_preferred', true)
                ->whereNull('valid_to')
                ->firstOrFail();
            $groupId = (int) ($mapping->raw_data['taxonomic_group_id'] ?? 1);

            return [[
                'mode' => 'species',
                'taxonomicGroupId' => $groupId,
                'fauneFranceId' => $mapping->source_taxon_id,
                'scientificName' => $definition->taxon->scientific_name,
                'vernacularName' => $definition->taxon->frenchName() ?: $definition->taxon->scientific_name,
                'label' => $definition->taxon->frenchName() ?: $definition->taxon->scientific_name,
                'mappingId' => $mapping->id,
            ]];
        }

        return array_map(static fn (array $group): array => [
            'mode' => 'group',
            'taxonomicGroupId' => $group['id'],
            'label' => $group['label'],
            'mappingId' => null,
        ], $this->fauneFranceGroups->forTaxon($definition->taxon));
    }

    private function fauneFranceTaxonLabel(SearchDefinition $definition, array $filter): string
    {
        $taxonLabel = trim($definition->taxonLabel() ?? 'Tous les animaux');
        $filterLabel = trim((string) $filter['label']);

        return mb_strtolower($taxonLabel) === mb_strtolower($filterLabel)
            ? $taxonLabel
            : $taxonLabel.' — '.$filterLabel;
    }

    private function createFauneFranceJob(
        ImportJob $import,
        SearchDefinition $definition,
        ObservationQueryExecution $execution,
        array $filter,
    ): ExternalFetchJob {
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
            'taxon_source_mapping_id' => $filter['mappingId'],
            'source' => 'faune-france',
            'status' => ExternalFetchJob::STATUS_PENDING,
            'payload' => [
                'filter' => collect($filter)->except(['mappingId'])->all(),
                'dateFrom' => $definition->dateFrom,
                'dateTo' => $definition->dateTo,
                ...$spatialPayload,
                'importLimit' => $execution->importLimit,
                'maxPages' => $execution->maxPages,
                'pagePauseMs' => $execution->pagePauseMs,
            ],
        ]);
    }
}
