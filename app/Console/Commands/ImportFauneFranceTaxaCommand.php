<?php

namespace App\Console\Commands;

use App\Services\Biodiversity\FauneFrance\FauneFranceTaxonCatalogImporter;
use Illuminate\Console\Command;
use JsonException;
use Throwable;

final class ImportFauneFranceTaxaCommand extends Command
{
    protected $signature = 'faune-france:import-taxa
        {file : Catalogue JSON produit par npm run export-taxa}
        {--reference-version= : Version TAXREF cible ; utilise la version active par défaut}
        {--dry-run : Analyser sans modifier les correspondances}
        {--report= : Chemin du rapport JSON}';

    protected $description = 'Rapproche le catalogue taxonomique Faune-France avec les taxons canoniques TAXREF';

    public function handle(FauneFranceTaxonCatalogImporter $importer): int
    {
        $file = (string) $this->argument('file');
        if (! is_file($file) || ! is_readable($file)) {
            $this->error("Catalogue introuvable ou illisible : {$file}");

            return self::FAILURE;
        }

        try {
            $catalog = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($catalog)) {
                throw new JsonException('La racine JSON doit être un objet.');
            }
            $result = $importer->import(
                $catalog,
                ! $this->option('dry-run'),
                trim((string) $this->option('reference-version')) ?: null,
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $report = trim((string) $this->option('report'));
        if ($report === '') {
            $report = storage_path('app/faune-france/taxon-import-'.now()->format('Ymd-His').'.json');
        }
        if (! is_dir(dirname($report)) && ! mkdir(dirname($report), 0700, true) && ! is_dir(dirname($report))) {
            $this->error('Impossible de créer le dossier du rapport : '.dirname($report));

            return self::FAILURE;
        }
        file_put_contents($report, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);

        $this->table(['Mesure', 'Valeur'], collect($result['summary'])->map(
            fn (mixed $value, string $key): array => [$key, is_bool($value) ? ($value ? 'oui' : 'non') : $value],
        )->values()->all());
        $this->info("Rapport : {$report}");

        return self::SUCCESS;
    }
}
