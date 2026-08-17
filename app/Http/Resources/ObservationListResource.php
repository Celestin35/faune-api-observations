<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ObservationListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'taxon' => $this->taxon === null ? null : [
                'id' => $this->taxon->id,
                'frenchName' => $this->taxon->preferred_french_name ?: $this->taxon->vernacular_name,
                'scientificName' => $this->taxon->accepted_scientific_name ?: $this->taxon->scientific_name,
                // Compatibility with the existing map while it migrates to the explicit contract.
                'vernacular_name' => $this->taxon->preferred_french_name ?: $this->taxon->vernacular_name,
                'scientific_name' => $this->taxon->accepted_scientific_name ?: $this->taxon->scientific_name,
            ],
            'observedAt' => $this->observed_at?->toIso8601String(),
            'observed_at' => $this->observed_at?->toIso8601String(),
            'temporalPrecision' => $this->temporal_precision,
            'location' => [
                'status' => $this->location_status,
                'latitude' => $this->latitude,
                'longitude' => $this->longitude,
                'uncertaintyM' => $this->coordinate_uncertainty_m,
                'locality' => $this->locality_name ?: $this->location_name,
                'municipality' => $this->municipality_name,
                'department' => $this->department_name,
                'departmentCode' => $this->department_code,
                'region' => $this->region_name,
            ],
            // Compatibility with MapView's current point contract.
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'validationStatus' => $this->validation_status,
            'validation_status' => $this->validation_status,
            'individualCount' => $this->individual_count,
            'historyAt' => $this->history_at,
            'sources' => ObservationSourceResource::collection($this->whenLoaded('sources')),
        ];
    }
}
