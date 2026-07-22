<?php

namespace App\Console\Commands;

use App\Models\TaxonomicReferenceVersion;
use App\Services\Biodiversity\Taxref\LocalTaxaDecisionReader;
use Illuminate\Console\Command;
use Throwable;

final class ValidateLocalTaxaDecisionsCommand extends Command
{
    protected $signature = 'taxref:validate-local-decisions
        {--reference-version=18 : Version TAXREF (alias CLI public : --version)}
        {--file= : Chemin du fichier CSV de décisions}';

    protected $description = 'Valide les décisions de rapprochement des taxons locaux sans modifier la base';

    public function handle(LocalTaxaDecisionReader $reader): int
    {
        $versionName = trim((string) $this->option('reference-version'));
        $version = TaxonomicReferenceVersion::query()->where('provider', 'taxref')->where('version', $versionName)->first();
        if ($version === null) {
            $this->error("Version TAXREF {$versionName} introuvable.");

            return self::FAILURE;
        }
        $file = trim((string) $this->option('file')) ?: database_path("data/taxref/v{$versionName}/local-taxa-decisions.csv");
        try {
            $rows = $reader->read($file, $version);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $counts = collect($rows)->countBy('decision')->sortKeys();
        $this->table(['Décision', 'Nombre'], $counts->map(fn (int $count, string $decision): array => [$decision, $count])->values()->all());
        $this->info(count($rows).' décisions valides ; aucune donnée modifiée.');

        return self::SUCCESS;
    }
}
