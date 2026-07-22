<?php

namespace App\Services\Biodiversity;

use App\Services\Biodiversity\Data\NormalizedOccurrence;

final class DeduplicationHints
{
    /** @return list<string> */
    public function for(NormalizedOccurrence $occurrence): array
    {
        $hints = [
            $this->canonicalIdentifier($occurrence->sourceOccurrenceId),
            $this->canonicalIdentifier($occurrence->sourceUrl),
        ];

        foreach ($occurrence->rawData['identifiers'] ?? [] as $identifier) {
            if (is_array($identifier)) {
                $hints[] = $this->canonicalIdentifier(isset($identifier['identifier']) ? (string) $identifier['identifier'] : null);
            } elseif (is_scalar($identifier)) {
                $hints[] = $this->canonicalIdentifier((string) $identifier);
            }
        }

        $references = $occurrence->rawData['references'] ?? null;
        if (is_string($references)) {
            $hints[] = $this->canonicalIdentifier($references);
        }

        return array_values(array_unique(array_filter($hints)));
    }

    public function primaryFor(NormalizedOccurrence $occurrence): string
    {
        $hints = $this->for($occurrence);
        foreach ($hints as $hint) {
            if (str_starts_with($hint, 'inaturalist:')) {
                return $hint;
            }
        }

        return $occurrence->source.':'.strtolower(trim($occurrence->sourceOccurrenceId));
    }

    private function canonicalIdentifier(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        if (preg_match('~inaturalist\.org/observations/(\d+)~i', $value, $matches) === 1) {
            return 'inaturalist:'.$matches[1];
        }

        return strtolower(rtrim($value, '/'));
    }
}
