<?php

namespace Tests\Unit;

use App\Services\Biodiversity\CoverageCalculator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CoverageCalculatorTest extends TestCase
{
    #[Test]
    public function it_calculates_missing_periods_and_merges_overlaps(): void
    {
        $missing = (new CoverageCalculator)->missing('2026-01-01', '2026-01-10', [
            ['from' => '2026-01-02', 'to' => '2026-01-04'], ['from' => '2026-01-04', 'to' => '2026-01-08'],
        ]);
        self::assertSame([['from' => '2026-01-01', 'to' => '2026-01-01'], ['from' => '2026-01-09', 'to' => '2026-01-10']], $missing);
    }
}
