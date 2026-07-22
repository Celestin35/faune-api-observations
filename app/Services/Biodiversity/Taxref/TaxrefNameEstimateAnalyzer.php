<?php

namespace App\Services\Biodiversity\Taxref;

use App\Services\Biodiversity\TaxonNameNormalizer;
use App\Services\Biodiversity\TaxrefVernacularNameExtractor;
use PDO;

final class TaxrefNameEstimateAnalyzer
{
    public function __construct(
        private readonly TaxonNameNormalizer $normalizer,
        private readonly TaxrefVernacularNameExtractor $vernacularExtractor,
    ) {}

    /**
     * Small-fixture analyzer used to validate the same deduplication rules as the SQL report.
     *
     * @param  list<array{cd_ref:int,status:string,scientific_name:string,vernacular_name?:?string}>  $records
     * @return array<string, int>
     */
    public function analyze(array $records): array
    {
        $accepted = [];
        foreach ($records as $record) {
            if ($record['status'] === 'accepted') {
                $accepted[$record['cd_ref']] = $record['scientific_name'];
            }
        }

        $names = [];
        $synonymConcepts = [];
        $vernacularConcepts = [];
        $multiValueCells = 0;
        $identicalScientificVernacular = 0;
        foreach ($records as $record) {
            $concept = $record['cd_ref'];
            $scientific = $accepted[$concept] ?? $record['scientific_name'];
            $key = $this->normalizer->normalize($record['scientific_name']);
            $type = $record['status'] === 'accepted' ? 'accepted_scientific' : 'scientific_synonym';
            $names[$concept][$type][$key] = true;
            if ($type === 'scientific_synonym') {
                $synonymConcepts[$key][$concept] = true;
            }

            $rawVernacular = $record['vernacular_name'] ?? null;
            if ($rawVernacular !== null && preg_match('/[,;]/u', $rawVernacular)) {
                $multiValueCells++;
            }
            foreach ($this->vernacularExtractor->extract($rawVernacular, $scientific) as $name) {
                $vernacularKey = $this->normalizer->normalize($name);
                $names[$concept]['vernacular'][$vernacularKey] = true;
                $vernacularConcepts[$vernacularKey][$concept] = true;
            }
            if ($rawVernacular !== null && $this->normalizer->normalize($rawVernacular) === $this->normalizer->normalize($scientific)) {
                $identicalScientificVernacular++;
            }
        }

        $counts = ['accepted_scientific' => 0, 'scientific_synonym' => 0, 'vernacular' => 0];
        foreach ($names as $types) {
            foreach ($counts as $type => $_) {
                $counts[$type] += count($types[$type] ?? []);
            }
        }

        return [
            ...$counts,
            'total_taxon_names' => array_sum($counts),
            'synonym_names_shared_by_concepts' => count(array_filter($synonymConcepts, static fn (array $concepts): bool => count($concepts) > 1)),
            'vernacular_names_shared_by_concepts' => count(array_filter($vernacularConcepts, static fn (array $concepts): bool => count($concepts) > 1)),
            'scientific_vernacular_identical_cells' => $identicalScientificVernacular,
            'multi_value_vernacular_cells' => $multiValueCells,
        ];
    }

