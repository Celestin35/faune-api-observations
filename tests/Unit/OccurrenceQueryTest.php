<?php

namespace Tests\Unit;

use App\Services\Biodiversity\Data\OccurrenceQuery;
use App\Services\Biodiversity\Data\SpatialFilter;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OccurrenceQueryTest extends TestCase
{
    #[Test]
    public function it_builds_closed_wkt_for_a_radius(): void
    {
        $wkt = SpatialFilter::circleWkt(45.0, 6.0, 10.0, 8);
        preg_match('/POLYGON\(\((.*)\)\)/', $wkt, $matches);
        $points = explode(',', $matches[1]);

        self::assertCount(9, $points);
        self::assertSame($points[0], $points[8]);
    }

    #[Test]
    public function a_radius_requires_a_complete_point(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new OccurrenceQuery(latitude: 45, radiusKm: 10);
    }

    #[Test]
    public function a_date_range_must_be_complete_and_ordered(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new OccurrenceQuery(from: '2026-07-20');
    }
}
