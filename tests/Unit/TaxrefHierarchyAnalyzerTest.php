<?php

namespace Tests\Unit;

use App\Services\Biodiversity\Taxref\TaxrefHierarchyAnalyzer;
use PHPUnit\Framework\TestCase;

final class TaxrefHierarchyAnalyzerTest extends TestCase
{
    public function test_depth_and_path_row_estimate_include_self_and_ancestors(): void
    {
        $summary = (new TaxrefHierarchyAnalyzer)->analyze([
            ['cd_ref' => 1, 'parent_cd_ref' => null],
            ['cd_ref' => 2, 'parent_cd_ref' => 1],
            ['cd_ref' => 3, 'parent_cd_ref' => 2],
        ]);

        $this->assertSame(2, $summary['max_depth']);
        $this->assertSame(6, $summary['taxon_paths_rows']);
        $this->assertSame(0, $summary['cycle_groups']);
    }

    public function test_cycles_are_reported_without_infinite_loop(): void
    {
        $summary = (new TaxrefHierarchyAnalyzer)->analyze([
            ['cd_ref' => 1, 'parent_cd_ref' => 2],
            ['cd_ref' => 2, 'parent_cd_ref' => 1],
        ]);

        $this->assertSame(1, $summary['cycle_groups']);
        $this->assertSame(2, $summary['cycle_nodes']);
        $this->assertSame(0, $summary['taxon_paths_rows']);
    }
}
