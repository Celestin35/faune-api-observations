<?php

namespace App\Services\Biodiversity;

use App\Models\GeographicArea;
use App\Models\Taxon;
use App\Services\Biodiversity\FauneFrance\FauneFranceTaxonomicGroups;
use Illuminate\Validation\ValidationException;

final class SourceCapabilityService
{
    public function __construct(private readonly FauneFranceTaxonomicGroups $groups) {}

    /**
     * @param  array<string, mixed>  $zone
     * @return array{available: bool, estimable: bool, reason: string|null}
     */
    public function assess(string $source, ?Taxon $taxon, string $scope, array $zone): array
    {
        if (in_array($source, ['gbif', 'inaturalist'], true)) {
            return ['available' => true, 'estimable' => true, 'reason' => null];
        }
        if ($source !== 'faune-france') {
            return ['available' => false, 'estimable' => false, 'reason' => 'Cette source n’est pas prise en charge.'];
        }
        if ($taxon !== null) {
            $rank = $taxon->rank_code ?: $taxon->rank;
            if ($rank === 'species') {
                if ($scope !== 'exact') {
                    return ['available' => false, 'estimable' => false, 'reason' => 'Une espèce Faune-France doit être recherchée avec la portée exacte.'];
                }
                if (! $taxon->mappings()->where('source', 'faune_france')->where('mapping_status', 'validated')
                    ->where('is_preferred', true)->whereNull('valid_to')->exists()) {
                    return ['available' => false, 'estimable' => false, 'reason' => 'Cette espèce ne dispose pas encore d’un identifiant Faune-France validé.'];
                }
            } elseif ($scope !== 'subtree' || ! $this->groups->supports($taxon)) {
                return ['available' => false, 'estimable' => false, 'reason' => 'Ce taxon ne correspond pas à un groupe de recherche Faune-France pris en charge.'];
            }
        }

        if (($zone['type'] ?? null) === 'radius') {
            $latitude = (float) ($zone['latitude'] ?? 999);
            $longitude = (float) ($zone['longitude'] ?? 999);
            if ($latitude < 41 || $latitude > 51.5 || $longitude < -5.5 || $longitude > 10) {
                return ['available' => false, 'estimable' => false, 'reason' => 'Le connecteur point/rayon Faune-France est limité à la France métropolitaine.'];
            }
        } elseif (($zone['type'] ?? null) === 'departments') {
            $codes = (array) ($zone['department_codes'] ?? []);
            if ($codes === []) {
                return ['available' => false, 'estimable' => false, 'reason' => 'Sélectionnez au moins un département.'];
            }
            $portals = GeographicArea::query()->whereIn('code', $codes)->pluck('faune_portal')->unique()->values();
            if ($portals->count() !== 1 || $portals->first() !== 'faune_france') {
                return [
                    'available' => false,
                    'estimable' => false,
                    'reason' => $portals->count() > 1
                        ? 'La sélection couvre plusieurs portails Faune, ce qui n’est pas encore pris en charge.'
                        : 'Le connecteur du portail ultramarin sélectionné n’est pas encore disponible.',
                ];
            }
        }

        return $this->available();
    }

    /** @return array{available: bool, estimable: bool, reason: string} */
    private function available(): array
    {
        return [
            'available' => true,
            'estimable' => false,
            'reason' => 'Estimation indisponible pour Faune-France. Le nombre de résultats sera connu pendant la récupération.',
        ];
    }

    /** @param list<string> $sources @param array<string, mixed> $zone */
    public function validate(array $sources, ?Taxon $taxon, string $scope, array $zone): void
    {
        foreach ($sources as $source) {
            $capability = $this->assess($source, $taxon, $scope, $zone);
            if (! $capability['available']) {
                throw ValidationException::withMessages(['sources' => $capability['reason']]);
            }
        }
    }
}
