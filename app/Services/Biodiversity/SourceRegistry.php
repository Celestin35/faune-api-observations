<?php

namespace App\Services\Biodiversity;

use App\Services\Biodiversity\Contracts\OccurrenceSourceConnector;
use App\Services\Biodiversity\Data\OccurrenceQuery;
use App\Services\Biodiversity\Sources\GbifConnector;
use App\Services\Biodiversity\Sources\INaturalistConnector;
use App\Services\Biodiversity\Sources\ObisConnector;

final class SourceRegistry
{
    /** @return list<string> */
    public function keys(): array
    {
        return ['gbif', 'inaturalist', 'taxref', 'ebird', 'obis', 'geonature'];
    }

    public function connector(string $key): ?OccurrenceSourceConnector
    {
        return match (strtolower($key)) {
            'gbif' => app(GbifConnector::class),
            'inaturalist' => app(INaturalistConnector::class),
            'obis' => app(ObisConnector::class),
            default => null,
        };
    }

    public function sampleQuery(string $key): OccurrenceQuery
    {
        if (strtolower($key) === 'obis') {
            return new OccurrenceQuery(
                taxon: 'Delphinus delphis',
                from: now()->subYears(5)->toDateString(),
                to: now()->toDateString(),
                boundingBox: ['south' => 41.0, 'west' => -5.3, 'north' => 51.2, 'east' => 9.7],
            );
        }

        return OccurrenceQuery::franceTichodromeLastThirtyDays();
    }

    /** @return array{verdict: string, reason: string} */
    public function status(string $key): array
    {
        return match (strtolower($key)) {
            'gbif' => ['verdict' => 'utilisable', 'reason' => 'API publique vérifiée sans authentification.'],
            'inaturalist' => ['verdict' => 'utilisable', 'reason' => 'API publique de lecture vérifiée sans authentification.'],
            'obis' => ['verdict' => 'partiellement utilisable', 'reason' => 'API publique vérifiée, limitée aux données marines.'],
            'taxref' => ['verdict' => 'non utilisable actuellement', 'reason' => 'Le 20/07/2026, la route historique redirige et la route /taxref-web testée ignore les filtres.'],
            'ebird' => ['verdict' => 'partiellement utilisable', 'reason' => 'Clé personnelle obligatoire; une requête sans clé retourne HTTP 403.'],
            'geonature' => ['verdict' => 'non validé', 'reason' => 'Aucune route publique générique d’observations n’a été validée sur la démo.'],
            default => ['verdict' => 'inconnu', 'reason' => 'Source inconnue.'],
        };
    }
}
