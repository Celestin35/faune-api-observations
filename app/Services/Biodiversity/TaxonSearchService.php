<?php

namespace App\Services\Biodiversity;

use App\Models\Taxon;
use App\Models\TaxonomicReferenceVersion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class TaxonSearchService
{
    public function __construct(private readonly TaxonNameNormalizer $normalizer) {}

    /** @return list<array<string, mixed>> */
    public function search(string $query, int $limit = 10): array
    {
        $normalized = $this->normalizer->normalize($query);
        if ($normalized === '') {
            return [];
        }
        $lookup = match ($normalized) {
            'reptile', 'reptiles' => 'sauropsida',
            default => $normalized,
        };
        $version = TaxonomicReferenceVersion::query()->where('provider', 'taxref')
            ->where('status', TaxonomicReferenceVersion::STATUS_ACTIVE)->first();
        $matches = collect();
        if ($version !== null) {
            $matches = $this->canonicalMatches($version->id, $lookup, $limit);
        }
        $usedIds = $matches->pluck('taxon_id')->map(fn ($id): int => (int) $id)->all();
        $results = $this->formatCanonical($matches, $version?->version ?? '');
        if (count($results) < $limit) {
            $locals = Taxon::query()->with('mappings')->whereNull('taxref_version_id')
                ->whereNotIn('id', $usedIds)->whereRaw('lower(scientific_name) like ?', ['%'.mb_strtolower(trim($query)).'%'])
                ->limit($limit - count($results))->get();
            foreach ($locals as $taxon) {
                $results[] = $this->formatLocal($taxon, $query);
            }
        }

        return array_slice($results, 0, $limit);
    }

    /** @return array<string, mixed> */
    public function one(Taxon $taxon): array
    {
        if ($taxon->taxref_version_id === null) {
            return $this->formatLocal($taxon->load('mappings'), $taxon->scientific_name);
        }
        $name = DB::table('taxon_names')->where('taxon_id', $taxon->id)
            ->where('name_type', 'accepted_scientific')->first();
        $row = (object) [
            'taxon_id' => $taxon->id,
            'accepted_scientific_name' => $taxon->accepted_scientific_name,
            'preferred_french_name' => $taxon->preferred_french_name,
            'rank_code' => $taxon->rank_code ?? $taxon->rank,
            'rank_label' => $taxon->rankDefinition?->label_fr,
            'cd_ref' => $taxon->taxref_cd_ref,
            'matched_name' => $name?->name ?? $taxon->scientific_name,
            'name_type' => $name?->name_type ?? 'accepted_scientific',
        ];

        return $this->formatCanonical(collect([$row]), $taxon->referenceVersion?->version ?? '')[0];
    }

    private function canonicalMatches(int $versionId, string $query, int $limit): Collection
    {
        $similarity = DB::getDriverName() === 'pgsql' ? 'similarity(tn.normalized_name, ?)' : '0';
        $bindings = DB::getDriverName() === 'pgsql' ? [$query] : [];
        $rows = DB::table('taxon_names as tn')
            ->join('taxa as t', 't.id', '=', 'tn.taxon_id')
            ->leftJoin('taxon_ranks as tr', 'tr.code', '=', 't.rank_code')
            ->where('tn.taxonomic_reference_version_id', $versionId)
            ->where(function ($builder) use ($query): void {
                $builder->where('tn.normalized_name', $query)
                    ->orWhere('tn.normalized_name', 'like', $query.'%')
                    ->orWhere('tn.normalized_name', 'like', '%'.$query.'%');
                if (DB::getDriverName() === 'pgsql') {
                    $builder->orWhereRaw('similarity(tn.normalized_name, ?) >= 0.25', [$query]);
                }
            })
            ->select([
                't.id as taxon_id', 't.accepted_scientific_name', 't.preferred_french_name',
                DB::raw('coalesce(t.rank_code, t.rank) as rank_code'), 'tr.label_fr as rank_label', 't.taxref_cd_ref as cd_ref',
                'tn.name as matched_name', 'tn.name_type', 'tn.is_preferred',
            ])
            ->selectRaw("case
                when tn.normalized_name = ? and tn.name_type = 'accepted_scientific' then 0
                when tn.normalized_name = ? and tn.name_type = 'vernacular' and tn.is_preferred then 1
                when tn.normalized_name = ? and tn.name_type = 'scientific_synonym' then 2
                when tn.normalized_name = ? then 3
                when tn.normalized_name like ? then 4 else 5 end as priority", [$query, $query, $query, $query, $query.'%'])
            ->selectRaw("{$similarity} as similarity_score", $bindings)
            ->orderBy('priority')->orderByDesc('similarity_score')->orderByRaw('length(tn.normalized_name)')
            ->limit(max(50, $limit * 20))->get();

        return $rows->unique('taxon_id')->take($limit)->values();
    }

    /** @return list<array<string, mixed>> */
    private function formatCanonical(Collection $matches, string $version): array
    {
        if ($matches->isEmpty()) {
            return [];
        }
        $ids = $matches->pluck('taxon_id')->map(fn ($id): int => (int) $id)->all();
        $lineages = DB::table('taxon_paths as p')->join('taxa as a', 'a.id', '=', 'p.ancestor_taxon_id')
            ->whereIn('p.descendant_taxon_id', $ids)->where('p.depth', '>', 0)->whereNotNull('a.rank_code')
            ->orderBy('p.descendant_taxon_id')->orderByDesc('p.depth')
            ->get(['p.descendant_taxon_id', 'a.accepted_scientific_name'])->groupBy('descendant_taxon_id');
        $mappingRows = DB::table('taxon_source_mappings')->whereIn('taxon_id', $ids)
            ->where('mapping_status', 'validated')->where('is_preferred', true)->whereNull('valid_to')->get(['taxon_id', 'source']);
        $mappingSources = $mappingRows->groupBy('taxon_id')->map(fn (Collection $rows) => $rows->pluck('source')->all());

        return $matches->map(function ($match) use ($lineages, $mappingSources, $version): array {
            $sources = $mappingSources[(int) $match->taxon_id] ?? [];

            return [
                'id' => (int) $match->taxon_id,
                'acceptedScientificName' => $match->accepted_scientific_name,
                'preferredFrenchName' => $match->preferred_french_name,
                'matchedName' => $match->matched_name,
                'matchedNameType' => $match->name_type,
                'rank' => ['code' => $match->rank_code, 'label' => $match->rank_label ?? $this->fallbackRankLabel($match->rank_code)],
                'lineage' => ($lineages[(int) $match->taxon_id] ?? collect())->pluck('accepted_scientific_name')->values()->all(),
                'reference' => ['provider' => 'TAXREF', 'version' => $version, 'cdRef' => (int) $match->cd_ref],
                'defaultScope' => $match->rank_code === 'species' ? 'exact' : 'subtree',
                'sourceAvailability' => [
                    'gbif' => true,
                    'inaturalist' => true,
                    'fauneFrance' => $match->rank_code === 'species' && in_array('faune_france', $sources, true),
                ],
            ];
        })->all();
    }

    /** @return array<string, mixed> */
    private function formatLocal(Taxon $taxon, string $matchedName): array
    {
        $sources = $taxon->mappings->where('mapping_status', 'validated')->where('is_preferred', true)->pluck('source')->all();

        return [
            'id' => $taxon->id,
            'acceptedScientificName' => $taxon->scientific_name,
            'preferredFrenchName' => $taxon->vernacular_name,
            'matchedName' => $matchedName,
            'matchedNameType' => 'local_name',
            'rank' => ['code' => $taxon->rank, 'label' => $taxon->rank],
            'lineage' => array_values($taxon->classification ?? []),
            'reference' => null,
            'defaultScope' => $taxon->defaultScope(),
            'sourceAvailability' => [
                'gbif' => in_array('gbif', $sources, true),
                'inaturalist' => in_array('inaturalist', $sources, true),
                'fauneFrance' => $taxon->rank === 'species' && in_array('faune_france', $sources, true),
            ],
            'localStatus' => $taxon->taxonomic_status,
        ];
    }

    private function fallbackRankLabel(?string $rank): ?string
    {
        return match ($rank) {
            'CLAD' => 'Clade',
            default => $rank,
        };
    }
}
