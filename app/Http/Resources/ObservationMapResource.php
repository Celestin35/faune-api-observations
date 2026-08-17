<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ObservationMapResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'taxon' => $this->taxon === null ? null : [
                'frenchName' => $this->taxon->frenchName(),
                'scientificName' => $this->taxon->accepted_scientific_name ?: $this->taxon->scientific_name,
                'vernacular_name' => $this->taxon->frenchName(),
                'scientific_name' => $this->taxon->accepted_scientific_name ?: $this->taxon->scientific_name,
            ],
            'observedAt' => $this->observed_at?->toIso8601String(),
            'observed_at' => $this->observed_at?->toIso8601String(),
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'validation_status' => $this->validation_status,
            'taxonGroup' => $this->taxon_map_group,
            'sources' => $this->whenLoaded('sources', fn () => $this->sources->map(fn ($source): array => [
                'source' => $source->source,
            ])->values()->all()),
        ];
    }
}
