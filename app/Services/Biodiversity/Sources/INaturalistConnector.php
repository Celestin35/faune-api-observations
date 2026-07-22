<?php

namespace App\Services\Biodiversity\Sources;

use App\Services\Biodiversity\Contracts\OccurrenceSourceConnector;
use App\Services\Biodiversity\Data\NormalizedOccurrence;
use App\Services\Biodiversity\Data\OccurrenceQuery;
use App\Services\Biodiversity\Data\SourceResult;
use UnexpectedValueException;

final class INaturalistConnector extends AbstractHttpConnector implements OccurrenceSourceConnector
{
    private const FRANCE_PLACE_ID = 6753;

    public function key(): string
    {
        return 'inaturalist';
    }

    protected function baseUrl(): string
    {
        return (string) config('biodiversity.sources.inaturalist.base_url');
    }

    public function search(OccurrenceQuery $query, int $limit = 3): SourceResult
    {
        return $this->fetchPage($query, min(max($limit, 1), 20));
    }

    public function fetchPage(OccurrenceQuery $query, int $limit = 200, ?int $idAbove = null): SourceResult
    {
        $parameters = $this->parameters($query) + [
            'per_page' => min(max($limit, 1), 200),
            'order_by' => 'id', 'order' => 'asc', 'id_above' => $idAbove,
        ];
        $payload = $this->get('/observations', $parameters)->json();

        if (! is_array($payload) || ! isset($payload['total_results']) || ! is_array($payload['results'] ?? null)) {
            throw new UnexpectedValueException('iNaturalist returned an unexpected occurrence response.');
        }

        return new SourceResult(
            source: $this->key(),
            total: (int) $payload['total_results'],
            occurrences: array_map(fn (array $record) => $this->normalize($record), $payload['results']),
            requestParameters: $parameters,
            quotaHeaders: $this->lastQuotaHeaders,
        );
    }

    public function count(OccurrenceQuery $query): int
    {
        $payload = $this->get('/observations', $this->parameters($query) + ['per_page' => 1, 'page' => 1])->json();

        if (! is_array($payload) || ! isset($payload['total_results'])) {
            throw new UnexpectedValueException('iNaturalist did not return a count.');
        }

        return (int) $payload['total_results'];
    }

    /** @return list<array<string, mixed>> */
    public function searchTaxa(string $query, int $limit = 10): array
    {
        $payload = $this->get('/taxa', ['q' => $query, 'per_page' => min(max($limit, 1), 20)])->json();

        if (! is_array($payload) || ! is_array($payload['results'] ?? null)) {
            throw new UnexpectedValueException('iNaturalist returned an unexpected taxon response.');
        }

        return $payload['results'];
    }

    public function normalize(array $record): NormalizedOccurrence
    {
        $id = isset($record['id']) ? (string) $record['id'] : '';
        if ($id === '') {
            throw new UnexpectedValueException('An iNaturalist record has no id.');
        }

        $taxon = is_array($record['taxon'] ?? null) ? $record['taxon'] : [];
        $classification = [];
        foreach ($taxon['ancestors'] ?? [] as $ancestor) {
            if (is_array($ancestor) && isset($ancestor['rank'], $ancestor['name'])) {
                $classification[(string) $ancestor['rank']] = (string) $ancestor['name'];
            }
        }
        if (isset($taxon['rank'], $taxon['name'])) {
            $classification[(string) $taxon['rank']] = (string) $taxon['name'];
        }

        $media = [];
        $photoItems = $record['observation_photos'] ?? $record['photos'] ?? [];
        foreach ($photoItems as $observationPhoto) {
            $photo = is_array($observationPhoto['photo'] ?? null) ? $observationPhoto['photo'] : $observationPhoto;
            $media[] = array_filter([
                'type' => 'StillImage',
                'url' => $photo['url'] ?? null,
                'license' => $photo['license_code'] ?? null,
                'attribution' => $photo['attribution'] ?? null,
            ], static fn (mixed $value): bool => $value !== null);
        }
        foreach ($record['sounds'] ?? [] as $sound) {
            if (is_array($sound)) {
                $media[] = array_filter(['type' => 'Sound', 'url' => $sound['file_url'] ?? null], static fn (mixed $value): bool => $value !== null);
            }
        }

        $coordinates = $record['geojson']['coordinates'] ?? null;

        return new NormalizedOccurrence(
            source: $this->key(),
            sourceOccurrenceId: $id,
            sourceDatasetId: null,
            scientificName: $taxon['name'] ?? null,
            vernacularName: $taxon['preferred_common_name'] ?? $record['species_guess'] ?? null,
            sourceTaxonId: isset($taxon['id']) ? (string) $taxon['id'] : null,
            classification: $classification,
            observedAt: $record['time_observed_at'] ?? $record['observed_on'] ?? null,
            sourceCreatedAt: $record['created_at'] ?? null,
            sourceUpdatedAt: $record['updated_at'] ?? null,
            publishedAt: $record['created_at'] ?? null,
            latitude: is_array($coordinates) && isset($coordinates[1]) ? (float) $coordinates[1] : null,
            longitude: is_array($coordinates) && isset($coordinates[0]) ? (float) $coordinates[0] : null,
            coordinateUncertaintyM: isset($record['public_positional_accuracy']) ? (float) $record['public_positional_accuracy'] : (isset($record['positional_accuracy']) ? (float) $record['positional_accuracy'] : null),
            individualCount: null,
            validationStatus: $record['quality_grade'] ?? null,
            observerName: $record['user']['login'] ?? $record['user']['name'] ?? null,
            license: $record['license_code'] ?? null,
            sourceUrl: $record['uri'] ?? "https://www.inaturalist.org/observations/{$id}",
            media: $media,
            rawData: $record,
        );
    }

    /** @return array<string, scalar|null> */
    private function parameters(OccurrenceQuery $query): array
    {
        $placeId = $query->department ?? $query->region;
        if ($placeId === null && strtoupper((string) $query->country) === 'FR') {
            $placeId = (string) self::FRANCE_PLACE_ID;
        }

        return [
            'taxon_id' => is_numeric($query->sourceTaxonId) ? (int) $query->sourceTaxonId : null,
            'taxon_name' => $query->sourceTaxonId === null ? $query->taxon : null,
            'd1' => $query->from,
            'd2' => $query->to,
            'place_id' => $placeId,
            'lat' => $query->radiusKm !== null ? $query->latitude : null,
            'lng' => $query->radiusKm !== null ? $query->longitude : null,
            'radius' => $query->radiusKm,
            'swlat' => $query->boundingBox['south'] ?? null,
            'swlng' => $query->boundingBox['west'] ?? null,
            'nelat' => $query->boundingBox['north'] ?? null,
            'nelng' => $query->boundingBox['east'] ?? null,
        ];
    }
}
