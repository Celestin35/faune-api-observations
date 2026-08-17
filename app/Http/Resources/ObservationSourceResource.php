<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ObservationSourceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'source' => $this->source,
            'occurrenceId' => $this->source_occurrence_id,
            'datasetId' => $this->source_dataset_id,
            'taxonId' => $this->source_taxon_id,
            'scientificName' => $this->source_scientific_name,
            'vernacularName' => $this->source_vernacular_name,
            'url' => $this->source_url,
            'license' => $this->license,
            'observedAt' => $this->source_observed_at?->toIso8601String(),
            'temporalPrecision' => $this->source_temporal_precision,
            'location' => [
                'status' => $this->location_status,
                'latitude' => $this->public_latitude,
                'longitude' => $this->public_longitude,
                'uncertaintyM' => $this->coordinate_uncertainty_m,
                'sourcePrecision' => $this->source_location_precision,
                'locality' => $this->source_location_name,
                'municipality' => $this->source_municipality_name,
                'department' => $this->source_department_name,
                'departmentCode' => $this->source_department_code,
                'region' => $this->source_region_name,
                'country' => $this->source_country_name,
                'countryCode' => $this->source_country_code,
            ],
            'observerName' => $this->observer_is_public ? $this->source_observer_name : null,
            'individualCount' => $this->source_individual_count,
            'validationStatus' => $this->source_validation_status,
            'lifeStage' => $this->life_stage,
            'sex' => $this->sex,
            'behavior' => $this->behavior,
            'remarks' => $this->remarks,
            'sourceCreatedAt' => $this->source_created_at?->toIso8601String(),
            'sourceUpdatedAt' => $this->source_updated_at?->toIso8601String(),
            'publishedAt' => $this->published_at?->toIso8601String(),
            'importedAt' => $this->created_at?->toIso8601String(),
            'media' => $this->whenLoaded('media', fn (): array => $this->media->map(fn ($media): array => [
                'type' => $media->media_type,
                'url' => $media->url,
                'thumbnailUrl' => $media->thumbnail_url,
                'sourcePageUrl' => $media->source_page_url,
                'license' => $media->license,
                'attribution' => $media->attribution,
            ])->all()),
        ];
    }
}
