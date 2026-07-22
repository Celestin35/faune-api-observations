<?php

namespace App\Services\Biodiversity\Data;

use JsonSerializable;

final readonly class NormalizedOccurrence implements JsonSerializable
{
    /**
     * @param  array<string, string|null>  $classification
     * @param  list<array<string, mixed>>  $media
     * @param  array<string, mixed>  $rawData
     */
    public function __construct(
        public string $source,
        public string $sourceOccurrenceId,
        public ?string $sourceDatasetId,
        public ?string $scientificName,
        public ?string $vernacularName,
        public ?string $sourceTaxonId,
        public array $classification,
        public ?string $observedAt,
        public ?string $sourceCreatedAt,
        public ?string $sourceUpdatedAt,
        public ?string $publishedAt,
        public ?float $latitude,
        public ?float $longitude,
        public ?float $coordinateUncertaintyM,
        public ?int $individualCount,
        public ?string $validationStatus,
        public ?string $observerName,
        public ?string $license,
        public ?string $sourceUrl,
        public array $media,
        public array $rawData,
        public ?string $locationName = null,
        public ?string $remarks = null,
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'source' => $this->source,
            'source_occurrence_id' => $this->sourceOccurrenceId,
            'source_dataset_id' => $this->sourceDatasetId,
            'scientific_name' => $this->scientificName,
            'vernacular_name' => $this->vernacularName,
            'source_taxon_id' => $this->sourceTaxonId,
            'classification' => $this->classification,
            'observed_at' => $this->observedAt,
            'source_created_at' => $this->sourceCreatedAt,
            'source_updated_at' => $this->sourceUpdatedAt,
            'published_at' => $this->publishedAt,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'coordinate_uncertainty_m' => $this->coordinateUncertaintyM,
            'individual_count' => $this->individualCount,
            'validation_status' => $this->validationStatus,
            'observer_name' => $this->observerName,
            'license' => $this->license,
            'source_url' => $this->sourceUrl,
            'media' => $this->media,
            'raw_data' => $this->rawData,
            'location_name' => $this->locationName,
            'remarks' => $this->remarks,
        ];
    }
}
