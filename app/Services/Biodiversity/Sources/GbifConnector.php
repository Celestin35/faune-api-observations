<?php

namespace App\Services\Biodiversity\Sources;

use App\Services\Biodiversity\Contracts\OccurrenceSourceConnector;
use App\Services\Biodiversity\Data\NormalizedOccurrence;
use App\Services\Biodiversity\Data\OccurrenceQuery;
use App\Services\Biodiversity\Data\SourceResult;
use App\Services\Biodiversity\Data\SpatialFilter;
use UnexpectedValueException;

final class GbifConnector extends AbstractHttpConnector implements OccurrenceSourceConnector
{
    /** @var array<string, int> */
    private array $taxonKeys = [];

    public function key(): string
    {
        return 'gbif';
    }

    protected function baseUrl(): string
    {
        return (string) config('biodiversity.sources.gbif.base_url');
    }

    public function search(OccurrenceQuery $query, int $limit = 3): SourceResult
    {
        return $this->fetchPage($query, min(max($limit, 1), 20));
    }

    public function fetchPage(OccurrenceQuery $query, int $limit = 300, int $offset = 0): SourceResult
    {
        if ($offset < 0 || $offset + $limit > 100000) {
            throw new \InvalidArgumentException('GBIF pagination must remain inside the 100,000 record window.');
        }

        $parameters = $this->parameters($query) + ['limit' => min(max($limit, 1), 300), 'offset' => $offset];
        $response = $this->get('/occurrence/search', $parameters);
        $payload = $response->json();

        if (! is_array($payload) || ! isset($payload['count']) || ! is_array($payload['results'] ?? null)) {
            throw new UnexpectedValueException('GBIF returned an unexpected occurrence response.');
        }

        return new SourceResult(
            source: $this->key(),
            total: (int) $payload['count'],
            occurrences: array_map(fn (array $record) => $this->normalize($record), $payload['results']),
            requestParameters: $parameters,
            quotaHeaders: $this->lastQuotaHeaders,
        );
    }

    public function count(OccurrenceQuery $query): int
    {
        $parameters = $this->parameters($query) + ['limit' => 0];
        $payload = $this->get('/occurrence/search', $parameters)->json();

        if (! is_array($payload) || ! isset($payload['count'])) {
            throw new UnexpectedValueException('GBIF did not return a count.');
        }

        return (int) $payload['count'];
    }

    public function countINaturalistDataset(OccurrenceQuery $query): int
    {
        $parameters = $this->parameters($query) + [
            'datasetKey' => (string) config('biodiversity.inaturalist_gbif_dataset_key'),
            'limit' => 0,
        ];
        $payload = $this->get('/occurrence/search', $parameters)->json();

        if (! is_array($payload) || ! isset($payload['count'])) {
            throw new UnexpectedValueException('GBIF did not return the iNaturalist dataset count.');
        }

        return (int) $payload['count'];
    }

    /** @return list<array<string, mixed>> */
    public function searchTaxa(string $query, int $limit = 10): array
    {
        $payload = $this->get('/species/search', ['q' => $query, 'limit' => min(max($limit, 1), 20)])->json();

        if (! is_array($payload) || ! is_array($payload['results'] ?? null)) {
            throw new UnexpectedValueException('GBIF returned an unexpected species response.');
        }

        return $payload['results'];
    }

    public function normalize(array $record): NormalizedOccurrence
    {
        $key = (string) ($record['key'] ?? '');
        $occurrenceId = (string) ($record['occurrenceID'] ?? $key);

        if ($occurrenceId === '') {
            throw new UnexpectedValueException('A GBIF record has no occurrence identifier.');
        }

        $classification = [];
        foreach (['kingdom', 'phylum', 'class', 'order', 'family', 'genus', 'species'] as $rank) {
            if (isset($record[$rank])) {
                $classification[$rank] = (string) $record[$rank];
            }
        }

        $media = array_values(array_map(
            static fn (array $item): array => array_filter([
                'type' => $item['type'] ?? null,
                'url' => $item['identifier'] ?? null,
                'page_url' => $item['references'] ?? null,
                'license' => $item['license'] ?? null,
            ], static fn (mixed $value): bool => $value !== null),
            is_array($record['media'] ?? null) ? $record['media'] : [],
        ));

        return new NormalizedOccurrence(
            source: $this->key(),
            sourceOccurrenceId: $occurrenceId,
            sourceDatasetId: isset($record['datasetKey']) ? (string) $record['datasetKey'] : null,
            scientificName: $record['scientificName'] ?? null,
            vernacularName: $record['vernacularName'] ?? null,
            sourceTaxonId: isset($record['taxonKey']) ? (string) $record['taxonKey'] : null,
            classification: $classification,
            observedAt: $record['eventDate'] ?? null,
            sourceCreatedAt: $record['created'] ?? null,
            sourceUpdatedAt: $record['modified'] ?? null,
            publishedAt: $record['lastCrawled'] ?? null,
            latitude: isset($record['decimalLatitude']) ? (float) $record['decimalLatitude'] : null,
            longitude: isset($record['decimalLongitude']) ? (float) $record['decimalLongitude'] : null,
            coordinateUncertaintyM: isset($record['coordinateUncertaintyInMeters']) ? (float) $record['coordinateUncertaintyInMeters'] : null,
            individualCount: isset($record['individualCount']) && is_numeric($record['individualCount']) ? (int) $record['individualCount'] : null,
            validationStatus: $record['identificationVerificationStatus'] ?? $record['occurrenceStatus'] ?? null,
            observerName: is_array($record['recordedBy'] ?? null) ? implode(', ', $record['recordedBy']) : ($record['recordedBy'] ?? null),
            license: $record['license'] ?? null,
            sourceUrl: $record['references'] ?? ($key !== '' ? "https://www.gbif.org/occurrence/{$key}" : null),
            media: $media,
            rawData: $record,
        );
    }

    /** @return array<string, scalar|null> */
    private function parameters(OccurrenceQuery $query): array
    {
        $geometry = null;
        if ($query->boundingBox !== null) {
            $geometry = SpatialFilter::boundingBoxWkt($query->boundingBox);
        } elseif ($query->latitude !== null && $query->longitude !== null && $query->radiusKm !== null) {
            $geometry = SpatialFilter::circleWkt($query->latitude, $query->longitude, $query->radiusKm);
        }

        return [
            // taxonKey includes descendants, which is essential for genus,
            // family and Animalia searches. scientificName=Animalia returned 0
            // in the live audit because it only targets that name literally.
            'taxonKey' => is_numeric($query->sourceTaxonId) ? (int) $query->sourceTaxonId : $this->resolveTaxonKey($query->taxon),
            'eventDate' => $query->from !== null ? $query->from.','.$query->to : null,
            'country' => $query->country,
            'geometry' => $geometry,
            'gadmGid' => $query->department ?? $query->region,
        ];
    }

    private function resolveTaxonKey(?string $name): ?int
    {
        if ($name === null || $name === '') {
            return null;
        }

        if (isset($this->taxonKeys[$name])) {
            return $this->taxonKeys[$name];
        }

        $payload = $this->get('/species/match', ['name' => $name])->json();
        $key = is_array($payload) ? ($payload['usageKey'] ?? $payload['speciesKey'] ?? null) : null;

        if (! is_numeric($key)) {
            throw new UnexpectedValueException("GBIF could not resolve taxon: {$name}");
        }

        return $this->taxonKeys[$name] = (int) $key;
    }
}
