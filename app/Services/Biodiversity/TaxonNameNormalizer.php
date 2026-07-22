<?php

namespace App\Services\Biodiversity;

use Illuminate\Support\Str;

final class TaxonNameNormalizer
{
    public function normalize(string $name): string
    {
        $normalized = mb_strtolower(Str::ascii($name, 'fr'), 'UTF-8');
        $normalized = preg_replace('/[^a-z0-9]+/u', ' ', $normalized) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $normalized) ?? '');
    }
}
