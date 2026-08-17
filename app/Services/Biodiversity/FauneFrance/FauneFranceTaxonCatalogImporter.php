<?php

namespace App\Services\Biodiversity\FauneFrance;

use App\Models\TaxonName;
use App\Models\TaxonomicReferenceVersion;
use App\Models\TaxonSourceMapping;
use App\Services\Biodiversity\TaxonNameNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class FauneFranceTaxonCatalogImporter
{
    public function __construct(private TaxonNameNormalizer $normalizer) {}

    /**
     * @param  array<string, mixed>  $catalog
     * @return array{summary: array<string, int|string|bool|null>, issues: array<string, array<int, mixed>>}
     */
    public function import(array $catalog, bool $persist = true, ?string $referenceVersion = null): array
    {
        $entries = $this->validateCatalog($catalog);
        $version = TaxonomicReferenceVersion::query()
            ->where('provider', 'taxref')
            ->when($referenceVersion, fn ($query) => $query->where('version', $referenceVersion), fn ($query) => $query->where('status', TaxonomicReferenceVersion::STATUS_ACTIVE))
            ->first();
        if ($version === null) {
            throw new InvalidArgumentException($referenceVersion
                ? "La version TAXREF {$referenceVersion} est introuvable."
                : 'Aucune version TAXREF active n’est disponible.');
        }

        $selectable = collect($entries)->where('selectable', true)->values();
        $normalizedNames = $selectable->flatMap(fn (array $entry): array => [
            $this->normalizer->normalize($entry['scientificName']),
            $this->normalizer->normalize((string) ($entry['vernacularName'] ?? '')),
        ])
            ->filter()->unique()->values();
        $candidates = $this->candidates($normalizedNames, $version->id);
        $existing = TaxonSourceMapping::query()->where('source', 'faune_france')->get()->keyBy('source_taxon_id');

        $matches = [];
        $issues = ['unmatched' => [], 'ambiguous' => [], 'conflicts' => []];
        $acceptedMatches = 0;
        $synonymMatches = 0;
        $vernacularMatches = 0;

        foreach ($selectable as $entry) {
            $normalized = $this->normalizer->normalize($entry['scientificName']);
            $nameCandidates = $candidates->get($normalized, collect());
            $accepted = $nameCandidates->where('name_type', TaxonName::TYPE_ACCEPTED_SCIENTIFIC)->unique('taxon_id')->values();
            $synonyms = $nameCandidates->where('name_type', TaxonName::TYPE_SCIENTIFIC_SYNONYM)->unique('taxon_id')->values();
            $vernacularNormalized = $this->normalizer->normalize((string) ($entry['vernacularName'] ?? ''));
            $vernacular = $vernacularNormalized === ''
                ? collect()
                : $candidates->get($vernacularNormalized, collect())
                    ->where('name_type', TaxonName::TYPE_VERNACULAR)
                    ->filter(fn (object $candidate): bool => ($candidate->rank_code ?: $candidate->rank) === 'species')
                    ->unique('taxon_id')->values();

            if ($accepted->count() === 1) {
                $candidate = $accepted->first();
                $matchType = 'exact_accepted_name';
                $confidence = 1.0;
                $acceptedMatches++;
            } elseif ($accepted->count() > 1) {
                $issues['ambiguous'][] = $this->ambiguousIssue($entry, $accepted);

                continue;
            } elseif ($synonyms->count() === 1) {
                $candidate = $synonyms->first();
                $matchType = 'exact_scientific_synonym';
                $confidence = 0.95;
                $synonymMatches++;
            } elseif ($synonyms->count() > 1) {
                $issues['ambiguous'][] = $this->ambiguousIssue($entry, $synonyms);

                continue;
            } elseif ($vernacular->count() === 1) {
                $candidate = $vernacular->first();
                $matchType = 'exact_vernacular_name';
                $confidence = 0.90;
                $vernacularMatches++;
            } elseif ($vernacular->count() > 1) {
                $issues['ambiguous'][] = $this->ambiguousIssue($entry, $vernacular);

                continue;
            } else {
                $issues['unmatched'][] = $this->entryIdentity($entry);

                continue;
            }

            $current = $existing->get($entry['fauneFranceId']);
            if ($current !== null && (int) $current->taxon_id !== (int) $candidate->taxon_id) {
                $issues['conflicts'][] = $this->entryIdentity($entry) + [
                    'existingTaxonId' => (int) $current->taxon_id,
                    'matchedTaxonId' => (int) $candidate->taxon_id,
                ];

                continue;
            }

            $matches[] = [
                'entry' => $entry,
                'taxon_id' => (int) $candidate->taxon_id,
                'taxref_cd_ref' => $candidate->taxref_cd_ref !== null ? (int) $candidate->taxref_cd_ref : null,
                'target_scientific_name' => (string) $candidate->scientific_name,
                'source_rank' => (string) ($candidate->rank_code ?: $candidate->rank ?: ''),
                'match_type' => $matchType,
                'confidence' => $confidence,
            ];
        }

        $preferredIds = collect($matches)->groupBy('taxon_id')->map(function (Collection $taxonMatches): string {
            return (string) $taxonMatches->sort(function (array $left, array $right): int {
                $priority = ['exact_accepted_name' => 0, 'exact_scientific_synonym' => 1, 'exact_vernacular_name' => 2];
                $leftKey = [$priority[$left['match_type']], (int) $left['entry']['fauneFranceId']];
                $rightKey = [$priority[$right['match_type']], (int) $right['entry']['fauneFranceId']];

                return $leftKey <=> $rightKey;
            })->first()['entry']['fauneFranceId'];
        });

        $sourceVersion = isset($catalog['sourceLastUpdateTimestamp']) && is_int($catalog['sourceLastUpdateTimestamp'])
            ? 'species-index:'.$catalog['sourceLastUpdateTimestamp']
            : null;
        $now = now();
        $rows = [];
        $created = 0;
        $updated = 0;
        $unchanged = 0;

        foreach ($matches as $match) {
            $entry = $match['entry'];
            $isPreferred = $preferredIds->get($match['taxon_id']) === $entry['fauneFranceId'];
            $rawData = [
                'vernacular_name' => $entry['vernacularName'],
                'taxonomic_group_id' => $entry['taxonomicGroupId'],
                'selectable' => true,
                'order' => $entry['order'],
                'category' => $entry['category'],
                'catalog_exported_at' => $catalog['exportedAt'] ?? null,
            ];
            $row = [
                'taxon_id' => $match['taxon_id'],
                'source' => 'faune_france',
                'source_taxon_id' => $entry['fauneFranceId'],
                'source_accepted_taxon_id' => $entry['fauneFranceId'],
                'source_scientific_name' => $entry['scientificName'],
                'source_rank' => $match['source_rank'] ?: null,
                'source_reference_version' => $sourceVersion,
                'mapping_status' => 'validated',
                'match_type' => $match['match_type'],
                'confidence' => $match['confidence'],
                'is_preferred' => $isPreferred,
                'valid_from' => $existing->get($entry['fauneFranceId'])?->valid_from ?? $now,
                'valid_to' => null,
                'reviewed_at' => null,
                'raw_data' => json_encode($rawData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => $existing->get($entry['fauneFranceId'])?->created_at ?? $now,
                'updated_at' => $now,
            ];
            $current = $existing->get($entry['fauneFranceId']);
            if ($current === null) {
                $created++;
            } elseif ($this->mappingChanged($current, $row, $rawData)) {
                $updated++;
            } else {
                $unchanged++;
            }
            $rows[] = $row;
        }

        if ($persist && $rows !== []) {
            DB::transaction(function () use ($rows): void {
                $taxonIds = collect($rows)->pluck('taxon_id')->unique()->all();
                TaxonSourceMapping::query()->where('source', 'faune_france')->whereIn('taxon_id', $taxonIds)
                    ->update(['is_preferred' => false, 'updated_at' => now()]);
                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table('taxon_source_mappings')->upsert($chunk, ['source', 'source_taxon_id'], [
                        'taxon_id', 'source_accepted_taxon_id', 'source_scientific_name', 'source_rank',
                        'source_reference_version', 'mapping_status', 'match_type', 'confidence', 'is_preferred',
                        'valid_from', 'valid_to', 'reviewed_at', 'raw_data', 'updated_at',
                    ]);
                }
            });
        }

        return [
            'summary' => [
                'persisted' => $persist,
                'taxrefVersion' => (string) $version->version,
                'catalogEntries' => count($entries),
                'selectableEntries' => $selectable->count(),
                'hiddenEntries' => count($entries) - $selectable->count(),
                'matched' => count($rows),
                'matchedAcceptedNames' => $acceptedMatches,
                'matchedSynonyms' => $synonymMatches,
                'matchedVernacularNames' => $vernacularMatches,
                'created' => $created,
                'updated' => $updated,
                'unchanged' => $unchanged,
                'unmatched' => count($issues['unmatched']),
                'ambiguous' => count($issues['ambiguous']),
                'conflicts' => count($issues['conflicts']),
            ],
            'issues' => $issues,
        ];
    }

    /** @param array<string, mixed> $catalog
     * @return array<int, array<string, mixed>>
     */
    private function validateCatalog(array $catalog): array
    {
        if (($catalog['schemaVersion'] ?? null) !== 1 || ($catalog['source'] ?? null) !== 'faune_france' || ! is_array($catalog['entries'] ?? null)) {
            throw new InvalidArgumentException('Le fichier n’est pas un catalogue Faune-France version 1 valide.');
        }
        $ids = [];
        foreach ($catalog['entries'] as $index => $entry) {
            if (! is_array($entry) || ! preg_match('/^[1-9]\d*$/', (string) ($entry['fauneFranceId'] ?? ''))
                || ! is_string($entry['scientificName'] ?? null) || trim($entry['scientificName']) === ''
                || ! is_int($entry['taxonomicGroupId'] ?? null) || ! is_bool($entry['selectable'] ?? null)) {
                throw new InvalidArgumentException("Entrée Faune-France invalide à l’index {$index}.");
            }
            $id = (string) $entry['fauneFranceId'];
            if (isset($ids[$id])) {
                throw new InvalidArgumentException("Identifiant Faune-France dupliqué : {$id}.");
            }
            $ids[$id] = true;
        }

        return $catalog['entries'];
    }

    /** @return Collection<string, Collection<int, object>> */
    private function candidates(Collection $normalizedNames, int $versionId): Collection
    {
        $rows = collect();
        foreach ($normalizedNames->chunk(500) as $chunk) {
            $rows->push(...DB::table('taxon_names as tn')->join('taxa as t', 't.id', '=', 'tn.taxon_id')
                ->where('tn.taxonomic_reference_version_id', $versionId)
                ->where('t.taxref_version_id', $versionId)
                ->whereIn('tn.name_type', [
                    TaxonName::TYPE_ACCEPTED_SCIENTIFIC,
                    TaxonName::TYPE_SCIENTIFIC_SYNONYM,
                    TaxonName::TYPE_VERNACULAR,
                ])
                ->whereIn('tn.normalized_name', $chunk->all())
                ->get(['tn.normalized_name', 'tn.name_type', 't.id as taxon_id', 't.taxref_cd_ref',
                    't.scientific_name', 't.rank_code', 't.rank']));
        }

        return $rows->groupBy('normalized_name');
    }

    private function mappingChanged(TaxonSourceMapping $current, array $row, array $rawData): bool
    {
        return (int) $current->taxon_id !== $row['taxon_id']
            || $current->source_accepted_taxon_id !== $row['source_accepted_taxon_id']
            || $current->source_scientific_name !== $row['source_scientific_name']
            || $current->source_rank !== $row['source_rank']
            || $current->source_reference_version !== $row['source_reference_version']
            || $current->mapping_status !== $row['mapping_status']
            || $current->match_type !== $row['match_type']
            || (float) $current->confidence !== (float) $row['confidence']
            || (bool) $current->is_preferred !== (bool) $row['is_preferred']
            || $current->valid_to !== null
            || ($current->raw_data ?? []) != $rawData;
    }

    /** @param Collection<int, object> $candidates */
    private function ambiguousIssue(array $entry, Collection $candidates): array
    {
        return $this->entryIdentity($entry) + ['candidates' => $candidates->map(fn (object $candidate): array => [
            'taxonId' => (int) $candidate->taxon_id,
            'taxrefCdRef' => $candidate->taxref_cd_ref !== null ? (int) $candidate->taxref_cd_ref : null,
            'scientificName' => (string) $candidate->scientific_name,
        ])->values()->all()];
    }

    private function entryIdentity(array $entry): array
    {
        return [
            'fauneFranceId' => $entry['fauneFranceId'],
            'scientificName' => $entry['scientificName'],
            'vernacularName' => $entry['vernacularName'] ?? null,
        ];
    }
}
