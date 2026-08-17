<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ObservationHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'taxon' => $this->taxon === null ? null : [
                'id' => $this->taxon->id,
                'frenchName' => $this->taxon->frenchName(),
                'scientificName' => $this->taxon->accepted_scientific_name ?: $this->taxon->scientific_name,
            ],
            'observedAt' => $this->observed_at?->toIso8601String(),
            'location' => [
                'status' => $this->location_status,
                'latitude' => $this->latitude,
                'longitude' => $this->longitude,
                'locality' => $this->locality_name ?: $this->location_name,
                'municipality' => $this->municipality_name,
                'department' => $this->department_name,
                'departmentCode' => $this->department_code,
                'region' => $this->region_name,
            ],
            'individualCount' => $this->individual_count,
            'validationStatus' => $this->validation_status,
            'historyAt' => $this->history_at,
            'sources' => $this->whenLoaded('sources', fn () => $this->sources->map(fn ($source): array => [
                'source' => $source->source,
            ])->values()->all()),
        ];
    }
}
