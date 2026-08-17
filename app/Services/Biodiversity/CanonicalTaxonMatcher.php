<?php

namespace App\Services\Biodiversity;

use App\Models\Taxon;
use App\Models\TaxonName;
use App\Models\TaxonomicReferenceVersion;
use Illuminate\Database\Eloquent\Collection;

final class CanonicalTaxonMatcher
{
    public function __construct(private readonly TaxonNameNormalizer $nameNormalizer) {}

    /** @param array<string, string|null> $classification */
    public function match(?string $scientificName, array $classification, ?string $rank = null): ?Taxon
    {
        $rank ??= array_key_last($classification);
        $names = $this->candidateNames($scientificName, $classification, $rank);

        foreach ($names as $name) {
            $candidates = $this->candidates($name, $rank);
            if ($candidates->count() === 1) {
                return $candidates->first();
            }

            if ($candidates->count() > 1) {
                $narrowed = $this->narrowByClassification($candidates, $classification);
                if ($narrowed->count() === 1) {
                    return $narrowed->first();
                }

                // Do not bypass an ambiguous clean scientific name with a less
                // reliable fallback such as a source label containing authorship.
                return null;
            }
        }

        return null;
    }

    /** @param array<string, string|null> $classification @return list<string> */
    private function candidateNames(?string $scientificName, array $classification, ?string $rank): array
    {
        $names = [];
        if ($rank !== null && is_string($classification[$rank] ?? null)) {
            $names[] = trim($classification[$rank]);
        }

        $last = end($classification);
        if (is_string($last)) {
            $names[] = trim($last);
        }
        if ($scientificName !== null) {
            $names[] = trim($scientificName);
        }

        return array_values(array_unique(array_filter($names)));
    }

    /** @return Collection<int, Taxon> */
    private function candidates(string $name, ?string $rank): Collection
    {
        $query = Taxon::query()
            ->where('taxonomic_status', 'canonical')
            ->whereHas('referenceVersion', fn ($query) => $query
                ->where('provider', 'taxref')
                ->where('status', TaxonomicReferenceVersion::STATUS_ACTIVE))
            ->whereIn('id', TaxonName::query()
                ->where('normalized_name', $this->nameNormalizer->normalize($name))
                ->whereIn('name_type', [
                    TaxonName::TYPE_ACCEPTED_SCIENTIFIC,
                    TaxonName::TYPE_SCIENTIFIC_SYNONYM,
                ])
                ->select('taxon_id'));

        if ($rank !== null && $rank !== '') {
            $query->where(fn ($query) => $query
                ->where('rank_code', $rank)
                ->orWhere(fn ($query) => $query->whereNull('rank_code')->where('rank', $rank)));
        }

        return $query->limit(20)->get();
    }

    /**
     * @param  Collection<int, Taxon>  $candidates
     * @param  array<string, string|null>  $classification
     * @return Collection<int, Taxon>
     */
    private function narrowByClassification(Collection $candidates, array $classification): Collection
    {
        if ($classification === []) {
            return $candidates;
        }

        $scores = $candidates->mapWithKeys(function (Taxon $candidate) use ($classification): array {
            $candidateClassification = $candidate->classification ?? [];
            $score = 0;
            foreach ($classification as $rank => $name) {
                if (! is_string($name) || $name === '' || ! is_string($candidateClassification[$rank] ?? null)) {
                    continue;
                }
                $matches = $this->nameNormalizer->normalize($name)
                    === $this->nameNormalizer->normalize($candidateClassification[$rank]);
                $score += $matches ? 1 : -10;
            }

            return [$candidate->id => $score];
        });
        $best = $scores->max();

        return $candidates->filter(fn (Taxon $candidate): bool => $scores[$candidate->id] === $best)->values();
    }
}
