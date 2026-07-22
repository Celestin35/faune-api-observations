<?php

namespace App\Services\Biodiversity;

use App\Models\GeographicArea;
use App\Models\TaxonSourceMapping;
use App\Services\Biodiversity\Data\OccurrenceQuery;
use Illuminate\Validation\ValidationException;

final class SearchQueryFactory
{
    /** @return list<OccurrenceQuery> */
    public function forSource(SearchDefinition $definition, string $source): array
    {
        $taxon = $definition->taxon?->scientific_name ?? 'Animalia';
        $sourceTaxonId = $definition->taxon?->mappings()
            ->where('source', TaxonSourceMapping::normalizeSource($source))
            ->where('mapping_status', 'validated')->where('is_preferred', true)->whereNull('valid_to')
            ->value('source_taxon_id');
        if ($definition->zoneType() === 'radius') {
            return [new OccurrenceQuery(taxon: $taxon, from: $definition->dateFrom, to: $definition->dateTo,
                latitude: $definition->zone['latitude'], longitude: $definition->zone['longitude'],
                radiusKm: $definition->zone['radius_km'], sourceTaxonId: $sourceTaxonId)];
        }

        $areas = GeographicArea::query()->whereIn('code', $definition->zone['department_codes'])->get()->keyBy('code');
        if ($areas->count() !== count($definition->zone['department_codes'])) {
            throw ValidationException::withMessages(['zone.department_codes' => 'Un département demandé ne fait pas partie du référentiel géographique.']);
        }

        return array_map(function (string $code) use ($areas, $source, $taxon, $definition, $sourceTaxonId): OccurrenceQuery {
            $area = $areas[$code];
            $native = (string) ($source === 'gbif' ? ($area->gadm_gid ?? '') : ($area->inaturalist_place_id ?? ''));
            if ($native !== '') {
                return new OccurrenceQuery(taxon: $taxon, from: $definition->dateFrom, to: $definition->dateTo,
                    department: $native, sourceTaxonId: $sourceTaxonId);
            }
            $bbox = $this->bbox($area->geometry_geojson);

            return new OccurrenceQuery(taxon: $taxon, from: $definition->dateFrom, to: $definition->dateTo,
                boundingBox: $bbox, sourceTaxonId: $sourceTaxonId);
        }, $definition->zone['department_codes']);
    }

    /** @param array<string, mixed>|null $geometry @return array{south: float, west: float, north: float, east: float} */
    private function bbox(?array $geometry): array
    {
        $coordinates = $geometry['coordinates'][0] ?? [];
        if ($coordinates === []) {
            throw ValidationException::withMessages(['zone' => 'La zone locale ne dispose pas de géométrie ni d’identifiant source.']);
        }
        $lng = array_column($coordinates, 0);
        $lat = array_column($coordinates, 1);

        return ['south' => (float) min($lat), 'west' => (float) min($lng),
            'north' => (float) max($lat), 'east' => (float) max($lng)];
    }
}
