<?php

namespace App\Console\Commands;

use App\Models\TaxonomicReferenceVersion;
use App\Services\Biodiversity\Taxref\TaxrefNameBuilder;
use Illuminate\Console\Command;
use Throwable;

final class BuildTaxrefNamesCommand extends Command
{
    protected $signature = 'taxref:build-names {--reference-version=18 : Version TAXREF (alias CLI public : --version)}';

    protected $description = 'Construit les noms scientifiques, synonymes et vernaculaires indexés';

    public function handle(TaxrefNameBuilder $builder): int
    {
        $name = trim((string) $this->option('reference-version'));
        $version = TaxonomicReferenceVersion::query()->where('provider', 'taxref')->where('version', $name)->first();
        if ($version === null) {
            $this->error("Version TAXREF {$name} introuvable.");

            return self::FAILURE;
        }
        try {
            $result = $builder->build($version);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->table(['Mesure', 'Valeur'], collect($result)->map(fn ($value, $key): array => [$key, $value])->values()->all());

        return self::SUCCESS;
    }
}
