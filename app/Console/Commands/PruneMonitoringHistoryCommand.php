<?php

namespace App\Console\Commands;

use App\Models\Observation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PruneMonitoringHistoryCommand extends Command
{
    protected $signature = 'biodiversity:prune-monitoring-history {--dry-run : Compter sans supprimer}';

    protected $description = 'Supprime les détections de surveillance datant de plus de deux mois et leurs observations orphelines';

    public function handle(): int
    {
        $months = (int) config('biodiversity.monitoring_history_months', 2);
        $cutoff = now()->subMonths($months);
        $expired = DB::table('monitoring_rule_observations')->where('detected_at', '<', $cutoff);
        $linkCount = (clone $expired)->count();

        if ($this->option('dry-run')) {
            $this->info("{$linkCount} détection(s) de surveillance seraient supprimées.");

            return self::SUCCESS;
        }

        $observationIds = (clone $expired)->distinct()->pluck('observation_id');
        DB::transaction(function () use ($expired, $observationIds): void {
            $expired->delete();
            $observationIds->chunk(500)->each(function ($ids): void {
                Observation::query()->whereKey($ids)
                    ->whereDoesntHave('collections')
                    ->whereDoesntHave('monitoringRules')
                    ->delete();
            });
        });

        $this->info("{$linkCount} détection(s) de surveillance supprimées.");

        return self::SUCCESS;
    }
}
