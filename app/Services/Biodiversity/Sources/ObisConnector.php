<?php

namespace App\Services\Biodiversity\Sources;

use App\Services\Biodiversity\Contracts\OccurrenceSourceConnector;
use App\Services\Biodiversity\Data\NormalizedOccurrence;
use App\Services\Biodiversity\Data\OccurrenceQuery;
use App\Services\Biodiversity\Data\SourceResult;
use App\Services\Biodiversity\Data\SpatialFilter;
use InvalidArgumentException;
use UnexpectedValueException;

final class ObisConnector extends AbstractHttpConnector implements OccurrenceSourceConnector
{
    public function key(): string
    {
        return 'obis';
    }

    protected function baseUrl(): string
    {
        return (string) config('biodiversity.sources.obis.base_url');
    }

    public function search(OccurrenceQuery $query, int $limit = 3): SourceResult
    {
        $parameters = $this->parameters($query) + ['size' => min(max($limit, 1), 20)];
        $payload = $this->get('/occurrence', $parameters)->json();

        if (! is_array($payload) || ! isset($payload['total']) || ! is_array($payload['results'] ?? null)) {
            throw new UnexpectedValueException('OBIS returned an unexpected occurrence response.');
        }

        return new SourceResult(
            source: $this->key(),
            total: (int) $payload['total'],
            occurrences: array_map(fn (array $record) => $this->normalize($record), $payload['results']),
            requestParameters: $parameters,
            quotaHeaders: $this->lastQuotaHeaders,
        );
    }

    public function count(OccurrenceQuery $query): int
    {
        $payload = $this->get('/occurrence', $this->parameters($query) + ['size' => 1])->json();

        if (! is_array($payload) || ! isset($payload['total'])) {
            throw new UnexpectedValueException('OBIS did not return a count.');
        }

        return (int) $payload['total'];
    }

    public function normalize(array $record): NormalizedOccurrence
    {
        $id = (string) ($record['occurrenceID'] ?? $record['id'] ?? '');
        if ($id === '') {
            throw new UnexpectedValueException('An OBIS record has no occurrence identifier.');
        }

        $classification = [];
        foreach (['kingdom', 'phylum', 'class', 'order', 'family', 'genus', 'species'] as $rank) {
            if (isset($record[$rank])) {
                $classification[$rank] = (string) $record[$rank];
            }
        }

        return new NormalizedOccurrence(
            source: $this->key(),
            sourceOccurrenceId: $id,
            sourceDatasetId: isset($record['dataset_id']) ? (string) $record['dataset_id'] : ($record['datasetID'] ?? null),
            scientificName: $record['scientificName'] ?? null,
            vernacularName: $record['vernacularName'] ?? null,
            sourceTaxonId: isset($record['aphiaID']) ? (string) $record['aphiaID'] : (isset($record['speciesid']) ? (string) $record['speciesid'] : null),
            classification: $classification,
            observedAt: $record['eventDate'] ?? null,
            sourceCreatedAt: $record['created'] ?? null,
            sourceUpdatedAt: $record['modified'] ?? null,
            publishedAt: null,
            latitude: isset($record['decimalLatitude']) ? (float) $record['decimalLatitude'] : null,
            longitude: isset($record['decimalLongitude']) ? (float) $record['decimalLongitude'] : null,
            coordinateUncertaintyM: isset($record['coordinateUncertaintyInMeters']) ? (float) $record['coordinateUncertaintyInMeters'] : null,
            individualCount: isset($record['individualCount']) && is_numeric($record['individualCount']) ? (int) $record['individualCount'] : null,
            validationStatus: $record['occurrenceStatus'] ?? null,
            observerName: $record['recordedBy'] ?? null,
            license: $record['license'] ?? null,
            sourceUrl: isset($record['id']) ? 'https://obis.org/occurrence/'.urlencode((string) $record['id']) : null,
            media: [],
            rawData: $record,
        );
    }

    /** @return array<string, scalar|null> */
    private function parameters(OccurrenceQuery $query): array
    {
        if ($query->country !== null) {
            throw new InvalidArgumentException('OBIS occurrence v3 has no country filter; use a bounding box/WKT or source-native area id.');
        }

        $geometry = null;
        if ($query->boundingBox !== null) {
            $geometry = SpatialFilter::boundingBoxWkt($query->boundingBox);
        } elseif ($query->latitude !== null && $query->longitude !== null && $query->radiusKm !== null) {
            $geometry = SpatialFilter::circleWkt($query->latitude, $query->longitude, $query->radiusKm);
        }

        return [
            'scientificname' => $query->taxon,
            'startdate' => $query->from,
            'enddate' => $query->to,
            'areaid' => $query->department ?? $query->region,
            'geometry' => $geometry,
        ];
    }
}
