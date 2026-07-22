<?php

namespace App\Services\Biodiversity;

use App\Jobs\ImportObservationsJob;
use App\Models\ImportJob;

final class ImportCoordinator
{
    /** @return list<ImportJob> */
    public function create(SearchDefinition $definition, ?int $collectionId = null, ?int $monitoringRuleId = null, array $estimates = []): array
    {
        $jobs = [];
        foreach ($definition->sources as $source) {
            $job = ImportJob::create([
                'source' => $source, 'taxon_id' => $definition->taxon?->id,
                'data_collection_id' => $collectionId, 'monitoring_rule_id' => $monitoringRuleId,
                'date_from' => $definition->dateFrom, 'date_to' => $definition->dateTo,
                'zone_type' => $definition->zoneType(), 'zone_data' => $definition->zone,
                'zone_hash' => $definition->zoneHash(), 'status' => 'pending',
                'limit' => (int) config('biodiversity.import_limit_per_source', 10000),
                'estimated_count' => is_numeric($estimates[$source] ?? null) ? (int) $estimates[$source] : null,
                'taxonomic_reference_version_id' => $definition->taxonomicReferenceVersionId,
                'taxon_scope' => $definition->taxonScope, 'taxon_label_snapshot' => $definition->taxonLabel(),
            ]);
            ImportObservationsJob::dispatch($job->id);
            $jobs[] = $job;
        }

        return $jobs;
    }
}
