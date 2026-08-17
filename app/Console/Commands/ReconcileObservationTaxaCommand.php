<?php

namespace App\Console\Commands;

use App\Models\Taxon;
use App\Services\Biodiversity\CanonicalTaxonMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ReconcileObservationTaxaCommand extends Command
{
    protected $signature = 'biodiversity:reconcile-observation-taxa {--dry-run : Analyser sans modifier la base}';

    protected $description = 'Rapproche les taxons locaux des observations avec la référence TAXREF active';

    public function handle(CanonicalTaxonMatcher $matcher): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $stats = [
            'taxa' => 0,
            'matched' => 0,
            'unresolved' => 0,
            'observations' => 0,
            'mappings' => 0,
        ];

        $taxa = Taxon::query()
            ->where('taxonomic_status', 'local_unresolved')
            ->whereNull('merged_into_taxon_id')
            ->where(function ($query): void {
                $query->whereExists(fn ($query) => $query->selectRaw('1')
                    ->from('observations')
                    ->whereColumn('observations.taxon_id', 'taxa.id'))
                    ->orWhereExists(fn ($query) => $query->selectRaw('1')
                        ->from('taxon_source_mappings')
                        ->whereColumn('taxon_source_mappings.taxon_id', 'taxa.id'));
            })
            ->orderBy('id')
            ->get();

        foreach ($taxa as $taxon) {
            $stats['taxa']++;
            $canonical = $matcher->match(
                $taxon->scientific_name,
                $taxon->classification ?? [],
                $taxon->rank_code ?: $taxon->rank,
            );
            if ($canonical === null || $canonical->id === $taxon->id) {
                $stats['unresolved']++;

                continue;
            }

            $stats['matched']++;
            $stats['observations'] += DB::table('observations')->where('taxon_id', $taxon->id)->count();
            $stats['mappings'] += DB::table('taxon_source_mappings')->where('taxon_id', $taxon->id)->count();
            if ($dryRun) {
                continue;
            }

            DB::transaction(function () use ($taxon, $canonical): void {
                DB::table('observations')->where('taxon_id', $taxon->id)->update(['taxon_id' => $canonical->id]);
                DB::table('taxon_source_mappings')->where('taxon_id', $taxon->id)->update([
                    'taxon_id' => $canonical->id,
                    'mapping_status' => 'candidate',
                    'match_type' => 'taxref_scientific_name',
                    'confidence' => .95,
                    'is_preferred' => false,
                    'updated_at' => now(),
                ]);
                $taxon->update([
                    'status' => 'merged',
                    'merged_into_taxon_id' => $canonical->id,
                ]);
            });
        }

        $this->table(['Mesure', 'Nombre'], [
            ['Taxons locaux analysés', $stats['taxa']],
            ['Taxons rapprochés', $stats['matched']],
            ['Taxons non résolus', $stats['unresolved']],
            ['Observations concernées', $stats['observations']],
            ['Mappings source concernés', $stats['mappings']],
        ]);
        $this->info($dryRun
            ? 'Simulation terminée ; aucune donnée modifiée.'
            : 'Rapprochement terminé ; les taxons ambigus ou absents de TAXREF ont été conservés.');

        return self::SUCCESS;
    }
}
