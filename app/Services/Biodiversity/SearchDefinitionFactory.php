<?php

namespace App\Services\Biodiversity;

use App\Models\GeographicArea;
use App\Models\Taxon;
use Illuminate\Validation\ValidationException;

final class SearchDefinitionFactory
{
    /** @param array<string, mixed> $data */
    public function make(array $data, bool $allowFauneFrance = false): SearchDefinition
    {
        $areas = collect();
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
            $address = isset($zone['address']) ? trim((string) $zone['address']) : '';
            if (strlen($address) > 255) {
                throw ValidationException::withMessages(['zone.address' => 'L’adresse ne peut pas dépasser 255 caractères.']);
            }
            $zone = ['type' => 'radius', 'latitude' => (float) $zone['latitude'],
                'longitude' => (float) $zone['longitude'], 'radius_km' => (float) $zone['radius_km']];
            if ($address !== '') {
                $zone['address'] = $address;
            }
            if ($zone['latitude'] < -90 || $zone['latitude'] > 90 || $zone['longitude'] < -180 || $zone['longitude'] > 180
                || $zone['radius_km'] <= 0 || $zone['radius_km'] > 200) {
                throw ValidationException::withMessages(['zone' => 'Le point ou le rayon est hors limites.']);
            }
        } else {
            if (! isset($zone['department_codes']) || ! is_array($zone['department_codes'])) {
                throw ValidationException::withMessages(['zone.department_codes' => 'Sélectionnez au moins un département.']);
            }
            $codes = array_values(array_unique(array_map(
                fn (mixed $code): string => str_pad(strtoupper(trim((string) $code)), 2, '0', STR_PAD_LEFT),
                $zone['department_codes']
            )));
            if ($codes === []) {
                throw ValidationException::withMessages(['zone.department_codes' => 'Sélectionnez au moins un département.']);
            }
            $areas = GeographicArea::query()->where('type', 'department')->whereIn('code', $codes)->get();
            if ($areas->count() !== count($codes)) {
                throw ValidationException::withMessages(['zone.department_codes' => 'Un département sélectionné n’est pas disponible.']);
            }
            $zone = ['type' => 'departments', 'department_codes' => $codes];
        }

        $sources = array_values(array_unique(array_map('strval', $data['sources'] ?? ['gbif', 'inaturalist'])));
        $allowedSources = $allowFauneFrance ? ['gbif', 'inaturalist', 'faune-france'] : ['gbif', 'inaturalist'];
        if ($sources === [] || array_diff($sources, $allowedSources)) {
            throw ValidationException::withMessages(['sources' => 'Une source sélectionnée n’est pas disponible pour cette opération.']);
        }
        if (in_array('faune-france', $sources, true)) {
            if ($type !== 'departments') {
                throw ValidationException::withMessages(['sources' => 'Faune-France est disponible uniquement avec une sélection de départements métropolitains.']);
            }
            $portals = $areas->pluck('faune_portal')->unique()->values();
            if ($portals->count() !== 1 || $portals->first() !== 'faune_france') {
                $message = $portals->count() > 1
                    ? 'La sélection couvre plusieurs portails Faune : la source Faune-France est temporairement indisponible.'
                    : 'Le connecteur du portail ultramarin sélectionné n’est pas encore disponible.';
                throw ValidationException::withMessages(['sources' => $message.' Utilisez GBIF et/ou iNaturalist.']);
            }
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
        if (in_array('faune-france', $sources, true)
            && ($taxon === null || ! $taxon->mappings()->where('source', 'faune_france')
                ->where('mapping_status', 'validated')->where('is_preferred', true)->exists())) {
            throw ValidationException::withMessages(['taxon_id' => 'Ce taxon ne dispose pas encore d’un identifiant Faune-France.']);
        }
        if (in_array('faune-france', $sources, true) && $taxon?->rank !== 'species') {
            throw ValidationException::withMessages(['taxon_id' => 'Le bot Faune-France accepte actuellement uniquement les taxons de rang espèce.']);
        }

        $scope = (string) ($data['taxon_scope'] ?? $taxon?->defaultScope() ?? 'subtree');
        if (! in_array($scope, ['exact', 'subtree'], true)) {
            throw ValidationException::withMessages(['taxon_scope' => 'Le scope taxonomique doit être exact ou subtree.']);
        }
        if (in_array('faune-france', $sources, true) && $scope !== 'exact') {
            throw ValidationException::withMessages(['taxon_scope' => 'Faune-France accepte uniquement une espèce exacte.']);
        }

        return new SearchDefinition($taxon, $from, $to, $zone, $sources, $scope, $taxon?->taxref_version_id);
    }
}
