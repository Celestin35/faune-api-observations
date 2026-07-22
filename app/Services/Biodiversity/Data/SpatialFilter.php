<?php

namespace App\Services\Biodiversity\Data;

final class SpatialFilter
{
    /** @param array{south: float, west: float, north: float, east: float} $box */
    public static function boundingBoxWkt(array $box): string
    {
        return sprintf(
            'POLYGON((%.6F %.6F,%.6F %.6F,%.6F %.6F,%.6F %.6F,%.6F %.6F))',
            $box['west'], $box['south'],
            $box['east'], $box['south'],
            $box['east'], $box['north'],
            $box['west'], $box['north'],
            $box['west'], $box['south'],
        );
    }

    /**
     * Approximate a geodesic circle with a small WKT polygon. It is used only
     * where the source supports WKT but has no native radius parameter.
     */
    public static function circleWkt(float $latitude, float $longitude, float $radiusKm, int $segments = 24): string
    {
        $points = [];
        $latRadians = deg2rad($latitude);

        for ($index = 0; $index <= $segments; $index++) {
            $angle = 2 * M_PI * $index / $segments;
            $deltaLat = ($radiusKm / 111.32) * sin($angle);
            $deltaLng = ($radiusKm / max(0.01, 111.32 * cos($latRadians))) * cos($angle);
            $points[] = sprintf('%.6F %.6F', $longitude + $deltaLng, $latitude + $deltaLat);
        }

        return 'POLYGON(('.implode(',', $points).'))';
    }
}
