<?php

namespace App\Services\Biodiversity;

use Carbon\CarbonImmutable;

final class CoverageCalculator
{
    /**
     * @param  list<array{from: string, to: string}>  $covered
     * @return list<array{from: string, to: string}>
     */
    public function missing(string $from, string $to, array $covered): array
    {
        usort($covered, static fn (array $a, array $b): int => $a['from'] <=> $b['from']);
        $cursor = CarbonImmutable::parse($from);
        $end = CarbonImmutable::parse($to);
        $missing = [];

        foreach ($covered as $range) {
            $start = CarbonImmutable::parse($range['from']);
            $rangeEnd = CarbonImmutable::parse($range['to']);
            if ($rangeEnd->lt($cursor) || $start->gt($end)) {
                continue;
            }
            if ($start->gt($cursor)) {
                $missing[] = ['from' => $cursor->toDateString(), 'to' => $start->subDay()->min($end)->toDateString()];
            }
            if ($rangeEnd->gte($cursor)) {
                $cursor = $rangeEnd->addDay();
            }
            if ($cursor->gt($end)) {
                break;
            }
        }
        if ($cursor->lte($end)) {
            $missing[] = ['from' => $cursor->toDateString(), 'to' => $end->toDateString()];
        }

        return $missing;
    }
}
