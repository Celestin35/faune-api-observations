<?php

namespace App\Console\Commands;

use App\Models\TaxonomicReferenceVersion;
use App\Services\Biodiversity\Taxref\TaxrefHealthChecker;
use Illuminate\Console\Command;

final class TaxrefHealthCheckCommand extends Command
{
    protected $signature = 'taxref:health-check
        {--reference-version=18 : Version TAXREF (alias CLI public : --version)}
        {--allow-staging : Ne pas exiger le statut active}';

    protected $description = 'Vérifie l’intégrité fonctionnelle d’une version TAXREF canonique';

    public function handle(TaxrefHealthChecker $checker): int
    {
        $name = trim((string) $this->option('reference-version'));
        $version = TaxonomicReferenceVersion::query()->where('provider', 'taxref')->where('version', $name)->first();
        if ($version === null) {
            $this->error("Version TAXREF {$name} introuvable.");

            return self::FAILURE;
        }
        $result = $checker->check($version, ! $this->option('allow-staging'));
        $this->table(['Contrôle', 'Réel', 'Attendu', 'État'], collect($result['checks'])->map(
            fn (array $check, string $key): array => [$key, $check['actual'], $check['expected'], $check['ok'] ? 'OK' : 'ÉCHEC'],
        )->values()->all());

        return $result['healthy'] ? self::SUCCESS : self::FAILURE;
    }
}
