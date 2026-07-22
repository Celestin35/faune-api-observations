<?php

namespace Tests\Unit;

use App\Services\Biodiversity\TaxonNameNormalizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TaxonNameNormalizerTest extends TestCase
{
    #[Test]
    public function it_normalizes_accents_spacing_case_and_punctuation(): void
    {
        $normalizer = new TaxonNameNormalizer;

        self::assertSame('tichodrome echelette', $normalizer->normalize('Tichodrome échelette'));
        self::assertSame('tichodroma muraria', $normalizer->normalize('  Tichodroma   muraria '));
        self::assertSame('tichodrome echelette', $normalizer->normalize('Tichodrome — échelette !'));
    }
}
