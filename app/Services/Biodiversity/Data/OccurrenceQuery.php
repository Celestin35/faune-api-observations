<?php

namespace App\Services\Biodiversity\Data;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class OccurrenceQuery
{
    /**
     * Department and region are deliberately source-native identifiers:
     * GADM gid for GBIF, place_id for iNaturalist. Their vocabularies differ.
     *
     * @param  array{south: float, west: float, north: float, east: float}|null  $boundingBox
     */
    public function __construct(
        public ?string $taxon = null,
        public ?string $from = null,
        public ?string $to = null,
        public ?string $country = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?float $radiusKm = null,
        public ?array $boundingBox = null,
        public ?string $department = null,
        public ?string $region = null,
    ) {
        if (($latitude === null) !== ($longitude === null)) {
            throw new InvalidArgumentException('Latitude and longitude must be provided together.');
        }

        if ($latitude !== null && ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180)) {
            throw new InvalidArgumentException('Point coordinates are outside WGS84 bounds.');
        }

        if ($radiusKm !== null && ($latitude === null || $radiusKm <= 0 || $radiusKm > 200)) {
            throw new InvalidArgumentException('A radius from 0 to 200 km requires a point.');
        }

        if ($boundingBox !== null) {
            if ($boundingBox['south'] < -90 || $boundingBox['north'] > 90
                || $boundingBox['west'] < -180 || $boundingBox['east'] > 180
                || $boundingBox['south'] >= $boundingBox['north'] || $boundingBox['west'] >= $boundingBox['east']) {
                throw new InvalidArgumentException('Invalid WGS84 bounding box.');
            }
        }

        if ($department !== null && $region !== null) {
            throw new InvalidArgumentException('Choose a department or a region, not both.');
        }

        if (($from === null) !== ($to === null)) {
            throw new InvalidArgumentException('A temporal filter requires both from and to dates.');
        }

        if ($from !== null && (! $this->isDate($from) || ! $this->isDate((string) $to) || $from > $to)) {
            throw new InvalidArgumentException('Dates must use YYYY-MM-DD and from must not exceed to.');
        }
    }

    public static function franceTichodromeLastThirtyDays(): self
    {
        return new self(
            taxon: 'Tichodroma muraria',
            from: now()->subDays(30)->toDateString(),
            to: now()->toDateString(),
            country: 'FR',
        );
    }

    private function isDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
