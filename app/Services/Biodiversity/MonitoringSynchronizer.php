<?php

namespace App\Services\Biodiversity;

use App\Models\MonitoringRule;
use App\Models\Taxon;
use Carbon\CarbonImmutable;

final class MonitoringSynchronizer
{
    public function __construct(private ImportCoordinator $imports) {}

    public function sync(MonitoringRule $rule): array
    {
        if ($rule->hasSynchronizationInProgress()) {
            return [];
        }

        [$from, $to] = $this->incrementalPeriod($rule);
        $jobs = [];
        foreach ($this->taxonSelections($rule) as $selection) {
            $definition = new SearchDefinition(
                taxon: $selection['taxon'],
                dateFrom: $from,
                dateTo: $to,
                zone: $rule->zone_data,
                sources: $rule->sources,
                taxonScope: $selection['scope'],
                taxonomicReferenceVersionId: $selection['referenceVersionId'],
            );
            array_push($jobs, ...$this->imports->create($definition, null, $rule->id));
        }
        $rule->update(['next_sync_at' => now()->addMinutes($rule->frequency_minutes), 'last_error' => null]);

        return $jobs;
    }

    /** @return array{string, string} */
    private function incrementalPeriod(MonitoringRule $rule): array
    {
        $now = CarbonImmutable::now();
        $windowStart = $now->subMinutes($rule->window_minutes);
        $cursor = $rule->last_synced_at !== null
            ? CarbonImmutable::instance($rule->last_synced_at)
            : CarbonImmutable::instance($rule->created_at);
        $from = $cursor->subMinutes((int) config('biodiversity.monitoring_overlap_minutes', 10));
        if ($from->lessThan($windowStart)) {
            $from = $windowStart;
        }

        return [$from->toDateString(), $now->toDateString()];
    }

    /** @return list<array{taxon: Taxon, scope: string, referenceVersionId: ?int}> */
    private function taxonSelections(MonitoringRule $rule): array
    {
        $taxa = $rule->taxa()->get();
        if ($taxa->isNotEmpty()) {
            return $taxa->map(fn (Taxon $taxon): array => [
                'taxon' => $taxon,
                'scope' => (string) $taxon->pivot->taxon_scope,
                'referenceVersionId' => $taxon->pivot->taxonomic_reference_version_id !== null
                    ? (int) $taxon->pivot->taxonomic_reference_version_id
                    : null,
            ])->all();
        }

        return $rule->taxon === null ? [] : [[
            'taxon' => $rule->taxon,
            'scope' => $rule->taxon_scope,
            'referenceVersionId' => $rule->taxonomic_reference_version_id,
        ]];
    }
}
