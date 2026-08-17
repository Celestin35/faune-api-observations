<?php

namespace App\Services\Biodiversity;

use App\Models\ExternalFetchJob;
use App\Models\GeographicArea;
use App\Models\MonitoringRule;

final class MonitoringSynchronizer
{
    public function __construct(private ImportCoordinator $imports) {}

    public function sync(MonitoringRule $rule): array
    {
        $criteria = new ObservationQueryCriteria(
            taxon: $rule->taxon,
            taxonScope: $rule->taxon_scope,
            taxonomicReferenceVersionId: $rule->taxonomic_reference_version_id,
            taxonLabelSnapshot: $rule->taxon_label_snapshot,
            periodType: 'sliding',
            dateFrom: null,
            dateTo: null,
            windowMinutes: $rule->window_minutes,
            zone: $rule->zone_data,
            sources: $rule->sources,
        );
        $definition = $criteria->resolve();
        $from = $definition->dateFrom;
        $to = $definition->dateTo;
        $regularSources = array_values(array_diff($rule->sources, ['faune-france']));
        $jobs = [];
        if ($regularSources !== []) {
            $regularDefinition = new SearchDefinition($definition->taxon, $from, $to, $definition->zone, $regularSources,
                $definition->taxonScope, $definition->taxonomicReferenceVersionId);
            $jobs = $this->imports->create($regularDefinition, null, $rule->id);
        }
        if (in_array('faune-france', $rule->sources, true)) {
            $mapping = $rule->taxon?->mappings()->where('source', 'faune_france')
                ->where('mapping_status', 'validated')->where('is_preferred', true)->firstOrFail();
            $spatialPayload = match ($rule->zone_type) {
                'radius' => ['zone' => [
                    'type' => 'radius',
                    'latitude' => (float) $rule->zone_data['latitude'],
                    'longitude' => (float) $rule->zone_data['longitude'],
                    'radiusKm' => (float) $rule->zone_data['radius_km'],
                    ...(! empty($rule->zone_data['address']) ? ['address' => $rule->zone_data['address']] : []),
                ]],
                'france' => ['departments' => GeographicArea::fauneFranceDepartmentCodes()],
                default => ['departments' => $rule->zone_data['department_codes']],
            };
            $jobs[] = ExternalFetchJob::create([
                'monitoring_rule_id' => $rule->id,
                'taxon_id' => $rule->taxon_id,
                'taxon_source_mapping_id' => $mapping->id,
                'source' => 'faune-france',
                'status' => ExternalFetchJob::STATUS_PENDING,
                'payload' => [
                    'taxon' => [
                        'fauneFranceId' => $mapping->source_taxon_id,
                        'scientificName' => $rule->taxon->scientific_name,
                        'vernacularName' => $rule->taxon->vernacular_name ?: $rule->taxon->scientific_name,
                        'rank' => 'species',
                    ],
                    'dateFrom' => $from,
                    'dateTo' => $to,
                    ...$spatialPayload,
                    'maxPages' => (int) config('biodiversity.faune_france_max_pages', 100),
                    'pagePauseMs' => (int) config('biodiversity.faune_france_page_pause_ms', 1500),
                ],
            ]);
        }
        $rule->update(['next_sync_at' => now()->addMinutes($rule->frequency_minutes), 'last_error' => null]);

        return $jobs;
    }
}
