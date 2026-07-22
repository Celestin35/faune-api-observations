<?php

namespace App\Services\Biodiversity\Taxref;

final class ScientificNameGroupAnalyzer
{
    /**
     * @param  list<array{cd_ref: int, name: string, authorship: ?string, raw_rank: ?string, rank_code: ?string, parent_cd_ref: ?int, lineage: string}>  $candidates
     * @return array{is_homonym: bool, concepts: int, strictly_identical: bool, authors_differ: bool, ranks_differ: bool, lineages_differ: bool}
     */
    public function summarize(array $candidates): array
    {
        return [
            'is_homonym' => count($candidates) > 1,
            'concepts' => count($candidates),
            'strictly_identical' => count($this->values($candidates, 'name')) === 1,
            'authors_differ' => count($this->values($candidates, 'authorship')) > 1,
            'ranks_differ' => count($this->values($candidates, 'raw_rank')) > 1,
            'lineages_differ' => count($this->values($candidates, 'lineage')) > 1,
        ];
    }

    /** @param list<array<string, mixed>> $candidates */
    private function values(array $candidates, string $key): array
    {
        return array_values(array_unique(array_map(
            static fn (array $candidate): string => trim((string) ($candidate[$key] ?? '')),
            $candidates,
        )));
    }
}
