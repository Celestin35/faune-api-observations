<?php

namespace App\Console\Commands;

use App\Models\TaxonomicReferenceVersion;
use App\Services\Biodiversity\Taxref\TaxrefCanonicalizationPlanner;
use Illuminate\Console\Command;
use Throwable;

final class PlanTaxrefCanonicalizationCommand extends Command
{
    protected $signature = 'taxref:plan-canonicalization
        {--reference-version= : Version TAXREF à analyser (alias CLI public : --version)}
        {--output= : Dossier de sortie des rapports}
        {--sample=20 : Nombre maximal d’exemples détaillés dans les résumés JSON}
        {--fail-on-ambiguity : Retourner un code d’échec si un taxon local reste ambigu ou non résolu}';

    protected $description = 'Prépare en lecture seule le plan de canonicalisation d’une version TAXREF';

    public function handle(TaxrefCanonicalizationPlanner $planner): int
    {
        $versionName = trim((string) $this->option('reference-version'));
        if ($versionName === '') {
            $this->error('L’option --version est obligatoire.');

            return self::FAILURE;
        }
        $sample = filter_var($this->option('sample'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1000]]);
        if ($sample === false) {
            $this->error('L’option --sample doit être un entier compris entre 0 et 1000.');

            return self::FAILURE;
        }
        $version = TaxonomicReferenceVersion::query()->where('provider', 'taxref')->where('version', $versionName)->first();
        if ($version === null) {
            $this->error("La version TAXREF {$versionName} n’existe pas.");

            return self::FAILURE;
        }
        if (! in_array($version->status, [TaxonomicReferenceVersion::STATUS_STAGING, TaxonomicReferenceVersion::STATUS_ACTIVE], true)) {
            $this->error("La version TAXREF {$versionName} a le statut {$version->status} et ne peut pas être planifiée.");

            return self::FAILURE;
        }
        $output = trim((string) $this->option('output'));
        $output = $output === '' ? storage_path("app/taxref/reports/v{$versionName}") : $output;

        $this->info("Analyse TAXREF v{$versionName} en lecture seule…");
        try {
            $result = $planner->plan($version, $output, $sample);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['Mesure', 'Valeur'], [
            ['Concepts acceptés', $result['concepts']['accepted_concepts']],
            ['Groupes homonymes', $result['homonyms']['groups']],
            ['Taxons locaux analysés', $result['matches']['total']],
            ['Correspondances ambiguës', $result['matches']['ambiguous']],
            ['Correspondances non résolues', $result['matches']['unresolved']],
            ['Lignes taxon_names estimées', $result['names']['estimated_taxon_names_rows'] ?? 'n/a'],
            ['Lignes taxon_paths estimées', $result['hierarchy']['taxon_paths_rows']],
        ]);
        $this->info("Rapports écrits dans {$output}");
        $this->info('Aucune table taxonomique n’a été modifiée.');

        if ($this->option('fail-on-ambiguity') && ($result['matches']['ambiguous'] > 0 || $result['matches']['unresolved'] > 0)) {
            $this->error('Des correspondances ambiguës ou non résolues nécessitent une revue manuelle.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
