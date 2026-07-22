<?php

namespace App\Services\Biodiversity;

final class TaxrefVernacularNameExtractor
{
    public function __construct(private readonly TaxonNameNormalizer $normalizer) {}

    /** @return list<string> */
    public function extract(?string $value, ?string $scientificName = null): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        $scientificNameKey = $scientificName === null ? null : $this->normalizer->normalize($scientificName);
        $seen = [];
        $names = [];
        foreach (preg_split('/\s*[,;]\s*/u', trim($value)) ?: [] as $candidate) {
            $name = trim($candidate);
            if ($name === '') {
                continue;
            }

            $key = $this->normalizer->normalize($name);
            if ($key === '' || $key === $scientificNameKey || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $names[] = $name;
        }

        return $names;
    }
}
