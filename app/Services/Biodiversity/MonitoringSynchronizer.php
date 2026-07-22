<?php

namespace App\Services\Biodiversity;

use App\Models\ExternalFetchJob;
use App\Models\MonitoringRule;

final class MonitoringSynchronizer
{
    public function __construct(private ImportCoordinator $imports) {}

    public function sync(MonitoringRule $rule): array
    {
        $from = now()->subMinutes($rule->window_minutes)->toDateString();
        $to = now()->toDateString();
        $regularSources = array_values(array_diff($rule->sources, ['faune-france']));
        $jobs = [];
        if ($regularSources !== []) {
            $definition = new SearchDefinition($rule->taxon, $from, $to, $rule->zone_data, $regularSources,
                $rule->taxon_scope, $rule->taxonomic_reference_version_id);
            $jobs = $this->imports->create($definition, null, $rule->id);
        }
        if (in_array('faune-france', $rule->sources, true)) {
            $mapping = $rule->taxon?->mappings()->where('source', 'faune_france')
                ->where('mapping_status', 'validated')->where('is_preferred', true)->firstOrFail();
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
                    'departments' => $rule->zone_data['department_codes'],
                    'maxPages' => (int) config('biodiversity.faune_france_max_pages', 100),
                    'pagePauseMs' => (int) config('biodiversity.faune_france_page_pause_ms', 1500),
                ],
            ]);
        }
        $rule->update(['next_sync_at' => now()->addMinutes($rule->frequency_minutes), 'last_error' => null]);

        return $jobs;
    }
}
