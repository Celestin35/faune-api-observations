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
        public string $temporalPrecision = 'unknown',
        public string $locationStatus = 'unavailable',
        public ?string $sourceLocationPrecision = null,
        public ?string $countryCode = null,
        public ?string $countryName = null,
        public ?string $regionName = null,
        public ?string $departmentCode = null,
        public ?string $departmentName = null,
        public ?string $municipalityCode = null,
        public ?string $municipalityName = null,
        public ?string $localityName = null,
        public bool $observerIsPublic = false,
        public ?string $lifeStage = null,
        public ?string $sex = null,
        public ?string $behavior = null,
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
            'temporal_precision' => $this->temporalPrecision,
            'location_status' => $this->locationStatus,
            'source_location_precision' => $this->sourceLocationPrecision,
            'country_code' => $this->countryCode,
            'country_name' => $this->countryName,
            'region_name' => $this->regionName,
            'department_code' => $this->departmentCode,
            'department_name' => $this->departmentName,
            'municipality_code' => $this->municipalityCode,
            'municipality_name' => $this->municipalityName,
            'locality_name' => $this->localityName,
            'observer_is_public' => $this->observerIsPublic,
            'life_stage' => $this->lifeStage,
            'sex' => $this->sex,
            'behavior' => $this->behavior,
        ];
    }
}
