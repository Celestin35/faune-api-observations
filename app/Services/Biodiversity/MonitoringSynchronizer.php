<?php

namespace App\Services\Biodiversity;

use App\Models\MonitoringRule;

final class MonitoringSynchronizer
{
    public function __construct(private ImportCoordinator $imports) {}

    public function sync(MonitoringRule $rule): array
    {
        $definition = new SearchDefinition($rule->taxon, now()->subMinutes($rule->window_minutes)->toDateString(),
            now()->toDateString(), $rule->zone_data, $rule->sources);
        $jobs = $this->imports->create($definition, null, $rule->id);
        $rule->update(['next_sync_at' => now()->addMinutes($rule->frequency_minutes), 'last_error' => null]);

        return $jobs;
    }
}
