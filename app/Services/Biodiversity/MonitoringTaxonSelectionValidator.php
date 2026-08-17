<?php

namespace App\Services\Biodiversity;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MonitoringTaxonSelectionValidator
{
    /** @param list<ObservationQueryCriteria> $criteria */
    public function validate(array $criteria): void
    {
        if ($criteria === []) {
            throw ValidationException::withMessages([
                'taxa' => 'Sélectionnez au moins une espèce ou un groupe à surveiller.',
            ]);
        }

        $ids = [];
        foreach ($criteria as $selection) {
            if ($selection->taxon === null) {
                throw ValidationException::withMessages([
                    'taxa' => 'La surveillance de tous les animaux n’est pas disponible. Sélectionnez des espèces ou des groupes.',
                ]);
            }
            $scientificName = $selection->taxon->accepted_scientific_name ?: $selection->taxon->scientific_name;
            if (mb_strtolower(trim((string) $scientificName)) === 'animalia') {
                throw ValidationException::withMessages([
                    'taxa' => 'Le groupe « Tous les animaux » n’est pas disponible dans les surveillances.',
                ]);
            }
            if (isset($ids[$selection->taxon->id])) {
                throw ValidationException::withMessages(['taxa' => 'Un même taxon ne peut être sélectionné qu’une seule fois.']);
            }
            $ids[$selection->taxon->id] = true;
        }

        foreach ($criteria as $leftIndex => $left) {
            foreach (array_slice($criteria, $leftIndex + 1) as $right) {
                if ($this->contains($left, $right) || $this->contains($right, $left)) {
                    throw ValidationException::withMessages([
                        'taxa' => sprintf(
                            'Les sélections « %s » et « %s » se recouvrent. Retirez la plus précise ou utilisez une portée exacte.',
                            $left->taxonLabelSnapshot,
                            $right->taxonLabelSnapshot,
                        ),
                    ]);
                }
            }
        }
    }

    private function contains(ObservationQueryCriteria $possibleAncestor, ObservationQueryCriteria $possibleDescendant): bool
    {
        if ($possibleAncestor->taxonScope !== 'subtree') {
            return false;
        }

        return DB::table('taxon_paths')
            ->where('ancestor_taxon_id', $possibleAncestor->taxon->id)
            ->where('descendant_taxon_id', $possibleDescendant->taxon->id)
            ->exists();
    }
}
