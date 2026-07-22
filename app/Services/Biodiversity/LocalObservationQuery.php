<?php

namespace App\Services\Biodiversity;

use App\Models\GeographicArea;
use App\Models\Observation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class LocalObservationQuery
{
    public function query(SearchDefinition $definition): Builder
    {
        $query = Observation::query()->with(['taxon', 'sources'])
            ->whereBetween('observed_at', [$definition->dateFrom.' 00:00:00', $definition->dateTo.' 23:59:59']);
        if ($definition->taxon) {
            $query->where('taxon_id', $definition->taxon->id);
        }
        if ($definition->zoneType() === 'radius') {
            $degrees = $definition->zone['radius_km'] / 111;
            $query->whereBetween('latitude', [$definition->zone['latitude'] - $degrees, $definition->zone['latitude'] + $degrees])
                ->whereBetween('longitude', [$definition->zone['longitude'] - $degrees, $definition->zone['longitude'] + $degrees]);
        } else {
            $areas = GeographicArea::query()->whereIn('code', $definition->zone['department_codes'])->get();
            $query->where(function (Builder $outer) use ($areas): void {
                foreach ($areas as $area) {
                    $coordinates = $area->geometry_geojson['coordinates'][0] ?? [];
                    if ($coordinates === []) {
                        continue;
                    }
                    $lng = array_column($coordinates, 0);
                    $lat = array_column($coordinates, 1);
                    $outer->orWhere(function (Builder $part) use ($lng, $lat): void {
                        $part->whereBetween('latitude', [min($lat), max($lat)])->whereBetween('longitude', [min($lng), max($lng)]);
                    });
                }
            });
        }

        return $query;
    }

    public function results(SearchDefinition $definition): Collection
    {
        $results = $this->query($definition)->get();
        if ($definition->zoneType() !== 'radius') {
            return $results;
        }

        return $results->filter(fn (Observation $observation): bool => $this->distanceKm(
            $definition->zone['latitude'], $definition->zone['longitude'],
            (float) $observation->latitude, (float) $observation->longitude,
        ) <= $definition->zone['radius_km'])->values();
    }

    private function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);
        $a = sin($latDelta / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lonDelta / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
