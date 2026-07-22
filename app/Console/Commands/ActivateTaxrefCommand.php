<?php

namespace App\Console\Commands;

use App\Models\TaxonomicReferenceVersion;
use App\Services\Biodiversity\Taxref\TaxrefHealthChecker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ActivateTaxrefCommand extends Command
{
    protected $signature = 'taxref:activate {--reference-version=18 : Version TAXREF (alias CLI public : --version)}';

    protected $description = 'Active atomiquement une version TAXREF complète et saine';

    public function handle(TaxrefHealthChecker $checker): int
    {
        $name = trim((string) $this->option('reference-version'));
        $version = TaxonomicReferenceVersion::query()->where('provider', 'taxref')->where('version', $name)->first();
        if ($version === null) {
            $this->error("Version TAXREF {$name} introuvable.");

            return self::FAILURE;
        }
        $health = $checker->check($version, false);
        if (! $health['healthy']) {
            $this->error('Activation refusée : les contrôles TAXREF ne sont pas tous valides.');

            return self::FAILURE;
        }
        DB::transaction(function () use ($version): void {
            TaxonomicReferenceVersion::query()->where('provider', $version->provider)
                ->where('id', '<>', $version->id)->where('status', TaxonomicReferenceVersion::STATUS_ACTIVE)
                ->update(['status' => TaxonomicReferenceVersion::STATUS_ARCHIVED]);
            TaxonomicReferenceVersion::query()->whereKey($version->id)->update(['status' => TaxonomicReferenceVersion::STATUS_ACTIVE]);
        });
        $version->refresh();
        if (! $checker->check($version, true)['healthy']) {
            $this->error('La vérification après activation a échoué.');

            return self::FAILURE;
        }
        $this->info("TAXREF {$name} est actif.");

        return self::SUCCESS;
    }
}
