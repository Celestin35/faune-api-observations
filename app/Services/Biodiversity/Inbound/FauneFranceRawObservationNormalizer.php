<?php

namespace App\Services\Biodiversity\Inbound;

use App\Services\Biodiversity\Data\NormalizedOccurrence;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class FauneFranceRawObservationNormalizer
{
    /**
     * @param  array<string, mixed>  $raw
     * @param  array{fauneFranceId: string, scientificName: string, vernacularName: string, rank: string}  $taxon
     */
    public function normalize(array $raw, array $taxon): NormalizedOccurrence
    {
        $observerInfo = $this->observerInfo($raw);
        $sourceOccurrenceId = $this->text($observerInfo['id_sighting'] ?? $raw['id_sighting'] ?? $raw['id'] ?? null);
        if ($sourceOccurrenceId === null) {
            throw new InvalidArgumentException('id_sighting est absent de l’observation brute.');
        }

        $latitude = $this->coordinate($observerInfo['lat'] ?? $raw['lat'] ?? null, -90, 90);
        $longitude = $this->coordinate($observerInfo['lon'] ?? $raw['lon'] ?? null, -180, 180);
        if (($latitude === null) !== ($longitude === null)) {
            throw new InvalidArgumentException('Les coordonnées Faune-France sont incomplètes.');
        }

        return new NormalizedOccurrence(
            source: 'faune-france',
            sourceOccurrenceId: $sourceOccurrenceId,
            sourceDatasetId: null,
            scientificName: $taxon['scientificName'],
            vernacularName: $taxon['vernacularName'],
            sourceTaxonId: $taxon['fauneFranceId'],
            classification: [$taxon['rank'] => $taxon['scientificName']],
            observedAt: $this->observedAt($raw, $observerInfo),
            sourceCreatedAt: null,
            sourceUpdatedAt: null,
            publishedAt: null,
            latitude: $latitude,
            longitude: $longitude,
            coordinateUncertaintyM: null,
            individualCount: $this->count($observerInfo['count'] ?? $raw['birds_count'] ?? null),
            validationStatus: null,
            observerName: null,
            license: null,
            sourceUrl: $this->sourceUrl($raw),
            media: [],
            rawData: $raw,
            locationName: $this->stripMarkup($raw['listSubmenu']['title'] ?? $raw['location'] ?? null),
            remarks: $this->remarks($raw['remarks'] ?? null),
        );
    }

    /** @param array<string, mixed> $raw @return array<string, mixed> */
    private function observerInfo(array $raw): array
    {
        foreach (($raw['opt_observers'] ?? []) as $observer) {
            $info = is_array($observer) ? ($observer['opt_observer_info'][0] ?? null) : null;
            if (is_array($info)) {
                return $info;
            }
        }

        return [];
    }

    /** @param array<string, mixed> $raw @param array<string, mixed> $observerInfo */
    private function observedAt(array $raw, array $observerInfo): ?string
    {
        $date = $this->text($raw['date_raw'] ?? $raw['date'] ?? null);
        if ($date === null) {
            return null;
        }
        $time = $this->stripMarkup($observerInfo['timing'] ?? $raw['timing'] ?? $raw['time'] ?? null);
        preg_match('/\b(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?\b/', $time ?? '', $timeMatch);
        $clock = $timeMatch[0] ?? '00:00';

        try {
            $parsed = CarbonImmutable::parse($date, 'Europe/Paris');
            [$hour, $minute, $second] = array_pad(array_map('intval', explode(':', $clock)), 3, 0);

            return $parsed->setTime($hour, $minute, $second)->toIso8601String();
        } catch (\Throwable) {
            // Fall back to the explicit date-only formats below.
        }

        foreach (['Y-m-d', 'd.m.Y', 'd/m/Y'] as $format) {
            try {
                $parsed = CarbonImmutable::createFromFormat('!'.$format.' H:i'.(strlen($clock) === 8 ? ':s' : ''), $date.' '.$clock, 'Europe/Paris');
                if ($parsed !== false) {
                    return $parsed->toIso8601String();
                }
            } catch (\Throwable) {
                // Try the next supported Faune-France date format.
            }
        }

        return null;
    }

    private function count(mixed $value): ?int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        $text = $this->text($value);

        return $text !== null && ctype_digit($text) ? (int) $text : null;
    }

    private function coordinate(mixed $value, float $minimum, float $maximum): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }
        $coordinate = (float) $value;
        if ($coordinate < $minimum || $coordinate > $maximum) {
            throw new InvalidArgumentException('Une coordonnée Faune-France est hors limites.');
        }

        return $coordinate;
    }

    private function sourceUrl(array $raw): ?string
    {
        $href = $this->text($raw['listSubmenu']['href'] ?? null);
        if ($href === null) {
            return null;
        }
        if (str_starts_with($href, '/')) {
            return 'https://www.faune-france.org'.$href;
        }

        return filter_var($href, FILTER_VALIDATE_URL) ? $href : null;
    }

    private function remarks(mixed $value): ?string
    {
        if (! is_array($value)) {
            return $this->stripMarkup($value);
        }
        $parts = [];
        foreach ($value as $remark) {
            if (! is_array($remark)) {
                continue;
            }
            $part = $this->stripMarkup($remark['content'] ?? $remark['title'] ?? null);
            if ($part !== null) {
                $parts[] = $part;
            }
        }

        return $parts === [] ? null : implode("\n", $parts);
    }

    private function stripMarkup(mixed $value): ?string
    {
        $text = $this->text($value);
        if ($text === null) {
            return null;
        }
        $clean = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');

        return $clean === '' ? null : $clean;
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
