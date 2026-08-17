<?php

namespace App\Services\Biodiversity;

use App\Models\GeographicArea;
use App\Models\Taxon;
use DateTimeImmutable;
use Illuminate\Validation\ValidationException;

final class SearchDefinitionFactory
{
    public function __construct(private SourceCapabilityService $capabilities) {}

    /** @param array<string, mixed> $data */
    public function make(array $data): SearchDefinition
    {
        return $this->absoluteCriteria($data)->resolve();
    }

    /** @param array<string, mixed> $data */
    public function absoluteCriteria(array $data): ObservationQueryCriteria
    {
        [$taxon, $scope, $zone, $sources] = $this->common($data);
        $from = (string) ($data['date_from'] ?? '');
        $to = (string) ($data['date_to'] ?? '');
        if (! $this->isDate($from) || ! $this->isDate($to) || $from > $to) {
            throw ValidationException::withMessages(['date_from' => 'Une période YYYY-MM-DD valide est requise.']);
        }

        return new ObservationQueryCriteria(
            taxon: $taxon,
            taxonScope: $scope,
            taxonomicReferenceVersionId: $taxon?->taxref_version_id,
            taxonLabelSnapshot: $this->taxonLabel($taxon),
            periodType: 'absolute',
            dateFrom: $from,
            dateTo: $to,
            windowMinutes: null,
            zone: $zone,
            sources: $sources,
        );
    }

    /** @param array<string, mixed> $data */
    public function slidingCriteria(array $data): ObservationQueryCriteria
    {
        [$taxon, $scope, $zone, $sources] = $this->common($data);
        $window = filter_var($data['window_minutes'] ?? null, FILTER_VALIDATE_INT);
        if ($window === false || $window < 5) {
            throw ValidationException::withMessages(['window_minutes' => 'La fenêtre glissante doit être un entier d’au moins 5 minutes.']);
        }

        return new ObservationQueryCriteria(
            taxon: $taxon,
            taxonScope: $scope,
            taxonomicReferenceVersionId: $taxon?->taxref_version_id,
            taxonLabelSnapshot: $this->taxonLabel($taxon),
            periodType: 'sliding',
            dateFrom: null,
            dateTo: null,
            windowMinutes: $window,
            zone: $zone,
            sources: $sources,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{?Taxon, string, array<string, mixed>, list<string>}
     */
    private function common(array $data): array
    {
        $zone = $this->zone((array) ($data['zone'] ?? []));
        $sources = array_values(array_unique(array_map('strval', $data['sources'] ?? ['gbif', 'inaturalist'])));
        if ($sources === [] || array_diff($sources, ['gbif', 'inaturalist', 'faune-france'])) {
            throw ValidationException::withMessages(['sources' => 'Une source sélectionnée n’est pas disponible pour cette opération.']);
        }

        $taxon = isset($data['taxon_id']) ? Taxon::find($data['taxon_id']) : null;
        if (isset($data['taxon_id']) && $taxon === null) {
            throw ValidationException::withMessages(['taxon_id' => 'Taxon inconnu.']);
        }
        $scope = (string) ($data['taxon_scope'] ?? $taxon?->defaultScope() ?? 'subtree');
        if (! in_array($scope, ['exact', 'subtree'], true)) {
            throw ValidationException::withMessages(['taxon_scope' => 'Le scope taxonomique doit être exact ou subtree.']);
        }
        $this->capabilities->validate($sources, $taxon, $scope, $zone);

        return [$taxon, $scope, $zone, $sources];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function zone(array $input): array
    {
        $type = $input['type'] ?? null;
        if (! in_array($type, ['france', 'radius', 'departments'], true)) {
            throw ValidationException::withMessages(['zone.type' => 'La zone doit être la France entière, un rayon ou une liste de départements.']);
        }
        if ($type === 'france') {
            return ['type' => 'france'];
        }
        if ($type === 'radius') {
            foreach (['latitude', 'longitude', 'radius_km'] as $field) {
                if (! isset($input[$field]) || ! is_numeric($input[$field])) {
                    throw ValidationException::withMessages(["zone.{$field}" => 'Valeur numérique requise.']);
                }
            }
            $address = isset($input['address']) ? trim((string) $input['address']) : '';
            if (mb_strlen($address) > 255) {
                throw ValidationException::withMessages(['zone.address' => 'L’adresse ne peut pas dépasser 255 caractères.']);
            }
            $zone = [
                'type' => 'radius',
                'latitude' => (float) $input['latitude'],
                'longitude' => (float) $input['longitude'],
                'radius_km' => (float) $input['radius_km'],
            ];
            if ($address !== '') {
                $zone['address'] = $address;
            }
            if ($zone['latitude'] < -90 || $zone['latitude'] > 90 || $zone['longitude'] < -180 || $zone['longitude'] > 180
                || $zone['radius_km'] <= 0 || $zone['radius_km'] > 200) {
                throw ValidationException::withMessages(['zone' => 'Le point ou le rayon est hors limites.']);
            }

            return $zone;
        }

        if (! isset($input['department_codes']) || ! is_array($input['department_codes'])) {
            throw ValidationException::withMessages(['zone.department_codes' => 'Sélectionnez au moins un département.']);
        }
        $codes = array_values(array_unique(array_map(
            fn (mixed $code): string => str_pad(strtoupper(trim((string) $code)), 2, '0', STR_PAD_LEFT),
            $input['department_codes'],
        )));
        if ($codes === []) {
            throw ValidationException::withMessages(['zone.department_codes' => 'Sélectionnez au moins un département.']);
        }
        if (GeographicArea::query()->where('type', 'department')->whereIn('code', $codes)->count() !== count($codes)) {
            throw ValidationException::withMessages(['zone.department_codes' => 'Un département sélectionné n’est pas disponible.']);
        }

        return ['type' => 'departments', 'department_codes' => $codes];
    }

    private function taxonLabel(?Taxon $taxon): ?string
    {
        return $taxon === null
            ? null
            : ($taxon->preferred_french_name ?: $taxon->accepted_scientific_name ?: $taxon->vernacular_name ?: $taxon->scientific_name);
    }

    private function isDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
