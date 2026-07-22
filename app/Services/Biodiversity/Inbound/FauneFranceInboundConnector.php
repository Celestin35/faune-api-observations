<?php

namespace App\Services\Biodiversity\Inbound;

use App\Services\Biodiversity\Contracts\InboundOccurrenceConnector;
use App\Services\Biodiversity\Data\NormalizedOccurrence;

final class FauneFranceInboundConnector implements InboundOccurrenceConnector
{
    public function normalizeInbound(array $payload): NormalizedOccurrence
    {
        return new NormalizedOccurrence(
            source: 'faune-france',
            sourceOccurrenceId: (string) $payload['source_occurrence_id'],
            sourceDatasetId: $payload['source_dataset_id'] ?? null,
            scientificName: $payload['scientific_name'] ?? null,
            vernacularName: $payload['vernacular_name'] ?? null,
            sourceTaxonId: isset($payload['source_taxon_id']) ? (string) $payload['source_taxon_id'] : null,
            classification: $payload['classification'] ?? [],
            observedAt: $payload['observed_at'] ?? null,
            sourceCreatedAt: $payload['source_created_at'] ?? null,
            sourceUpdatedAt: $payload['source_updated_at'] ?? null,
            publishedAt: $payload['published_at'] ?? null,
            latitude: isset($payload['latitude']) ? (float) $payload['latitude'] : null,
            longitude: isset($payload['longitude']) ? (float) $payload['longitude'] : null,
            coordinateUncertaintyM: isset($payload['coordinate_uncertainty_m']) ? (float) $payload['coordinate_uncertainty_m'] : null,
            individualCount: isset($payload['individual_count']) ? (int) $payload['individual_count'] : null,
            validationStatus: $payload['validation_status'] ?? null,
            observerName: $payload['observer_name'] ?? null,
            license: $payload['license'] ?? null,
            sourceUrl: $payload['source_url'] ?? null,
            media: $payload['media'] ?? [],
            rawData: $payload['raw_data'] ?? $payload,
        );
    }
}
