<?php

namespace App\Services\Biodiversity\Taxref;

final class TaxrefHierarchyAnalyzer
{
    /**
     * @param  iterable<array{cd_ref: int, parent_cd_ref: ?int, raw_parent_cd_ref?: ?int}>  $nodes
     * @return array<string, int|float|list<list<int>>>
     */
    public function analyze(iterable $nodes): array
    {
        $parents = [];
        $rawParents = [];
        foreach ($nodes as $node) {
            $cdRef = (int) $node['cd_ref'];
            $parents[$cdRef] = $node['parent_cd_ref'] === null ? null : (int) $node['parent_cd_ref'];
            $rawParents[$cdRef] = isset($node['raw_parent_cd_ref']) ? (int) $node['raw_parent_cd_ref'] : null;
        }

        $depths = [];
        $cycles = [];
        $missingParents = 0;
        $resolvedSynonymParents = 0;
        foreach ($parents as $node => $parent) {
            if ($parent === null && ($rawParents[$node] ?? null) !== null) {
                $missingParents++;
            } elseif ($parent !== null && ! array_key_exists($parent, $parents)) {
                $missingParents++;
            }
            if ($parent !== null && ($rawParents[$node] ?? null) !== null && $parent !== $rawParents[$node]) {
                $resolvedSynonymParents++;
            }
            if (! array_key_exists($node, $depths)) {
                $this->resolveDepth($node, $parents, $depths, $cycles);
            }
        }

        $validDepths = array_values(array_filter($depths, static fn (?int $depth): bool => $depth !== null));
        $pathRows = array_sum(array_map(static fn (int $depth): int => $depth + 1, $validDepths));
        $roots = count(array_filter(
            array_keys($parents),
            static fn (int $node): bool => $parents[$node] === null && ($rawParents[$node] ?? null) === null,
        ));
        $cycleNodes = array_sum(array_map('count', $cycles));

        return [
            'concepts' => count($parents),
            'roots' => $roots,
            'concepts_without_parent' => $roots,
            'missing_parents' => $missingParents,
            'parents_resolved_via_synonym' => $resolvedSynonymParents,
            'cycle_groups' => count($cycles),
            'cycle_nodes' => $cycleNodes,
            'cycles' => $cycles,
            'max_depth' => $validDepths === [] ? 0 : max($validDepths),
            'average_depth' => $validDepths === [] ? 0.0 : round(array_sum($validDepths) / count($validDepths), 4),
            'taxon_paths_rows' => $pathRows,
            'estimated_postgresql_bytes' => $pathRows * 176,
            'estimated_postgresql_min_bytes' => $pathRows * 150,
            'estimated_postgresql_max_bytes' => $pathRows * 210,
        ];
    }

    /**
     * @param  array<int, int|null>  $parents
     * @param  array<int, int|null>  $depths
     * @param  list<list<int>>  $cycles
     */
    private function resolveDepth(int $start, array $parents, array &$depths, array &$cycles): void
    {
        $path = [];
        $positions = [];
        $current = $start;
        $baseDepth = -1;

        while (true) {
            if (array_key_exists($current, $depths)) {
                $knownDepth = $depths[$current];
                if ($knownDepth === null) {
                    foreach ($path as $node) {
                        $depths[$node] = null;
                    }

                    return;
                }
                $baseDepth = $knownDepth;
                break;
            }
            if (isset($positions[$current])) {
                $cycle = array_slice($path, $positions[$current]);
                $cycles[] = $cycle;
                foreach ($cycle as $node) {
                    $depths[$node] = null;
                }
                foreach ($path as $node) {
                    $depths[$node] = null;
                }

                return;
            }

            $positions[$current] = count($path);
            $path[] = $current;
            $parent = $parents[$current] ?? null;
            if ($parent === null || ! array_key_exists($parent, $parents)) {
                $baseDepth = -1;
                break;
            }
            $current = $parent;
        }

        while ($path !== []) {
            $node = array_pop($path);
            $baseDepth++;
            $depths[$node] = $baseDepth;
        }
    }
}
