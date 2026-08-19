<?php

namespace App\Console\Commands;

use App\Models\Observation;
use App\Services\Biodiversity\ExactLocationEnricher;
use Illuminate\Console\Command;

final class EnrichObservationGeographyCommand extends Command
{
    protected $signature = 'biodiversity:enrich-observation-geography
        {--limit= : Nombre maximal d’observations à traiter}
        {--retry : Retraiter aussi les observations déjà examinées}';

    protected $description = 'Ajoute altitude et découpage administratif aux observations publiquement localisées';

    public function handle(ExactLocationEnricher $enricher): int
    {
        $limit = max(1, min(500, (int) ($this->option('limit') ?: config('biodiversity.geography_enrichment_batch_size', 50))));
        $query = Observation::query()
            ->whereIn('location_status', ['exact', 'approximate'])
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            // Fresh imports must become useful on the map immediately. The
            // historical backlog is still drained whenever no newer points
            // are waiting.
            ->orderByDesc('id');

        if (! $this->option('retry')) {
            $query->whereNull('geography_enrichment_attempted_at');
        }

        $result = $enricher->enrich($query->limit($limit)->get());
        $this->info("{$result['processed']} observation(s) examinée(s), {$result['elevations']} altitude(s) et {$result['municipalities']} localisation(s) administrative(s) ajoutées.");

        return self::SUCCESS;
    }
}
