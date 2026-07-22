<?php

namespace App\Console\Commands;

use App\Services\Biodiversity\SourceRegistry;
use Illuminate\Console\Command;
use Throwable;

final class BiodiversityTestSourceCommand extends Command
{
    protected $signature = 'biodiversity:test-source {source : gbif, inaturalist, taxref, ebird, obis or geonature}';

    protected $description = 'Run a deliberately small live smoke test against one biodiversity source';

    public function handle(SourceRegistry $registry): int
    {
        $source = strtolower((string) $this->argument('source'));
        $connector = $registry->connector($source);
        $status = $registry->status($source);

        if ($connector === null) {
            $this->warn("{$source}: {$status['verdict']} — {$status['reason']}");

            return in_array($source, $registry->keys(), true) ? self::FAILURE : self::INVALID;
        }

        $query = $registry->sampleQuery($source);

        try {
            $result = $connector->search($query, 3);
            $this->info("{$source}: HTTP/format OK; {$result->total} résultat(s), ".count($result->occurrences).' échantillon(s) normalisé(s).');
            $this->line(json_encode($result->occurrences, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (Throwable $exception) {
            $this->error("{$source}: échec — {$exception->getMessage()}");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
