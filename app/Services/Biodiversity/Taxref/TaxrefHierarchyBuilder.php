<?php

namespace App\Services\Biodiversity\Taxref;

use App\Models\Taxon;
use App\Models\TaxonomicReferenceVersion;
use App\Models\TaxonPath;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class TaxrefHierarchyBuilder
{
    /** @return array<string, int|float> */
    public function build(TaxonomicReferenceVersion $version, bool $rebuild = false): array
    {
        $started = microtime(true);
        $concepts = Taxon::query()->where('taxref_version_id', $version->id)->count();
        if ($concepts === 0) {
            throw new RuntimeException('Aucun taxon canonique à hiérarchiser.');
        }
        $existing = TaxonPath::query()->where('taxonomic_reference_version_id', $version->id)->count();
        if ($rebuild || $existing === 0) {
            DB::transaction(function () use ($version): void {
                TaxonPath::query()->where('taxonomic_reference_version_id', $version->id)->delete();
                if (DB::getDriverName() === 'pgsql') {
                    DB::statement(<<<'SQL'
                        WITH RECURSIVE hierarchy AS (
                            SELECT id AS descendant_taxon_id, id AS ancestor_taxon_id, 0 AS depth
                            FROM taxa WHERE taxref_version_id = ?
                            UNION ALL
                            SELECT h.descendant_taxon_id, parent.parent_id AS ancestor_taxon_id, h.depth + 1
                            FROM hierarchy h
                            JOIN taxa current_taxon ON current_taxon.id = h.ancestor_taxon_id
                            JOIN taxa parent ON parent.id = current_taxon.id
                            WHERE parent.parent_id IS NOT NULL AND h.depth < 100
                        )
                        INSERT INTO taxon_paths (taxonomic_reference_version_id, ancestor_taxon_id, descendant_taxon_id, depth)
                        SELECT ?, ancestor_taxon_id, descendant_taxon_id, depth FROM hierarchy
                        ON CONFLICT DO NOTHING
                        SQL, [$version->id, $version->id]);
                } else {
                    $this->buildPortable($version);
                }
            });
        }

        $paths = TaxonPath::query()->where('taxonomic_reference_version_id', $version->id)->count();
        $self = TaxonPath::query()->where('taxonomic_reference_version_id', $version->id)->where('depth', 0)->count();
        $maxDepth = (int) TaxonPath::query()->where('taxonomic_reference_version_id', $version->id)->max('depth');
        $roots = Taxon::query()->where('taxref_version_id', $version->id)->whereNull('parent_id')
            ->whereHas('currentTaxrefRecord', fn ($query) => $query->whereNull('parent_cd_ref'))->count();
        $orphans = Taxon::query()->where('taxref_version_id', $version->id)->whereNull('parent_id')
            ->whereHas('currentTaxrefRecord', fn ($query) => $query->whereNotNull('parent_cd_ref'))->count();
        if ($self !== $concepts || $maxDepth >= 100) {
            throw new RuntimeException("Hiérarchie invalide : réflexifs={$self}/{$concepts}, profondeur={$maxDepth}.");
        }
        if ($version->version === '18' && ($paths !== 5479172 || $roots !== 2 || $orphans !== 8 || $maxDepth !== 35)) {
            throw new RuntimeException("Hiérarchie v18 inattendue : chemins={$paths}, racines={$roots}, orphelins={$orphans}, profondeur={$maxDepth}.");
        }

        $sizes = ['table_bytes' => 0, 'indexes_bytes' => 0, 'total_bytes' => 0];
        if (DB::getDriverName() === 'pgsql') {
            $size = DB::selectOne("select pg_relation_size('taxon_paths') table_bytes, pg_indexes_size('taxon_paths') indexes_bytes, pg_total_relation_size('taxon_paths') total_bytes");
            $sizes = array_map('intval', (array) $size);
        }

        return [
            'concepts' => $concepts,
            'paths' => $paths,
            'self_paths' => $self,
            'roots' => $roots,
            'technical_orphans' => $orphans,
            'max_depth' => $maxDepth,
            ...$sizes,
            'duration_seconds' => round(microtime(true) - $started, 3),
        ];
    }

    private function buildPortable(TaxonomicReferenceVersion $version): void
    {
        $parents = Taxon::query()->where('taxref_version_id', $version->id)->pluck('parent_id', 'id')->all();
        $batch = [];
        foreach ($parents as $descendant => $parent) {
            $ancestor = (int) $descendant;
            $depth = 0;
            $visited = [];
            while ($ancestor !== 0 && ! isset($visited[$ancestor]) && $depth < 100) {
                $visited[$ancestor] = true;
                $batch[] = ['taxonomic_reference_version_id' => $version->id, 'ancestor_taxon_id' => $ancestor, 'descendant_taxon_id' => $descendant, 'depth' => $depth];
                if (count($batch) >= 1000) {
                    DB::table('taxon_paths')->insertOrIgnore($batch);
                    $batch = [];
                }
                $ancestor = (int) ($parents[$ancestor] ?? 0);
                $depth++;
            }
            if ($ancestor !== 0 && isset($visited[$ancestor])) {
                throw new RuntimeException("Cycle détecté autour du taxon {$ancestor}.");
            }
        }
        if ($batch !== []) {
            DB::table('taxon_paths')->insertOrIgnore($batch);
        }
    }
}
