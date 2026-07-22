<?php

namespace App\Console\Commands;

use App\Models\TaxonomicReferenceVersion;
use App\Models\TaxonRank;
use App\Models\TaxrefRecord;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ImportTaxrefCommand extends Command
{
    private const BATCH_SIZE = 250;

    /** @var array<string, list<string>> */
    private const COLUMN_ALIASES = [
        'cd_nom' => ['CD_NOM'],
        'cd_ref' => ['CD_REF'],
        'parent_cd_ref' => ['CD_SUP', 'PARENT_CD_REF'],
        'scientific_name' => ['SCIENTIFIC_NAME', 'LB_NOM'],
        'authorship' => ['AUTHORSHIP', 'LB_AUTEUR'],
        'rank' => ['RANK', 'RANK_CODE', 'RANG'],
        'vernacular_name' => ['VERNACULAR_NAME', 'NOM_VERN'],
    ];

    /** @var list<string> */
    private const REQUIRED_COLUMNS = ['cd_nom', 'cd_ref', 'scientific_name', 'rank'];

    protected $signature = 'taxref:import
        {file : Chemin du fichier CSV ou TSV à valider/importer}
        {--reference-version= : Version TAXREF associée au fichier (alias CLI public : --version)}
        {--published-on= : Date de publication au format YYYY-MM-DD}
        {--source-uri= : URI d’origine du fichier}
        {--archive= : Archive source officielle ; si fournie, --sha256 vérifie cette archive}
        {--sha256= : Somme SHA-256 attendue de l’archive, ou du fichier si --archive est absent}
        {--file-sha256= : Somme SHA-256 attendue facultative du fichier extrait}
        {--dry-run : Valider et compter sans écrire en base}';

    protected $description = 'Valide et importe un fichier TAXREF préparatoire dans taxref_records';

    public function handle(): int
    {
        $startedAt = microtime(true);
        $file = (string) $this->argument('file');
        if (! is_file($file) || ! is_readable($file)) {
            $this->error("Le fichier TAXREF n’existe pas ou n’est pas lisible : {$file}");

            return self::FAILURE;
        }

        $versionName = trim((string) $this->option('reference-version'));
        if ($versionName === '' || mb_strlen($versionName) > 80) {
            $this->error('L’option --version est obligatoire et limitée à 80 caractères.');

            return self::FAILURE;
        }

        $publishedOn = $this->publishedOn();
        if ($publishedOn === false) {
            return self::FAILURE;
        }

        $archive = $this->nullableOption('archive');
        if ($archive !== null && (! is_file($archive) || ! is_readable($archive))) {
            $this->error("L’archive source n’existe pas ou n’est pas lisible : {$archive}");

            return self::FAILURE;
        }

        $actualFileSha256 = hash_file('sha256', $file);
        $checksumTarget = $archive ?? $file;
        $actualSha256 = hash_file('sha256', $checksumTarget);
        $expectedSha256 = strtolower(trim((string) $this->option('sha256')));
        $expectedFileSha256 = strtolower(trim((string) $this->option('file-sha256')));
        if ($actualFileSha256 === false || $actualSha256 === false
            || ($expectedSha256 !== '' && ! preg_match('/^[a-f0-9]{64}$/', $expectedSha256))
            || ($expectedFileSha256 !== '' && ! preg_match('/^[a-f0-9]{64}$/', $expectedFileSha256))) {
            $this->error('La somme --sha256 doit contenir exactement 64 caractères hexadécimaux.');

            return self::FAILURE;
        }
        if ($expectedSha256 !== '' && ! hash_equals($expectedSha256, $actualSha256)) {
            $this->error('La somme SHA-256 de la source ne correspond pas à --sha256.');

            return self::FAILURE;
        }
        if ($expectedFileSha256 !== '' && ! hash_equals($expectedFileSha256, $actualFileSha256)) {
            $this->error('La somme SHA-256 du fichier extrait ne correspond pas à --file-sha256.');

            return self::FAILURE;
        }

        try {
            [$delimiter, $headers, $columns] = $this->inspectFile($file);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $sourceMetadata = [
            'source_file' => basename($file),
            'source_file_size' => filesize($file),
            'source_file_sha256' => $actualFileSha256,
            'source_archive' => $archive === null ? null : basename($archive),
            'source_archive_size' => $archive === null ? null : filesize($archive),
            'source_uri' => $this->nullableOption('source-uri'),
            'source_sha256' => $actualSha256,
            'format' => [
                'type' => $delimiter === "\t" ? 'tsv' : 'csv',
                'delimiter' => $delimiter === "\t" ? 'tab' : 'comma',
                'quote' => 'double-quote',
                'encoding' => 'UTF-8',
                'bom' => false,
                'columns' => count($headers),
            ],
        ];

        $rankMap = $this->rankMap();
        if ($this->option('dry-run')) {
            try {
                $statistics = $this->readRows($file, $delimiter, $headers, $columns, $rankMap);
            } catch (Throwable $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }
            $statistics['duration_seconds'] = round(microtime(true) - $startedAt, 3);
            $this->displayStatistics($statistics);
            $this->info('Dry-run terminé : aucune écriture en base.');

            return self::SUCCESS;
        }

        try {
            $version = TaxonomicReferenceVersion::query()->create([
                'provider' => 'taxref',
                'version' => $versionName,
                'published_on' => $publishedOn,
                'source_uri' => $this->nullableOption('source-uri'),
                'sha256' => $actualSha256,
                'status' => TaxonomicReferenceVersion::STATUS_STAGING,
                'metadata' => $sourceMetadata,
            ]);
        } catch (Throwable $exception) {
            $this->error("Impossible de créer la version staging : {$exception->getMessage()}");

            return self::FAILURE;
        }

        $statistics = $this->emptyStatistics();
        $this->installSignalHandlers();
        try {
            $statistics = DB::transaction(fn (): array => $this->readRows(
                $file,
                $delimiter,
                $headers,
                $columns,
                $rankMap,
                $version->id,
            ));
            $statistics['duration_seconds'] = round(microtime(true) - $startedAt, 3);
            $statistics['peak_memory_bytes'] = memory_get_peak_usage(true);
            $version->update([
                'metadata' => array_merge($sourceMetadata, [
                    'line_count' => $statistics['rows_read'],
                    'import_statistics' => $statistics,
                    'batch_size' => self::BATCH_SIZE,
                ]),
                'imported_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $statistics['duration_seconds'] = round(microtime(true) - $startedAt, 3);
            $statistics['peak_memory_bytes'] = memory_get_peak_usage(true);
            $version->update([
                'status' => TaxonomicReferenceVersion::STATUS_FAILED,
                'metadata' => array_merge($sourceMetadata, [
                    'import_statistics' => $statistics,
                    'batch_size' => self::BATCH_SIZE,
                    'error' => mb_substr($exception->getMessage(), 0, 2000),
                ]),
            ]);
            $this->displayStatistics($statistics);
            $this->error("Import échoué ; la version a été marquée failed : {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->displayStatistics($statistics);
        $this->info("Version {$versionName} importée en staging ; aucune activation automatique.");

        return self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: list<string>, 2: array<string, int|null>}
     */
    private function inspectFile(string $file): array
    {
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Impossible d’ouvrir le fichier TAXREF : {$file}");
        }

        try {
            $firstLine = fgets($handle);
            if ($firstLine === false) {
                throw new \RuntimeException('Le fichier TAXREF est vide.');
            }
            $delimiter = substr_count($firstLine, "\t") > substr_count($firstLine, ',') ? "\t" : ',';
            rewind($handle);
            $headers = fgetcsv($handle, null, $delimiter, '"', '');
        } finally {
            fclose($handle);
        }

        if (! is_array($headers) || $headers === []) {
            throw new \RuntimeException('L’en-tête du fichier TAXREF est illisible.');
        }
        $headers = array_map(function (mixed $header, int $index): string {
            $value = trim((string) $header);

            return $index === 0 ? preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value : $value;
        }, $headers, array_keys($headers));
        $normalizedHeaders = array_map(static fn (string $header): string => strtoupper($header), $headers);
        $columns = [];
        foreach (self::COLUMN_ALIASES as $canonical => $aliases) {
            $columns[$canonical] = null;
            foreach ($aliases as $alias) {
                $position = array_search($alias, $normalizedHeaders, true);
                if ($position !== false) {
                    $columns[$canonical] = $position;
                    break;
                }
            }
        }
        $missing = array_values(array_filter(
            self::REQUIRED_COLUMNS,
            static fn (string $column): bool => $columns[$column] === null,
        ));
        if ($missing !== []) {
            throw new \RuntimeException('Colonnes TAXREF obligatoires absentes : '.implode(', ', $missing).'.');
        }

        return [$delimiter, $headers, $columns];
    }

    /**
     * @param  list<string>  $headers
     * @param  array<string, int|null>  $columns
     * @param  array<string, string>  $rankMap
     * @return array{rows_read: int, accepted_names: int, synonyms: int, recognized_ranks: int, unknown_ranks: int, invalid_rows: int, imported_records: int, batches: int, duration_seconds: float}
     */
    private function readRows(
        string $file,
        string $delimiter,
        array $headers,
        array $columns,
        array $rankMap,
        ?int $versionId = null,
    ): array {
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Impossible d’ouvrir le fichier TAXREF : {$file}");
        }

        $statistics = $this->emptyStatistics();
        $batch = [];
        try {
            fgetcsv($handle, null, $delimiter, '"', '');
            while (($values = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
                if ($values === [null] || collect($values)->every(static fn (mixed $value): bool => trim((string) $value) === '')) {
                    continue;
                }
                $statistics['rows_read']++;
                $record = $this->recordFromRow($values, $headers, $columns, $rankMap, $statistics, $versionId);
                if ($record === null || $versionId === null) {
                    continue;
                }
                $batch[] = $record;
                if (count($batch) >= self::BATCH_SIZE) {
                    TaxrefRecord::query()->insert($batch);
                    $statistics['imported_records'] += count($batch);
                    $statistics['batches']++;
                    $batch = [];
                }
            }
            if ($versionId !== null && $batch !== []) {
                TaxrefRecord::query()->insert($batch);
                $statistics['imported_records'] += count($batch);
                $statistics['batches']++;
            }
        } finally {
            fclose($handle);
        }

        return $statistics;
    }

    /**
     * @param  list<string|null>  $values
     * @param  list<string>  $headers
     * @param  array<string, int|null>  $columns
     * @param  array<string, string>  $rankMap
     * @param  array<string, int|float>  $statistics
     * @return array<string, mixed>|null
     */
    private function recordFromRow(
        array $values,
        array $headers,
        array $columns,
        array $rankMap,
        array &$statistics,
        ?int $versionId,
    ): ?array {
        if (count($values) !== count($headers)) {
            $statistics['invalid_rows']++;

            return null;
        }

        $value = static function (string $field) use ($values, $columns): ?string {
            $position = $columns[$field];
            if ($position === null) {
                return null;
            }
            $text = trim((string) ($values[$position] ?? ''));

            return $text === '' ? null : $text;
        };
        $cdNom = $value('cd_nom');
        $cdRef = $value('cd_ref');
        $parentCdRef = $value('parent_cd_ref');
        $scientificName = $value('scientific_name');
        $authorship = $value('authorship');
        $rawRank = strtoupper((string) $value('rank'));
        if (! $this->positiveInteger($cdNom) || ! $this->positiveInteger($cdRef)
            || ($parentCdRef !== null && ! $this->positiveInteger($parentCdRef))
            || $scientificName === null || mb_strlen($scientificName) > 512
            || ($authorship !== null && mb_strlen($authorship) > 512) || $rawRank === '') {
            $statistics['invalid_rows']++;

            return null;
        }

        $rankCode = $rankMap[$rawRank] ?? null;
        $statistics['rank_counts'][$rawRank] = ($statistics['rank_counts'][$rawRank] ?? 0) + 1;
        if ($rankCode === null) {
            $statistics['unknown_ranks']++;
            $statistics['unknown_rank_counts'][$rawRank] = ($statistics['unknown_rank_counts'][$rawRank] ?? 0) + 1;
        } else {
            $statistics['recognized_ranks']++;
        }
        $nameStatus = $cdNom === $cdRef ? TaxrefRecord::STATUS_ACCEPTED : TaxrefRecord::STATUS_SYNONYM;
        $statistics[$nameStatus === TaxrefRecord::STATUS_ACCEPTED ? 'accepted_names' : 'synonyms']++;
        $rawData = array_combine($headers, array_map(static fn (mixed $item): string => (string) $item, $values));
        if ($rawData === false) {
            $statistics['invalid_rows']++;

            return null;
        }
        $now = now();

        return [
            'taxonomic_reference_version_id' => $versionId,
            'taxon_id' => null,
            'cd_nom' => (int) $cdNom,
            'cd_ref' => (int) $cdRef,
            'parent_cd_ref' => $parentCdRef === null ? null : (int) $parentCdRef,
            'scientific_name' => $scientificName,
            'authorship' => $authorship,
            'rank_code' => $rankCode,
            'name_status' => $nameStatus,
            'raw_data' => json_encode($rawData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /** @return array<string, string> */
    private function rankMap(): array
    {
        $map = [];
        foreach (TaxonRank::query()->get() as $rank) {
            $map[strtoupper($rank->code)] = $rank->code;
            foreach ($rank->taxref_rank_codes as $sourceCode) {
                $map[strtoupper(trim((string) $sourceCode))] = $rank->code;
            }
        }

        return $map;
    }

    private function positiveInteger(?string $value): bool
    {
        return $value !== null && ctype_digit($value) && (int) $value > 0;
    }

    private function publishedOn(): CarbonImmutable|false|null
    {
        $value = trim((string) $this->option('published-on'));
        if ($value === '') {
            return null;
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            $date = false;
        }
        if ($date === false || $date->format('Y-m-d') !== $value) {
            $this->error('L’option --published-on doit utiliser une date réelle au format YYYY-MM-DD.');

            return false;
        }

        return $date;
    }

    private function nullableOption(string $name): ?string
    {
        $value = trim((string) $this->option($name));

        return $value === '' ? null : $value;
    }

    /** @return array{rows_read: int, accepted_names: int, synonyms: int, recognized_ranks: int, unknown_ranks: int, invalid_rows: int, imported_records: int, batches: int, duration_seconds: float, peak_memory_bytes: int, rank_counts: array<string, int>, unknown_rank_counts: array<string, int>} */
    private function emptyStatistics(): array
    {
        return [
            'rows_read' => 0,
            'accepted_names' => 0,
            'synonyms' => 0,
            'recognized_ranks' => 0,
            'unknown_ranks' => 0,
            'invalid_rows' => 0,
            'imported_records' => 0,
            'batches' => 0,
            'duration_seconds' => 0.0,
            'peak_memory_bytes' => 0,
            'rank_counts' => [],
            'unknown_rank_counts' => [],
        ];
    }

    /** @param array<string, int|float|array<string, int>> $statistics */
    private function displayStatistics(array $statistics): void
    {
        $this->line("Lignes lues : {$statistics['rows_read']}");
        $this->line("Noms acceptés : {$statistics['accepted_names']}");
        $this->line("Synonymes : {$statistics['synonyms']}");
        $this->line("Rangs reconnus : {$statistics['recognized_ranks']}");
        $this->line("Rangs inconnus : {$statistics['unknown_ranks']}");
        $this->line("Lignes invalides : {$statistics['invalid_rows']}");
        $this->line("Enregistrements importés : {$statistics['imported_records']}");
        $this->line("Lots écrits : {$statistics['batches']}");
        $this->line("Durée : {$statistics['duration_seconds']} s");
        $this->line("Mémoire maximale : {$statistics['peak_memory_bytes']} octets");
        $this->line('Rangs TAXREF observés : '.$this->formatCounts($statistics['rank_counts']));
        $this->line('Rangs TAXREF non mappés : '.$this->formatCounts($statistics['unknown_rank_counts']));
    }

    /** @param array<string, int> $counts */
    private function formatCounts(array $counts): string
    {
        if ($counts === []) {
            return 'aucun';
        }

        ksort($counts);

        return implode(', ', array_map(
            static fn (string $code, int $count): string => "{$code}={$count}",
            array_keys($counts),
            array_values($counts),
        ));
    }

    private function installSignalHandlers(): void
    {
        if (! function_exists('pcntl_async_signals') || ! function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);
        $handler = static function (int $signal): never {
            throw new \RuntimeException("Import interrompu par le signal {$signal}.");
        };
        pcntl_signal(SIGINT, $handler);
        pcntl_signal(SIGTERM, $handler);
    }
}
