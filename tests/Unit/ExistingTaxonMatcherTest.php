<?php

namespace Tests\Unit;

use App\Services\Biodiversity\Taxref\ExistingTaxonMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExistingTaxonMatcherTest extends TestCase
{
    #[DataProvider('matchesProvider')]
    public function test_match_statuses(array $source, array $accepted, array $synonyms, string $expected): void
    {
        $result = (new ExistingTaxonMatcher)->match(
            ['rank' => 'species', 'classification' => ['regne' => 'Animalia']],
            $source,
            $accepted,
            $synonyms,
        );

        $this->assertSame($expected, $result['status']);
    }

    public static function matchesProvider(): iterable
    {
        $one = [['cd_ref' => 1, 'rank_code' => 'species', 'classification' => ['regne' => 'Animalia']]];
        $two = [...$one, ['cd_ref' => 2, 'rank_code' => 'species', 'classification' => ['regne' => 'Animalia']]];

        yield 'exact source match' => [$one, [], [], 'exact'];
        yield 'synonym match' => [[], [], $one, 'synonym'];
        yield 'ambiguous accepted name' => [[], $two, [], 'ambiguous'];
        yield 'unresolved' => [[], [], [], 'unresolved'];
    }
}
