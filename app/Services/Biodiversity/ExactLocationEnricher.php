<?php

namespace App\Services\Biodiversity;

use App\Models\Observation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class ExactLocationEnricher
{
    /**
     * @param  Collection<int, Observation>  $observations
     * @return array{processed: int, elevations: int, municipalities: int}
     */
    public function enrich(Collection $observations): array
    {
        $observations = $observations
            ->filter(fn (Observation $observation): bool => in_array($observation->location_status, ['exact', 'approximate'], true)
                && $observation->latitude !== null && $observation->longitude !== null)
            ->values();

        if ($observations->isEmpty()) {
            return ['processed' => 0, 'elevations' => 0, 'municipalities' => 0];
        }

        $elevations = $this->elevations($observations
            ->where('location_status', 'exact')
            ->whereNull('elevation_m')
            ->values());
        $municipalities = 0;

        foreach ($observations as $observation) {
            $needsAdministrativeData = $observation->municipality_name === null
                || $observation->department_name === null
                || $observation->region_name === null;
            $administrativeLookupCompleted = ! $needsAdministrativeData;

            if ($needsAdministrativeData) {
                $administrative = $this->administrativeLocation($observation);
                $administrativeLookupCompleted = true;
                if ($administrative !== null) {
                    $attributes = array_filter([
                        'municipality_name' => $observation->municipality_name === null ? $administrative['municipalityName'] : null,
                        'municipality_code' => $observation->municipality_code === null ? $administrative['municipalityCode'] : null,
                        'department_name' => $observation->department_name === null ? $administrative['departmentName'] : null,
                        'department_code' => $observation->department_code === null ? $administrative['departmentCode'] : null,
                        'region_name' => $observation->region_name === null ? $administrative['regionName'] : null,
                        'country_name' => $observation->country_name === null ? 'France' : null,
                        'country_code' => $observation->country_code === null ? 'FR' : null,
                    ], static fn (mixed $value): bool => $value !== null && $value !== '');

                    if ($attributes !== []) {
                        $attributes['geography_resolution_method'] = $observation->geography_resolution_method === 'source'
                            ? 'source+official' : 'official';
                        $attributes['geography_resolved_at'] = now();
                        $observation->update($attributes);
                        $municipalities++;
                    }
                }
            }

            $elevationLookupCompleted = $observation->location_status !== 'exact'
                || $observation->elevation_m !== null
                || array_key_exists($observation->id, $elevations);
            if ($elevationLookupCompleted && $administrativeLookupCompleted) {
                $observation->update(['geography_enrichment_attempted_at' => now()]);
            }
        }

        return [
            'processed' => $observations->count(),
            'elevations' => count(array_filter($elevations, static fn (?float $value): bool => $value !== null)),
            'municipalities' => $municipalities,
        ];
    }

    /**
     * @param  Collection<int, Observation>  $observations
     * @return array<int, float|null>
     */
    private function elevations(Collection $observations): array
    {
        if ($observations->isEmpty()) {
            return [];
        }

        try {
            $response = Http::acceptJson()->timeout(15)->retry(2, 250, throw: false)->get(
                (string) config('biodiversity.elevation_url'),
                [
                    'lon' => $observations->pluck('longitude')->implode('|'),
                    'lat' => $observations->pluck('latitude')->implode('|'),
                    'resource' => (string) config('biodiversity.elevation_resource'),
                    'delimiter' => '|',
                    'zonly' => 'false',
                ],
            );
            $response->throw();
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Le service altimétrique IGN est inaccessible.', previous: $exception);
        }

        $items = $response->json('elevations');
        if (! is_array($items) || count($items) !== $observations->count()) {
            throw new RuntimeException('Le service altimétrique IGN a renvoyé une réponse incomplète.');
        }

        $resolved = [];
        foreach ($observations as $index => $observation) {
            $value = $items[$index]['z'] ?? null;
            $elevation = is_numeric($value) && (float) $value > -1000 ? (float) $value : null;
            $resolved[$observation->id] = $elevation;
            if ($elevation !== null) {
                $observation->update([
                    'elevation_m' => $elevation,
                    'elevation_source' => 'IGN RGE ALTI',
                    'elevation_resolved_at' => now(),
                ]);
            }
        }

        return $resolved;
    }

    /** @return array{municipalityName: string|null, municipalityCode: string|null, departmentName: string|null, departmentCode: string|null, regionName: string|null}|null */
    private function administrativeLocation(Observation $observation): ?array
    {
        try {
            $response = Http::acceptJson()->timeout(8)->retry(2, 200, throw: false)->get(
                (string) config('biodiversity.administrative_geocoding_url'),
                [
                    'lat' => $observation->latitude,
                    'lon' => $observation->longitude,
                    'fields' => 'nom,code,codeDepartement,departement,region',
                    'format' => 'json',
                ],
            );
            $response->throw();
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Le service du découpage administratif est inaccessible.', previous: $exception);
        }

        $payload = $response->json();
        $item = is_array($payload) ? ($payload[0] ?? null) : null;
        if ($item === null) {
            return null;
        }
        if (! is_array($item)) {
            throw new RuntimeException('Le service du découpage administratif a renvoyé une réponse invalide.');
        }

        return [
            'municipalityName' => $this->text($item['nom'] ?? null),
            'municipalityCode' => $this->text($item['code'] ?? null),
            'departmentName' => $this->text($item['departement']['nom'] ?? null),
            'departmentCode' => $this->text($item['codeDepartement'] ?? $item['departement']['code'] ?? null),
            'regionName' => $this->text($item['region']['nom'] ?? null),
        ];
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
