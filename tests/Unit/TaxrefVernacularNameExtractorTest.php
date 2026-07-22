<?php

namespace Tests\Unit;

use App\Services\Biodiversity\TaxonNameNormalizer;
use App\Services\Biodiversity\TaxrefVernacularNameExtractor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TaxrefVernacularNameExtractorTest extends TestCase
{
    private TaxrefVernacularNameExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new TaxrefVernacularNameExtractor(new TaxonNameNormalizer);
    }

    #[Test]
    public function it_extracts_deduplicates_and_preserves_order_from_taxref_names(): void
    {
        self::assertSame(
            ['Hespérie du Marrube (L\')', 'Lisette (La)', 'Phycide de Jean Bourgogne'],
            $this->extractor->extract(
                "Hespérie du Marrube (L'), Hesperie du Marrube (L'); Lisette (La); Phycide de Jean Bourgogne",
            ),
        );
    }

    #[Test]
    public function it_ignores_empty_and_scientific_name_equivalents(): void
    {
        self::assertSame(
            ['Nérophis à nez droit'],
            $this->extractor->extract('Nérophis à nez droit, Nerophis ophidion', 'Nerophis ophidion'),
        );
        self::assertSame([], $this->extractor->extract(null, 'Tichodroma muraria'));
        self::assertSame([], $this->extractor->extract('  ', 'Tichodroma muraria'));
    }
}
