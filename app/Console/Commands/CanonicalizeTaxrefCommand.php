<?php

namespace App\Console\Commands;

use App\Models\TaxonomicReferenceVersion;
use App\Services\Biodiversity\Taxref\TaxrefCanonicalizer;
use Illuminate\Console\Command;
use Throwable;

final class CanonicalizeTaxrefCommand extends Command
{
    protected $signature = 'taxref:canonicalize
        {--reference-version=18 : Version TAXREF (alias CLI public : --version)}
        {--decisions= : Fichier CSV des décisions locales}';

    protected $description = 'Crée ou reprend les taxons canoniques et leurs liens sans supprimer les taxons historiques';

    public function handle(TaxrefCanonicalizer $canonicalizer): int
    {
        $versionName = trim((string) $this->option('reference-version'));
        $version = TaxonomicReferenceVersion::query()->where('provider', 'taxref')->where('version', $versionName)->first();
        if ($version === null) {
            $this->error("Version TAXREF {$versionName} introuvable.");

            return self::FAILURE;
        }
        $file = trim((string) $this->option('decisions')) ?: database_path("data/taxref/v{$versionName}/local-taxa-decisions.csv");
        try {
            $result = $canonicalizer->canonicalize($version, $file);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->table(['Mesure', 'Valeur'], collect($result)->map(fn ($value, $key): array => [$key, $value])->values()->all());
        $this->info('Canonicalisation terminée et contrôlée. La version reste staging.');

        return self::SUCCESS;
    }
}
