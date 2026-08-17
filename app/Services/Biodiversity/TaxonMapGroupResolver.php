<?php

namespace App\Services\Biodiversity;

use App\Models\Observation;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

final class TaxonMapGroupResolver
{
    /** @param EloquentCollection<int, Observation> $observations */
    public function apply(EloquentCollection $observations): void
    {
        $taxonIds = $observations->pluck('taxon_id')->filter()->unique()->values();
        $groups = DB::table('taxon_paths as paths')
            ->join('taxa as groups', 'groups.id', '=', 'paths.ancestor_taxon_id')
            ->whereIn('paths.descendant_taxon_id', $taxonIds)
            ->where('groups.rank_code', 'class')
            ->orderBy('paths.depth')
            ->get([
                'paths.descendant_taxon_id',
                'groups.id',
                'groups.scientific_name',
                'groups.preferred_french_name',
                'groups.vernacular_name',
            ])
            ->unique('descendant_taxon_id')
            ->keyBy('descendant_taxon_id');

        foreach ($observations as $observation) {
            $group = $groups->get($observation->taxon_id);
            if ($group !== null) {
                $observation->setAttribute('taxon_map_group', [
                    'id' => $group->id,
                    'key' => $group->scientific_name,
                    'label' => $group->preferred_french_name ?: $group->vernacular_name ?: $group->scientific_name,
                ]);

                continue;
            }

            $taxon = $observation->taxon;
            $scientificName = ($taxon?->rank_code === 'class' || $taxon?->rank === 'class')
                ? $taxon->scientific_name
                : ($taxon?->classification['class'] ?? $taxon?->classification['classe'] ?? null);
            $observation->setAttribute('taxon_map_group', [
                'id' => null,
                'key' => $scientificName ?: 'other',
                'label' => $scientificName ?: 'Autres taxons',
            ]);
        }
    }
}
