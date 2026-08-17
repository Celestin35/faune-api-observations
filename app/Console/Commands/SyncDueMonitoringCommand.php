<?php

namespace App\Console\Commands;

use App\Models\MonitoringRule;
use App\Services\Biodiversity\MonitoringSynchronizer;
use Illuminate\Console\Command;

final class SyncDueMonitoringCommand extends Command
{
    protected $signature = 'biodiversity:sync-due-monitoring';

    protected $description = 'Place dans la queue les surveillances actives arrivées à échéance';

    public function handle(MonitoringSynchronizer $synchronizer): int
    {
        $rules = MonitoringRule::query()->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('next_sync_at')->orWhere('next_sync_at', '<=', now()))
            ->whereDoesntHave('imports', fn ($query) => $query->whereIn('status', ['pending', 'running']))
            ->whereDoesntHave('externalFetchJobs', fn ($query) => $query->whereIn('status', ['pending', 'claimed', 'running']))
            ->get();
        foreach ($rules as $rule) {
            try {
                $synchronizer->sync($rule);
                $this->line("Surveillance {$rule->id} planifiée.");
            } catch (\Throwable $exception) {
                $rule->update(['last_error' => $exception->getMessage(), 'next_sync_at' => now()->addMinutes($rule->frequency_minutes)]);
                $this->error($exception->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
