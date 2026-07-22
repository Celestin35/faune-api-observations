<?php

namespace App\Services\Biodiversity\Taxref;

final class ExistingTaxonMatcher
{
    /**
     * @param  array<string, mixed>  $local
     * @param  list<array<string, mixed>>  $sourceCandidates
     * @param  list<array<string, mixed>>  $acceptedCandidates
     * @param  list<array<string, mixed>>  $synonymCandidates
     * @return array<string, mixed>
     */
    public function match(array $local, array $sourceCandidates, array $acceptedCandidates, array $synonymCandidates): array
    {
        $sourceCandidates = $this->uniqueCandidates($sourceCandidates);
        if (count($sourceCandidates) === 1) {
            return $this->result('exact', 'validated_source_mapping', $sourceCandidates[0], $sourceCandidates,
                'Un mapping source officiel converge vers un unique concept TAXREF.');
        }
        if (count($sourceCandidates) > 1) {
            return $this->ambiguous('validated_source_mapping', $sourceCandidates,
                'Plusieurs concepts TAXREF correspondent aux mappings source disponibles.');
        }

        $acceptedCandidates = $this->uniqueCandidates($acceptedCandidates);
        if (count($acceptedCandidates) === 1) {
            $candidate = $acceptedCandidates[0];
            $rankMatches = $this->rankMatches($local, $candidate);

            return $this->result(
                $rankMatches ? 'exact' : 'probable',
                $rankMatches ? 'exact_accepted_name_and_rank' : 'exact_accepted_name',
                $candidate,
                $acceptedCandidates,
                $rankMatches
                    ? 'Nom scientifique accepté et rang identiques.'
                    : 'Nom scientifique accepté identique, mais rang local absent ou incohérent.',
            );
        }
        if (count($acceptedCandidates) > 1) {
            $narrowed = $this->narrow($local, $acceptedCandidates);
            if (count($narrowed) === 1) {
                return $this->result('probable', 'accepted_name_rank_lineage', $narrowed[0], $acceptedCandidates,
                    'Le rang et la lignée réduisent plusieurs homonymes à un candidat.');
            }

            return $this->ambiguous('accepted_name_homonyms', $acceptedCandidates,
                'Plusieurs concepts acceptés portent le même nom et les indices locaux ne suffisent pas.');
        }

        $synonymCandidates = $this->uniqueCandidates($synonymCandidates);
        if (count($synonymCandidates) === 1) {
            return $this->result('synonym', 'scientific_synonym', $synonymCandidates[0], $synonymCandidates,
                'Le nom local est un synonyme d’un unique concept accepté TAXREF.');
        }
        if (count($synonymCandidates) > 1) {
            $narrowed = $this->narrow($local, $synonymCandidates);
            if (count($narrowed) === 1) {
                return $this->result('probable', 'synonym_rank_lineage', $narrowed[0], $synonymCandidates,
                    'Le rang et la lignée réduisent plusieurs synonymes à un candidat.');
            }

            return $this->ambiguous('synonym_ambiguity', $synonymCandidates,
                'Le même synonyme pointe vers plusieurs concepts acceptés.');
        }

        return $this->result('unresolved', 'none', null, [],
            'Aucun nom accepté, synonyme ou mapping officiel ne correspond de façon exploitable.');
    }

    /** @param list<array<string, mixed>> $candidates */
    private function narrow(array $local, array $candidates): array
    {
        $ranked = array_values(array_filter($candidates, fn (array $candidate): bool => $this->rankMatches($local, $candidate)));
        if ($ranked !== []) {
            $candidates = $ranked;
        }

        $classification = is_array($local['classification'] ?? null) ? $local['classification'] : [];
        if ($classification === [] || count($candidates) < 2) {
            return $candidates;
        }

        $scores = [];
        foreach ($candidates as $index => $candidate) {
            $lineage = is_array($candidate['classification'] ?? null) ? $candidate['classification'] : [];
            $matches = 0;
            $conflicts = 0;
            foreach ($classification as $rank => $name) {
                if (! isset($lineage[$rank]) || trim((string) $name) === '') {
                    continue;
                }
                if (mb_strtolower(trim((string) $lineage[$rank])) === mb_strtolower(trim((string) $name))) {
                    $matches++;
                } else {
                    $conflicts++;
                }
            }
            $scores[$index] = $matches - ($conflicts * 10);
        }
        $best = max($scores);

        return array_values(array_filter($candidates, static fn (array $candidate, int $index): bool => $scores[$index] === $best, ARRAY_FILTER_USE_BOTH));
    }

    private function rankMatches(array $local, array $candidate): bool
    {
        $localRank = trim((string) ($local['rank'] ?? ''));
        $candidateRank = trim((string) ($candidate['rank_code'] ?? ''));

        return $localRank !== '' && $candidateRank !== '' && $localRank === $candidateRank;
    }

    /** @param list<array<string, mixed>> $candidates */
    private function uniqueCandidates(array $candidates): array
    {
        $unique = [];
        foreach ($candidates as $candidate) {
            $unique[(string) $candidate['cd_ref']] = $candidate;
        }

        return array_values($unique);
    }

    /** @param list<array<string, mixed>> $candidates */
    private function ambiguous(string $method, array $candidates, string $reason): array
    {
        return $this->result('ambiguous', $method, null, $candidates, $reason);
    }

    /** @param list<array<string, mixed>> $candidates */
    private function result(string $status, string $method, ?array $candidate, array $candidates, string $reason): array
    {
        return [
            'status' => $status,
            'method' => $method,
            'candidate' => $candidate,
            'candidates' => $candidates,
            'reason' => $reason,
        ];
    }
}
