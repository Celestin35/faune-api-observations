<?php

namespace App\Console\Commands;

use App\Models\Observation;
use Illuminate\Console\Command;

final class CleanupObservationsCommand extends Command
{
    protected $signature = 'biodiversity:cleanup {--dry-run : Compter sans supprimer}';

    protected $description = 'Supprime les données locales expirées qui ne sont protégées par aucune collection ou surveillance';

    public function handle(): int
    {
        $cutoff = now()->subDays((int) config('biodiversity.retention_days', 365));
        $query = Observation::query()->where('first_imported_at', '<', $cutoff)
            ->where(fn ($q) => $q->whereNull('retain_until')->orWhere('retain_until', '<', now()))
            ->whereDoesntHave('collections', fn ($q) => $q->where('is_permanent', true))
            ->whereDoesntHave('monitoringRules', fn ($q) => $q->where('is_active', true));
        $count = (clone $query)->count();
        if ($this->option('dry-run')) {
            $this->info("{$count} observation(s) seraient supprimées.");

            return self::SUCCESS;
        }
        $query->chunkById(500, fn ($items) => Observation::whereKey($items->modelKeys())->delete());
        $this->info("{$count} observation(s) supprimées.");

        return self::SUCCESS;
    }
}
