<?php

namespace App\Console\Commands;

use App\Models\TaxonomicReferenceVersion;
use App\Services\Biodiversity\Taxref\TaxrefHierarchyBuilder;
use Illuminate\Console\Command;
use Throwable;

final class BuildTaxrefHierarchyCommand extends Command
{
    protected $signature = 'taxref:build-hierarchy
        {--reference-version=18 : Version TAXREF (alias CLI public : --version)}
        {--rebuild : Reconstruire les chemins de cette version}';

    protected $description = 'Construit efficacement la table de fermeture hiérarchique TAXREF';

    public function handle(TaxrefHierarchyBuilder $builder): int
    {
        $name = trim((string) $this->option('reference-version'));
        $version = TaxonomicReferenceVersion::query()->where('provider', 'taxref')->where('version', $name)->first();
        if ($version === null) {
            $this->error("Version TAXREF {$name} introuvable.");

            return self::FAILURE;
        }
        try {
            $result = $builder->build($version, (bool) $this->option('rebuild'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->table(['Mesure', 'Valeur'], collect($result)->map(fn ($value, $key): array => [$key, $value])->values()->all());

        return self::SUCCESS;
    }
}
