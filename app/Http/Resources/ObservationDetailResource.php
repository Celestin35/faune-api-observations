<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ObservationDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $lineage = $this->taxon?->ancestorPaths
            ?->where('depth', '>', 0)
            ->sortByDesc('depth')
            ->map(fn ($path): array => [
                'id' => $path->ancestor?->id,
                'frenchName' => $path->ancestor?->frenchName(),
                'scientificName' => $path->ancestor?->accepted_scientific_name ?: $path->ancestor?->scientific_name,
                'rank' => $path->ancestor?->rank_code ?: $path->ancestor?->rank,
            ])->values()->all() ?? [];

        return [
            'id' => $this->id,
            'taxon' => $this->taxon === null ? null : [
                'id' => $this->taxon->id,
                'frenchName' => $this->taxon->frenchName(),
                'scientificName' => $this->taxon->accepted_scientific_name ?: $this->taxon->scientific_name,
                'rank' => $this->taxon->rankDefinition?->label_fr ?: ($this->taxon->rank_code ?: $this->taxon->rank),
                'lineage' => $lineage,
            ],
            'observedAt' => $this->observed_at?->toIso8601String(),
            'temporalPrecision' => $this->temporal_precision,
            'location' => [
                'status' => $this->location_status,
                'latitude' => $this->latitude,
                'longitude' => $this->longitude,
                'uncertaintyM' => $this->coordinate_uncertainty_m,
                'elevationM' => $this->elevation_m,
                'elevationSource' => $this->elevation_source,
                'locality' => $this->locality_name ?: $this->location_name,
                'municipality' => $this->municipality_name,
                'municipalityCode' => $this->municipality_code,
                'department' => $this->department_name,
                'departmentCode' => $this->department_code,
                'region' => $this->region_name,
                'country' => $this->country_name,
                'countryCode' => $this->country_code,
                'resolutionMethod' => $this->geography_resolution_method,
            ],
            'individualCount' => $this->individual_count,
            'validationStatus' => $this->validation_status,
            'observerName' => $this->observer_name,
            'lifeStage' => $this->life_stage,
            'sex' => $this->sex,
            'behavior' => $this->behavior,
            'remarks' => $this->remarks,
            'firstImportedAt' => $this->first_imported_at?->toIso8601String(),
            'lastSeenAt' => $this->last_seen_at?->toIso8601String(),
            'sources' => ObservationSourceResource::collection($this->whenLoaded('sources')),
        ];
    }
}
