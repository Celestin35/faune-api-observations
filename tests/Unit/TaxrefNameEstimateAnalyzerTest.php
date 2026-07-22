<?php

namespace Tests\Unit;

use App\Services\Biodiversity\TaxonNameNormalizer;
use App\Services\Biodiversity\Taxref\TaxrefNameEstimateAnalyzer;
use App\Services\Biodiversity\TaxrefVernacularNameExtractor;
use PHPUnit\Framework\TestCase;

final class TaxrefNameEstimateAnalyzerTest extends TestCase
{
    public function test_names_are_split_normalized_and_deduplicated_per_concept(): void
    {
        $normalizer = new TaxonNameNormalizer;
        $analyzer = new TaxrefNameEstimateAnalyzer($normalizer, new TaxrefVernacularNameExtractor($normalizer));
        $result = $analyzer->analyze([
            ['cd_ref' => 1, 'status' => 'accepted', 'scientific_name' => 'Aus bus', 'vernacular_name' => 'Nom français; Nom français'],
            ['cd_ref' => 1, 'status' => 'synonym', 'scientific_name' => 'Aus oldus'],
            ['cd_ref' => 2, 'status' => 'accepted', 'scientific_name' => 'Cus dus', 'vernacular_name' => 'Nom français'],
        ]);

        $this->assertSame(2, $result['accepted_scientific']);
        $this->assertSame(1, $result['scientific_synonym']);
        $this->assertSame(2, $result['vernacular']);
        $this->assertSame(1, $result['vernacular_names_shared_by_concepts']);
        $this->assertSame(5, $result['total_taxon_names']);
    }
}
