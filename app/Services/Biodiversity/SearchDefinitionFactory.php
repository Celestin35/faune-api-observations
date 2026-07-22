<?php

namespace App\Services\Biodiversity;

use App\Models\Taxon;
use Illuminate\Validation\ValidationException;

final class SearchDefinitionFactory
{
    /** @param array<string, mixed> $data */
    public function make(array $data): SearchDefinition
    {
        $zone = $data['zone'] ?? [];
        $type = $zone['type'] ?? null;
        if (! in_array($type, ['radius', 'departments'], true)) {
            throw ValidationException::withMessages(['zone.type' => 'La zone doit être un rayon ou une liste de départements.']);
        }
        if ($type === 'radius') {
            foreach (['latitude', 'longitude', 'radius_km'] as $field) {
                if (! isset($zone[$field]) || ! is_numeric($zone[$field])) {
                    throw ValidationException::withMessages(["zone.{$field}" => 'Valeur numérique requise.']);
                }
            }
            $zone = ['type' => 'radius', 'latitude' => (float) $zone['latitude'],
                'longitude' => (float) $zone['longitude'], 'radius_km' => (float) $zone['radius_km']];
            if ($zone['latitude'] < -90 || $zone['latitude'] > 90 || $zone['longitude'] < -180 || $zone['longitude'] > 180
                || $zone['radius_km'] <= 0 || $zone['radius_km'] > 200) {
                throw ValidationException::withMessages(['zone' => 'Le point ou le rayon est hors limites.']);
            }
        } else {
            $codes = array_values(array_unique(array_map('strval', $zone['department_codes'] ?? [])));
            if ($codes === []) {
                throw ValidationException::withMessages(['zone.department_codes' => 'Sélectionnez au moins un département.']);
            }
            $zone = ['type' => 'departments', 'department_codes' => $codes];
        }

        $sources = array_values(array_unique(array_map('strval', $data['sources'] ?? ['gbif', 'inaturalist'])));
        if ($sources === [] || array_diff($sources, ['gbif', 'inaturalist'])) {
            throw ValidationException::withMessages(['sources' => 'Les sources V0 sont gbif et inaturalist.']);
        }

        $from = (string) ($data['date_from'] ?? '');
        $to = (string) ($data['date_to'] ?? '');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $from > $to) {
            throw ValidationException::withMessages(['date_from' => 'Une période YYYY-MM-DD valide est requise.']);
        }

        $taxon = isset($data['taxon_id']) ? Taxon::find($data['taxon_id']) : null;
        if (isset($data['taxon_id']) && $taxon === null) {
            throw ValidationException::withMessages(['taxon_id' => 'Taxon inconnu.']);
        }

        return new SearchDefinition($taxon, $from, $to, $zone, $sources);
    }
}
