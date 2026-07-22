<?php

namespace App\Services\Biodiversity\Taxref;

use App\Models\Taxon;
use App\Models\TaxonomicReferenceVersion;
use App\Models\TaxrefRecord;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class TaxrefCanonicalizationPlanner
{
    private const RANK_FIELDS = ['REGNE', 'PHYLUM', 'CLASSE', 'ORDRE', 'FAMILLE', 'SOUS_FAMILLE', 'TRIBU'];

    public function __construct(
        private readonly ScientificNameGroupAnalyzer $nameGroupAnalyzer,
        private readonly ExistingTaxonMatcher $taxonMatcher,
        private readonly TaxrefHierarchyAnalyzer $hierarchyAnalyzer,
        private readonly TaxrefNameEstimateAnalyzer $nameEstimateAnalyzer,
    ) {}

    /** @return array<string, mixed> */
    public function plan(TaxonomicReferenceVersion $version, string $outputDirectory, int $sample = 20): array
    {
        $writer = new CanonicalizationReportWriter($outputDirectory);
        $before = $this->dataSnapshot();

        $concepts = $this->conceptSummary($version);
        $homonyms = $this->homonymReports($version, $writer, $sample);
        $matches = $this->existingTaxaMatches($version, $writer);
        $names = $this->nameEstimate($version, $writer);
        $hierarchy = $this->hierarchyEstimate($version);

        $after = $this->dataSnapshot();
        if ($before !== $after) {
            throw new RuntimeException('Garantie lecture seule violée : les compteurs taxonomiques ont changé pendant l’analyse.');
        }

        $writer->json('canonical-concepts-summary.json', [
            'reference' => ['provider' => $version->provider, 'version' => $version->version, 'status' => $version->status],
            'concepts' => $concepts,
            'scientific_name_homonyms' => $homonyms,
            'existing_taxa_matches' => $matches['summary'],
            'read_only_verification' => ['before' => $before, 'after' => $after, 'unchanged' => true],
            'sample_limit' => $sample,
            'generated_at' => now()->toIso8601String(),
        ]);
        $writer->json('taxon-names-estimate.json', $names);
        $writer->json('hierarchy-estimate.json', $hierarchy);

        return [
            'concepts' => $concepts,
            'homonyms' => $homonyms,
            'matches' => $matches['summary'],
            'names' => $names,
            'hierarchy' => $hierarchy,
            'output_directory' => $outputDirectory,
            'read_only_snapshot' => $before,
        ];
    }

    /** @return array<string, int> */
    private function dataSnapshot(): array
    {
        return [
            'taxa' => DB::table('taxa')->count(),
            'taxref_records' => DB::table('taxref_records')->count(),
            'taxon_names' => DB::table('taxon_names')->count(),
            'taxon_paths' => DB::table('taxon_paths')->count(),
            'taxon_source_mappings' => DB::table('taxon_source_mappings')->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function conceptSummary(TaxonomicReferenceVersion $version): array
    {
        $base = DB::table('taxref_records')->where('taxonomic_reference_version_id', $version->id);
        $rankCodes = (clone $base)->where('name_status', 'accepted')
            ->selectRaw("coalesce(rank_code, '(null)') as value, count(*) as total")
            ->groupBy('rank_code')->orderByDesc('total')->get()->mapWithKeys(fn ($row) => [$row->value => (int) $row->total])->all();
        $rawRanks = (clone $base)->where('name_status', 'accepted')
            ->selectRaw("coalesce(nullif(raw_data->>'RANG', ''), '(null)') as value, count(*) as total")
            ->groupByRaw("coalesce(nullif(raw_data->>'RANG', ''), '(null)')")->orderByDesc('total')->get()
            ->mapWithKeys(fn ($row) => [$row->value => (int) $row->total])->all();

        return [
            'records' => (clone $base)->count(),
            'accepted_concepts' => (clone $base)->where('name_status', 'accepted')->count(),
            'synonym_records' => (clone $base)->where('name_status', 'synonym')->count(),
            'other_records' => (clone $base)->where('name_status', 'other')->count(),
            'rank_codes' => $rankCodes,
            'raw_ranks' => $rawRanks,
            'null_rank_code' => (clone $base)->where('name_status', 'accepted')->whereNull('rank_code')->count(),
            'with_raw_parent' => (clone $base)->where('name_status', 'accepted')->whereNotNull('parent_cd_ref')->count(),
            'with_authorship' => (clone $base)->where('name_status', 'accepted')->whereNotNull('authorship')->where('authorship', '<>', '')->count(),
            'with_vernacular_cell' => (clone $base)->where('name_status', 'accepted')->whereRaw("coalesce(raw_data->>'NOM_VERN', '') <> ''")->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function homonymReports(TaxonomicReferenceVersion $version, CanonicalizationReportWriter $writer, int $sample): array
    {
        $csv = $writer->csv('scientific-name-homonyms.csv', [
            'normalized_name', 'concept_count', 'cd_ref', 'scientific_name', 'authorship', 'rank_code',
            'raw_rank', 'parent_cd_ref', 'authors_differ', 'ranks_differ', 'lineages_differ', 'lineage',
        ]);
        $normalExpression = $this->normalizedSql('scientific_name');
        $query = TaxrefRecord::query()->where('taxonomic_reference_version_id', $version->id)
            ->where('name_status', 'accepted')
            ->select('*')->selectRaw("{$normalExpression} as normalized_name")
            ->orderByRaw($normalExpression)->orderBy('cd_ref');

        $currentKey = null;
        $group = [];
        $summary = [
            'normalization' => 'trim + espaces internes + casse (accents conservés)',
            'groups' => 0,
            'concepts_in_groups' => 0,
            'extra_concepts_blocked_by_unique' => 0,
            'strictly_identical_groups' => 0,
            'authors_differ_groups' => 0,
            'ranks_differ_groups' => 0,
            'lineages_differ_groups' => 0,
            'accent_only_additional_groups' => $this->accentOnlyHomonymGroups($version),
            'samples' => [],
        ];
        foreach ($query->cursor() as $record) {
            $key = (string) $record->getAttribute('normalized_name');
            if ($currentKey !== null && $key !== $currentKey) {
                $this->flushHomonymGroup($currentKey, $group, $csv, $summary, $sample);
                $group = [];
            }
            $currentKey = $key;
            $group[] = $this->recordCandidate($record);
        }
        if ($currentKey !== null) {
            $this->flushHomonymGroup($currentKey, $group, $csv, $summary, $sample);
        }
        $csv->close();

        $summary['unique_scientific_name_constraint_compatible'] = $summary['groups'] === 0;
        $summary['decision'] = $summary['groups'] === 0
            ? 'La contrainte UNIQUE actuelle peut être conservée.'
            : 'La contrainte UNIQUE(taxa.scientific_name) doit être supprimée avant la canonicalisation complète.';

        return $summary;
    }

    /** @param list<array<string, mixed>> $group @param array<string, mixed> $summary */
    private function flushHomonymGroup(string $key, array $group, CanonicalizationCsvWriter $csv, array &$summary, int $sample): void
    {
        if (count($group) < 2) {
            return;
        }
        $analysis = $this->nameGroupAnalyzer->summarize($group);
        $summary['groups']++;
        $summary['concepts_in_groups'] += count($group);
        $summary['extra_concepts_blocked_by_unique'] += count($group) - 1;
        foreach (['strictly_identical', 'authors_differ', 'ranks_differ', 'lineages_differ'] as $flag) {
            if ($analysis[$flag]) {
                $summary[$flag.'_groups']++;
            }
        }
        if (count($summary['samples']) < $sample) {
            $summary['samples'][] = ['normalized_name' => $key, 'concepts' => $group];
        }
        foreach ($group as $candidate) {
            $csv->row([
                $key, count($group), $candidate['cd_ref'], $candidate['name'], $candidate['authorship'],
                $candidate['rank_code'], $candidate['raw_rank'], $candidate['parent_cd_ref'],
                (int) $analysis['authors_differ'], (int) $analysis['ranks_differ'],
                (int) $analysis['lineages_differ'], $candidate['lineage'],
            ]);
        }
    }

    private function accentOnlyHomonymGroups(TaxonomicReferenceVersion $version): int
    {
        if (DB::getDriverName() !== 'pgsql') {
            return 0;
        }
        $strict = $this->normalizedSql('scientific_name');
        $accent = "unaccent({$strict})";
        $row = DB::selectOne("select count(*) as total from (select {$accent}, count(*) as n, count(distinct {$strict}) as strict_n from taxref_records where taxonomic_reference_version_id = ? and name_status = 'accepted' group by {$accent} having count(*) > 1 and count(distinct {$strict}) > 1) x", [$version->id]);

        return (int) $row->total;
    }

    /** @return array{summary: array<string, int>, rows: list<array<string, mixed>>} */
    private function existingTaxaMatches(TaxonomicReferenceVersion $version, CanonicalizationReportWriter $writer): array
    {
        $headers = ['local_taxon_id', 'local_scientific_name', 'local_rank', 'status', 'method', 'taxref_cd_ref', 'taxref_scientific_name', 'reason', 'source_mappings', 'candidate_cd_refs'];
        $allCsv = $writer->csv('existing-taxa-matches.csv', $headers);
        $ambiguousCsv = $writer->csv('existing-taxa-ambiguous.csv', $headers);
        $unresolvedCsv = $writer->csv('existing-taxa-unresolved.csv', $headers);
        $taxa = Taxon::query()->with('mappings')->orderBy('id')->get();
        $wantedGbifIds = $taxa->flatMap(static fn (Taxon $taxon) => $taxon->mappings)
            ->where('source', 'gbif')->pluck('source_taxon_id')->all();
        $officialGbif = $this->officialGbifMappings($wantedGbifIds);
        $summary = ['total' => $taxa->count(), 'exact' => 0, 'synonym' => 0, 'probable' => 0, 'ambiguous' => 0, 'unresolved' => 0];
        $rows = [];

        foreach ($taxa as $taxon) {
            $local = ['rank' => $taxon->rank_code ?? $taxon->rank, 'classification' => $taxon->classification ?? []];
            $sourceCdRefs = [];
            foreach ($taxon->mappings as $mapping) {
                if ($mapping->source === 'gbif' && isset($officialGbif[$mapping->source_taxon_id])) {
                    $sourceCdRefs[] = $officialGbif[$mapping->source_taxon_id];
                }
            }
            $sourceCandidates = $this->candidatesByCdRefs($version, $sourceCdRefs);
            $acceptedCandidates = $this->candidatesByName($version, $taxon->scientific_name, 'accepted');
            $synonymCandidates = $this->candidatesByName($version, $taxon->scientific_name, 'synonym');
            $match = $this->taxonMatcher->match($local, $sourceCandidates, $acceptedCandidates, $synonymCandidates);
            $summary[$match['status']]++;
            $candidate = $match['candidate'];
            $row = [
                $taxon->id, $taxon->scientific_name, $taxon->rank_code ?? $taxon->rank, $match['status'], $match['method'],
                $candidate['cd_ref'] ?? null, $candidate['name'] ?? null, $match['reason'],
                json_encode($taxon->mappings->map(fn ($mapping) => [$mapping->source => $mapping->source_taxon_id])->all(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                implode('|', array_map(static fn (array $item): string => (string) $item['cd_ref'], $match['candidates'])),
            ];
            $allCsv->row($row);
            if ($match['status'] === 'ambiguous') {
                $ambiguousCsv->row($row);
            } elseif ($match['status'] === 'unresolved') {
                $unresolvedCsv->row($row);
            }
            $rows[] = ['local_taxon_id' => $taxon->id, 'scientific_name' => $taxon->scientific_name, ...$match];
        }
        $allCsv->close();
        $ambiguousCsv->close();
        $unresolvedCsv->close();

        return ['summary' => $summary, 'rows' => $rows];
    }

    /** @param list<string> $wantedIds @return array<string, int> */
    private function officialGbifMappings(array $wantedIds): array
    {
        $wanted = array_fill_keys(array_map('strval', $wantedIds), true);
        if ($wanted === []) {
            return [];
        }
        $path = storage_path('app/taxref/source/TAXREF_v18_2025/TAXREF_LIENS.txt');
        if (! is_readable($path)) {
            return [];
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }
        $result = [];
        $headers = fgetcsv($handle, null, "\t", '"', '');
        while (($row = fgetcsv($handle, null, "\t", '"', '')) !== false) {
            if (($row[0] ?? null) === 'GBIF' && isset($wanted[(string) ($row[6] ?? '')])) {
                $result[(string) $row[6]] = (int) $row[5];
            }
        }
        fclose($handle);

        return $result;
    }

    /** @param list<int> $cdRefs @return list<array<string, mixed>> */
    private function candidatesByCdRefs(TaxonomicReferenceVersion $version, array $cdRefs): array
    {
        if ($cdRefs === []) {
            return [];
        }

        $resolvedCdRefs = TaxrefRecord::query()->where('taxonomic_reference_version_id', $version->id)
            ->where(function ($query) use ($cdRefs): void {
                $query->whereIn('cd_nom', $cdRefs)->orWhereIn('cd_ref', $cdRefs);
            })->pluck('cd_ref')->unique()->values()->all();

        return TaxrefRecord::query()->where('taxonomic_reference_version_id', $version->id)
            ->where('name_status', 'accepted')->whereIn('cd_ref', $resolvedCdRefs)->get()->map(fn ($record) => $this->recordCandidate($record))->all();
    }

    /** @return list<array<string, mixed>> */
    private function candidatesByName(TaxonomicReferenceVersion $version, string $name, string $status): array
    {
        $expression = $this->normalizedSql('scientific_name');
        $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? $name));
        $records = TaxrefRecord::query()->where('taxonomic_reference_version_id', $version->id)
            ->where('name_status', $status)->whereRaw("{$expression} = ?", [$normalized])->get();
        if ($status === 'synonym') {
            $cdRefs = $records->pluck('cd_ref')->unique()->values()->all();

            return $this->candidatesByCdRefs($version, $cdRefs);
        }

        return $records->map(fn ($record) => $this->recordCandidate($record))->all();
    }

    /** @return array<string, mixed> */
    private function nameEstimate(TaxonomicReferenceVersion $version, CanonicalizationReportWriter $writer): array
    {
        $temporary = $writer->path('.taxon-names-estimate.sqlite.tmp');
        try {
            $records = TaxrefRecord::query()->where('taxonomic_reference_version_id', $version->id)
                ->select(['cd_ref', 'name_status', 'scientific_name', 'raw_data'])
                ->orderBy('cd_ref')->orderByRaw("case when name_status='accepted' then 0 else 1 end")->cursor();

            return $this->nameEstimateAnalyzer->analyzeStream($records, $temporary);
        } finally {
            @unlink($temporary);
        }
    }

    /** @return array<string, mixed> */
    private function hierarchyEstimate(TaxonomicReferenceVersion $version): array
    {
        $rows = DB::table('taxref_records as child')
            ->leftJoin('taxref_records as raw_parent', function ($join) use ($version): void {
                $join->on('raw_parent.cd_nom', '=', 'child.parent_cd_ref')
                    ->where('raw_parent.taxonomic_reference_version_id', '=', $version->id);
            })
            ->where('child.taxonomic_reference_version_id', $version->id)
            ->where('child.name_status', 'accepted')
            ->selectRaw('child.cd_ref, raw_parent.cd_ref as canonical_parent_cd_ref, child.parent_cd_ref as raw_parent_cd_ref')
            ->orderBy('child.cd_ref')->cursor();
        $nodes = (function () use ($rows): \Generator {
            foreach ($rows as $row) {
                yield [
                    'cd_ref' => (int) $row->cd_ref,
                    'parent_cd_ref' => $row->canonical_parent_cd_ref === null ? null : (int) $row->canonical_parent_cd_ref,
                    'raw_parent_cd_ref' => $row->raw_parent_cd_ref === null ? null : (int) $row->raw_parent_cd_ref,
                ];
            }
        })();

        return [
            ...$this->hierarchyAnalyzer->analyze($nodes),
            'path_semantics' => 'une ligne pour soi-même (profondeur 0) et chaque ancêtre canonique',
            'includes_intermediate_ranks' => true,
            'estimated_postgresql_method' => '150 à 210 octets par ligne, estimation médiane 176 octets incluant table et index',
        ];
    }

    /** @return array<string, mixed> */
    private function recordCandidate(TaxrefRecord $record): array
    {
        $raw = $record->raw_data ?? [];
        $classification = [];
        foreach (self::RANK_FIELDS as $field) {
            if (trim((string) ($raw[$field] ?? '')) !== '') {
                $classification[mb_strtolower($field)] = trim((string) $raw[$field]);
            }
        }

        return [
            'cd_ref' => (int) $record->cd_ref,
            'name' => $record->scientific_name,
            'authorship' => $record->authorship,
            'raw_rank' => $raw['RANG'] ?? null,
            'rank_code' => $record->rank_code,
            'parent_cd_ref' => $record->parent_cd_ref === null ? null : (int) $record->parent_cd_ref,
            'classification' => $classification,
            'lineage' => implode(' > ', $classification),
        ];
    }

    private function normalizedSql(string $column): string
    {
        return DB::getDriverName() === 'pgsql'
            ? "lower(regexp_replace(btrim({$column}), '[[:space:]]+', ' ', 'g'))"
            : "lower(trim({$column}))";
    }
}
