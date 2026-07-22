<?php

namespace Tests\Unit;

use App\Services\Biodiversity\Taxref\ScientificNameGroupAnalyzer;
use PHPUnit\Framework\TestCase;

final class ScientificNameGroupAnalyzerTest extends TestCase
{
    public function test_a_unique_name_is_not_a_homonym(): void
    {
        $summary = (new ScientificNameGroupAnalyzer)->summarize([$this->candidate(1, 'Aus bus', 'A.', 'ES')]);

        $this->assertFalse($summary['is_homonym']);
        $this->assertSame(1, $summary['concepts']);
    }

    public function test_homonyms_preserve_authors_and_report_differences(): void
    {
        $summary = (new ScientificNameGroupAnalyzer)->summarize([
            $this->candidate(1, 'Aus bus', 'A.', 'ES'),
            $this->candidate(2, 'Aus bus', 'B.', 'GN'),
        ]);

        $this->assertTrue($summary['is_homonym']);
        $this->assertTrue($summary['strictly_identical']);
        $this->assertTrue($summary['authors_differ']);
        $this->assertTrue($summary['ranks_differ']);
    }

    /** @return array<string, mixed> */
    private function candidate(int $id, string $name, string $author, string $rank): array
    {
        return ['cd_ref' => $id, 'name' => $name, 'authorship' => $author, 'raw_rank' => $rank, 'rank_code' => null, 'parent_cd_ref' => null, 'lineage' => 'Animalia'];
    }
}
