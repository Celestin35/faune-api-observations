<?php

namespace App\Console\Commands;

use App\Services\Biodiversity\Data\OccurrenceQuery;
use App\Services\Biodiversity\SourceRegistry;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

final class BiodiversityCountCommand extends Command
{
    protected $signature = 'biodiversity:count
        {--source= : Source key}
        {--taxon= : Scientific name or higher taxon}
        {--from= : Start date YYYY-MM-DD}
        {--to= : End date YYYY-MM-DD}
        {--country= : ISO alpha-2 country code}
        {--point= : Latitude,longitude}
        {--radius= : Radius in kilometres (requires --point)}
        {--bbox= : South,west,north,east}
        {--department= : Source-native department id (GBIF GADM gid or iNaturalist place_id)}
        {--region= : Source-native region id (GBIF GADM gid or iNaturalist place_id)}';

    protected $description = 'Count matching observations without downloading all records';

    public function handle(SourceRegistry $registry): int
    {
        $source = strtolower((string) $this->option('source'));
        $connector = $registry->connector($source);

        if ($connector === null) {
            $status = $registry->status($source);
            $this->error("{$source}: {$status['verdict']} — {$status['reason']}");

            return self::INVALID;
        }

        try {
            [$latitude, $longitude] = $this->coordinates((string) $this->option('point'));
            $query = new OccurrenceQuery(
                taxon: $this->nullableOption('taxon'),
                from: $this->nullableOption('from'),
                to: $this->nullableOption('to'),
                country: $this->nullableOption('country'),
                latitude: $latitude,
                longitude: $longitude,
                radiusKm: $this->option('radius') !== null ? (float) $this->option('radius') : null,
                boundingBox: $this->boundingBox((string) $this->option('bbox')),
                department: $this->nullableOption('department'),
                region: $this->nullableOption('region'),
            );

            $count = $connector->count($query);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("{$source}: {$count} observation(s)");

        return self::SUCCESS;
    }

    /** @return array{0: ?float, 1: ?float} */
    private function coordinates(string $value): array
    {
        if ($value === '') {
            return [null, null];
        }

        $parts = array_map('trim', explode(',', $value));
        if (count($parts) !== 2 || ! is_numeric($parts[0]) || ! is_numeric($parts[1])) {
            throw new InvalidArgumentException('--point must be latitude,longitude.');
        }

        return [(float) $parts[0], (float) $parts[1]];
    }

    /** @return array{south: float, west: float, north: float, east: float}|null */
    private function boundingBox(string $value): ?array
    {
        if ($value === '') {
            return null;
        }

        $parts = array_map('trim', explode(',', $value));
        if (count($parts) !== 4 || count(array_filter($parts, 'is_numeric')) !== 4) {
            throw new InvalidArgumentException('--bbox must be south,west,north,east.');
        }

        return ['south' => (float) $parts[0], 'west' => (float) $parts[1], 'north' => (float) $parts[2], 'east' => (float) $parts[3]];
    }

    private function nullableOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