    /**
     * Analyze a large ordered TAXREF stream with bounded PHP memory. The temporary SQLite
     * database is a report work file only and is deleted by the caller.
     *
     * @param  iterable<object>  $records  records ordered by cd_ref
     * @return array<string, int|string>
     */
    public function analyzeStream(iterable $records, string $temporaryDatabase): array
    {
        @unlink($temporaryDatabase);
        $pdo = new PDO('sqlite:'.$temporaryDatabase);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode=OFF; PRAGMA synchronous=OFF; PRAGMA temp_store=MEMORY');
        $pdo->exec('CREATE TABLE names (concept INTEGER NOT NULL, type TEXT NOT NULL, normalized TEXT NOT NULL, name TEXT NOT NULL, PRIMARY KEY (concept, type, normalized)) WITHOUT ROWID');
        $insert = $pdo->prepare('INSERT OR IGNORE INTO names(concept,type,normalized,name) VALUES(?,?,?,?)');
        $statistics = [
            'accepted_scientific_names' => 0,
            'scientific_synonym_rows' => 0,
            'vernacular_raw_parts' => 0,
            'scientific_vernacular_identical_parts_excluded' => 0,
            'multi_value_vernacular_cells' => 0,
        ];
        $currentConcept = null;
        $group = [];
        $pdo->beginTransaction();
        foreach ($records as $record) {
            $concept = (int) $record->cd_ref;
            if ($currentConcept !== null && $concept !== $currentConcept) {
                $this->persistConceptNames($group, $insert, $statistics);
                $group = [];
            }
            $currentConcept = $concept;
            $group[] = $record;
        }
        if ($group !== []) {
            $this->persistConceptNames($group, $insert, $statistics);
        }
        $pdo->commit();
        $pdo->exec('CREATE INDEX names_type_normalized ON names(type, normalized)');

        $count = static fn (PDO $pdo, string $sql): int => (int) $pdo->query($sql)->fetchColumn();
        $synonyms = $count($pdo, "SELECT count(*) FROM names WHERE type='scientific_synonym'");
        $vernacular = $count($pdo, "SELECT count(*) FROM names WHERE type='vernacular'");
        $statistics += [
            'scientific_synonyms_unique_per_concept' => $synonyms,
            'scientific_synonym_duplicates_eliminable' => $statistics['scientific_synonym_rows'] - $synonyms,
            'synonym_names_shared_by_concepts' => $count($pdo, "SELECT count(*) FROM (SELECT normalized FROM names WHERE type='scientific_synonym' GROUP BY normalized HAVING count(*)>1)"),
            'vernacular_names_unique_per_concept' => $vernacular,
            'vernacular_duplicates_eliminable' => max(0, $statistics['vernacular_raw_parts'] - $statistics['scientific_vernacular_identical_parts_excluded'] - $vernacular),
            'vernacular_globally_unique_names' => $count($pdo, "SELECT count(DISTINCT normalized) FROM names WHERE type='vernacular'"),
            'vernacular_names_shared_by_concepts' => $count($pdo, "SELECT count(*) FROM (SELECT normalized FROM names WHERE type='vernacular' GROUP BY normalized HAVING count(*)>1)"),
            'estimated_taxon_names_rows' => $statistics['accepted_scientific_names'] + $synonyms + $vernacular,
            'total_name_rows_eliminable' => ($statistics['scientific_synonym_rows'] - $synonyms)
                + max(0, $statistics['vernacular_raw_parts'] - $statistics['scientific_vernacular_identical_parts_excluded'] - $vernacular),
            'deduplication' => 'TaxrefVernacularNameExtractor + TaxonNameNormalizer ; unicité par concept, type et nom normalisé',
        ];
        $pdo = null;

        return $statistics;
    }

    /** @param list<object> $records @param array<string, int> $statistics */
    private function persistConceptNames(array $records, \PDOStatement $insert, array &$statistics): void
    {
        $accepted = null;
        foreach ($records as $record) {
            if ($record->name_status === 'accepted') {
                $accepted = (string) $record->scientific_name;
                $insert->execute([(int) $record->cd_ref, 'accepted_scientific', $this->normalizer->normalize($accepted), $accepted]);
                $statistics['accepted_scientific_names']++;
                break;
            }
        }
        $accepted ??= (string) $records[0]->scientific_name;
        $acceptedKey = $this->normalizer->normalize($accepted);
        foreach ($records as $record) {
            if ($record->name_status === 'synonym') {
                $statistics['scientific_synonym_rows']++;
                $insert->execute([(int) $record->cd_ref, 'scientific_synonym', $this->normalizer->normalize((string) $record->scientific_name), (string) $record->scientific_name]);
            }
            $raw = is_array($record->raw_data) ? $record->raw_data : json_decode((string) $record->raw_data, true);
            $cell = trim((string) ($raw['NOM_VERN'] ?? ''));
            if ($cell === '') {
                continue;
            }
            $parts = preg_split('/\s*[,;]\s*/u', $cell) ?: [];
            $statistics['vernacular_raw_parts'] += count(array_filter($parts, static fn (string $part): bool => trim($part) !== ''));
            if (preg_match('/[,;]/u', $cell)) {
                $statistics['multi_value_vernacular_cells']++;
            }
            foreach ($parts as $part) {
                if (trim($part) !== '' && $this->normalizer->normalize($part) === $acceptedKey) {
                    $statistics['scientific_vernacular_identical_parts_excluded']++;
                }
            }
            foreach ($this->vernacularExtractor->extract($cell, $accepted) as $name) {
                $insert->execute([(int) $record->cd_ref, 'vernacular', $this->normalizer->normalize($name), $name]);
            }
        }
    }
}
