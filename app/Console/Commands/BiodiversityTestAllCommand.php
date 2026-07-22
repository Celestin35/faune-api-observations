<?php

namespace App\Console\Commands;

use App\Services\Biodiversity\SourceRegistry;
use Illuminate\Console\Command;
use Throwable;

final class BiodiversityTestAllCommand extends Command
{
    protected $signature = 'biodiversity:test-all';

    protected $description = 'Run small live smoke tests and report unavailable biodiversity sources';

    public function handle(SourceRegistry $registry): int
    {
        $failed = false;
        foreach ($registry->keys() as $source) {
            $connector = $registry->connector($source);
            $status = $registry->status($source);

            if ($connector === null) {
                $this->warn("{$source}: {$status['verdict']} — {$status['reason']}");

                continue;
            }

            try {
                $result = $connector->search($registry->sampleQuery($source), 1);
                $this->info("{$source}: OK — total {$result->total}; échantillon ".count($result->occurrences));
            } catch (Throwable $exception) {
                $failed = true;
                $this->error("{$source}: échec — {$exception->getMessage()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
