<?php

namespace App\Services\Biodiversity;

use App\Models\DataCollection;
use App\Models\MonitoringRule;
use App\Models\Observation;
use App\Models\ObservationDeduplicationCandidate;
use App\Models\ObservationSource;
use App\Models\Taxon;
use App\Models\TaxonSourceMapping;
use App\Services\Biodiversity\Data\NormalizedOccurrence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class OccurrencePersister
{
    private const LOCATION_PRIORITY = [
        'unavailable' => 0,
        'source_masked' => 1,
        'approximate' => 2,
        'exact' => 3,
    ];

    private const TEMPORAL_PRIORITY = ['unknown' => 0, 'date' => 1, 'datetime' => 2];

    public function __construct(
        private DeduplicationHints $hints,
        private CanonicalTaxonMatcher $canonicalTaxonMatcher,
    ) {}

    public function persist(NormalizedOccurrence $item, ?int $collectionId = null, ?int $monitoringRuleId = null): PersistOutcome
    {
        return DB::transaction(function () use ($item, $collectionId, $monitoringRuleId): PersistOutcome {
            $existingSource = ObservationSource::query()->where('source', $item->source)
                ->where('source_occurrence_id', $item->sourceOccurrenceId)->first();
            $identifiers = $this->hints->for($item);
            $originKey = $this->hints->primaryFor($item);

            if ($existingSource !== null) {
                $before = hash('sha256', json_encode($existingSource->raw_data, JSON_THROW_ON_ERROR));
                $observation = $existingSource->observation;
                $onlySource = $observation->sources()->count() === 1;
                $existingSource->fill($this->sourceAttributes($item, $identifiers, $originKey))->save();
                $this->syncMedia($existingSource, $item->media);
                $this->updateCanonical($observation, $item, allowEqualLocation: $onlySource);
                $observation->update(['last_seen_at' => now()]);
                $this->attach($observation, $collectionId, $monitoringRuleId);
                $after = hash('sha256', json_encode($item->rawData, JSON_THROW_ON_ERROR));

                return new PersistOutcome($observation->fresh(['taxon', 'sources.media']), $before === $after ? 'unchanged' : 'updated');
            }

            $matchingSource = ObservationSource::query()->where('origin_key', $originKey)->first();
            $taxon = $this->taxon($item);
            $observation = $matchingSource?->observation;
            $created = false;
            if ($observation === null) {
                $created = true;
                $observation = Observation::create($this->newObservationAttributes($item, $taxon?->id) + [
                    'first_imported_at' => now(),
                    'last_seen_at' => now(),
                ]);
                $this->syncGeometry($observation);
            } else {
                $this->updateCanonical($observation, $item);
            }

            $source = ObservationSource::create(['observation_id' => $observation->id]
                + $this->sourceAttributes($item, $identifiers, $originKey));
            $this->syncMedia($source, $item->media);
            $this->attach($observation, $collectionId, $monitoringRuleId);
            if ($created) {
                $this->recordCandidates($observation);
            }

            return new PersistOutcome($observation->fresh(['taxon', 'sources.media']), $created ? 'created' : 'updated');
        });
    }

    /** @return array<string, mixed> */
    private function newObservationAttributes(NormalizedOccurrence $item, ?int $taxonId): array
    {
        $coordinatesAllowed = $this->coordinatesArePublic($item);
        $locationStatus = $this->locationStatus($item);

        return [
            'taxon_id' => $taxonId,
            'observed_at' => $this->date($item->observedAt),
            'temporal_precision' => $item->temporalPrecision,
            'latitude' => $coordinatesAllowed ? $item->latitude : null,
            'longitude' => $coordinatesAllowed ? $item->longitude : null,
            'coordinate_uncertainty_m' => $coordinatesAllowed ? $item->coordinateUncertaintyM : null,
            'location_status' => $locationStatus,
            'individual_count' => $item->individualCount,
            'validation_status' => $item->validationStatus,
            'observer_name' => $item->observerIsPublic ? $item->observerName : null,
            'location_name' => $item->locationName,
            'remarks' => $item->remarks,
            'country_code' => $item->countryCode,
            'country_name' => $item->countryName,
            'region_name' => $item->regionName,
            'department_code' => $item->departmentCode,
            'department_name' => $item->departmentName,
            'municipality_code' => $item->municipalityCode,
            'municipality_name' => $item->municipalityName,
            'locality_name' => $item->localityName,
            'geography_resolution_method' => $this->hasGeography($item) ? 'source' : 'none',
            'geography_resolved_at' => $this->hasGeography($item) ? now() : null,
            'life_stage' => $item->lifeStage,
            'sex' => $item->sex,
            'behavior' => $item->behavior,
        ];
    }

    private function updateCanonical(Observation $observation, NormalizedOccurrence $item, bool $allowEqualLocation = false): void
    {
        $attributes = ['last_seen_at' => now()];
        $locationStatus = $this->locationStatus($item);
        $incomingLocation = self::LOCATION_PRIORITY[$locationStatus] ?? 0;
        $currentLocation = self::LOCATION_PRIORITY[$observation->location_status ?? 'unavailable'] ?? 0;
        if ($incomingLocation > $currentLocation || ($allowEqualLocation && $incomingLocation === $currentLocation)) {
            $coordinatesAllowed = $this->coordinatesArePublic($item);
            $attributes += [
                'latitude' => $coordinatesAllowed ? $item->latitude : null,
                'longitude' => $coordinatesAllowed ? $item->longitude : null,
                'coordinate_uncertainty_m' => $coordinatesAllowed ? $item->coordinateUncertaintyM : null,
                'location_status' => $locationStatus,
                'location_name' => $item->locationName,
                'country_code' => $item->countryCode,
                'country_name' => $item->countryName,
                'region_name' => $item->regionName,
                'department_code' => $item->departmentCode,
                'department_name' => $item->departmentName,
                'municipality_code' => $item->municipalityCode,
                'municipality_name' => $item->municipalityName,
                'locality_name' => $item->localityName,
                'geography_resolution_method' => $this->hasGeography($item) ? 'source' : 'none',
                'geography_resolved_at' => $this->hasGeography($item) ? now() : null,
            ];
        }

        $incomingTemporal = self::TEMPORAL_PRIORITY[$item->temporalPrecision] ?? 0;
        $currentTemporal = self::TEMPORAL_PRIORITY[$observation->temporal_precision ?? 'unknown'] ?? 0;
        if ($observation->observed_at === null || $incomingTemporal > $currentTemporal || ($allowEqualLocation && $incomingTemporal === $currentTemporal)) {
            $attributes['observed_at'] = $this->date($item->observedAt);
            $attributes['temporal_precision'] = $item->temporalPrecision;
        }

        foreach ([
            'individual_count' => $item->individualCount,
            'validation_status' => $item->validationStatus,
            'remarks' => $item->remarks,
            'life_stage' => $item->lifeStage,
            'sex' => $item->sex,
            'behavior' => $item->behavior,
        ] as $field => $value) {
            if ($observation->{$field} === null && $value !== null) {
                $attributes[$field] = $value;
            }
        }
        if ($observation->observer_name === null && $item->observerIsPublic && $item->observerName !== null) {
            $attributes['observer_name'] = $item->observerName;
        }

        $observation->update($attributes);
        $this->syncGeometry($observation->fresh());
    }

    /** @return array<string, mixed> */
    private function sourceAttributes(NormalizedOccurrence $item, array $identifiers, string $originKey): array
    {
        $coordinatesAllowed = $this->coordinatesArePublic($item);
        $locationStatus = $this->locationStatus($item);

        return [
            'source' => $item->source,
            'source_occurrence_id' => $item->sourceOccurrenceId,
            'source_dataset_id' => $item->sourceDatasetId,
            'source_taxon_id' => $item->sourceTaxonId,
            'origin_key' => $originKey,
            'source_url' => $item->sourceUrl,
            'license' => $item->license,
            'source_created_at' => $this->date($item->sourceCreatedAt),
            'source_updated_at' => $this->date($item->sourceUpdatedAt),
            'published_at' => $this->date($item->publishedAt),
            'canonical_identifiers' => $identifiers,
            'raw_data' => $item->rawData,
            'source_scientific_name' => $item->scientificName,
            'source_vernacular_name' => $item->vernacularName,
            'source_observed_at' => $this->date($item->observedAt),
            'source_temporal_precision' => $item->temporalPrecision,
            'public_latitude' => $coordinatesAllowed ? $item->latitude : null,
            'public_longitude' => $coordinatesAllowed ? $item->longitude : null,
            'coordinate_uncertainty_m' => $coordinatesAllowed ? $item->coordinateUncertaintyM : null,
            'location_status' => $locationStatus,
            'source_location_precision' => $item->sourceLocationPrecision,
            'source_location_name' => $item->locationName,
            'source_country_code' => $item->countryCode,
            'source_country_name' => $item->countryName,
            'source_region_name' => $item->regionName,
            'source_department_code' => $item->departmentCode,
            'source_department_name' => $item->departmentName,
            'source_municipality_code' => $item->municipalityCode,
            'source_municipality_name' => $item->municipalityName,
            'source_observer_name' => $item->observerIsPublic ? $item->observerName : null,
            'observer_is_public' => $item->observerIsPublic,
            'source_individual_count' => $item->individualCount,
            'source_validation_status' => $item->validationStatus,
            'life_stage' => $item->lifeStage,
            'sex' => $item->sex,
            'behavior' => $item->behavior,
            'remarks' => $item->remarks,
        ];
    }

    /** @param list<array<string, mixed>> $media */
    private function syncMedia(ObservationSource $source, array $media): void
    {
        $urls = [];
        foreach (array_values($media) as $position => $item) {
            if (! is_array($item)) {
                continue;
            }
            $url = $item['url'] ?? null;
            if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false
                || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
                continue;
            }
            $urls[] = $url;
            $source->media()->updateOrCreate(['url' => $url], [
                'media_type' => isset($item['type']) ? (string) $item['type'] : null,
                'thumbnail_url' => $this->publicUrl($item['thumbnail_url'] ?? null),
                'source_page_url' => $this->publicUrl($item['page_url'] ?? null),
                'license' => $item['license'] ?? null,
                'attribution' => $item['attribution'] ?? null,
                'position' => $position,
            ]);
        }
        if ($urls === []) {
            $source->media()->delete();
        } else {
            $source->media()->whereNotIn('url', $urls)->delete();
        }
    }

    private function coordinatesArePublic(NormalizedOccurrence $item): bool
    {
        return $item->latitude !== null && $item->longitude !== null
            && in_array($this->locationStatus($item), ['exact', 'approximate', 'source_masked'], true);
    }

    private function locationStatus(NormalizedOccurrence $item): string
    {
        // Backward compatibility for internal callers created before the explicit
        // privacy field existed: coordinates without a precision claim are public
        // but approximate, never exact.
        if ($item->locationStatus === 'unavailable' && $item->latitude !== null && $item->longitude !== null) {
            return 'approximate';
        }

        return $item->locationStatus;
    }

    private function publicUrl(mixed $value): ?string
    {
        if (! is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], true) ? $value : null;
    }

    private function hasGeography(NormalizedOccurrence $item): bool
    {
        return collect([
            $item->countryCode, $item->countryName, $item->regionName, $item->departmentCode,
            $item->departmentName, $item->municipalityCode, $item->municipalityName, $item->localityName,
        ])->contains(fn (?string $value): bool => $value !== null && $value !== '');
    }

    private function syncGeometry(Observation $observation): void
    {
        if (DB::getDriverName() === 'pgsql') {
            if ($observation->longitude === null || $observation->latitude === null) {
                DB::statement('UPDATE observations SET geometry = NULL WHERE id = ?', [$observation->id]);
            } else {
                DB::statement('UPDATE observations SET geometry = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ?',
                    [$observation->longitude, $observation->latitude, $observation->id]);
            }

            return;
        }
        $geometry = $observation->longitude === null || $observation->latitude === null
            ? null
            : json_encode(['type' => 'Point', 'coordinates' => [$observation->longitude, $observation->latitude]], JSON_THROW_ON_ERROR);
        DB::table('observations')->where('id', $observation->id)->update(['geometry' => $geometry]);
    }

    private function taxon(NormalizedOccurrence $item): ?Taxon
    {
        if ($item->sourceTaxonId !== null) {
            $mapping = TaxonSourceMapping::query()->where('source', TaxonSourceMapping::normalizeSource($item->source))
                ->where('source_taxon_id', $item->sourceTaxonId)->first();
            if ($mapping) {
                return $mapping->taxon;
            }
        }
        if (! $item->scientificName) {
            return null;
        }
        $rank = array_key_last($item->classification);
        $taxon = $this->canonicalTaxonMatcher->match($item->scientificName, $item->classification, $rank);
        if ($taxon === null) {
            $taxon = Taxon::query()->whereNull('taxref_version_id')->where('scientific_name', $item->scientificName)->firstOrCreate([
                'scientific_name' => $item->scientificName,
            ], [
                'vernacular_name' => $item->vernacularName,
                'rank' => $rank,
                'classification' => $item->classification,
                'taxonomic_status' => 'local_unresolved',
            ]);
        }
        if ($item->sourceTaxonId !== null) {
            TaxonSourceMapping::updateOrCreate([
                'source' => TaxonSourceMapping::normalizeSource($item->source),
                'source_taxon_id' => $item->sourceTaxonId,
            ], [
                'taxon_id' => $taxon->id,
                'source_scientific_name' => $item->scientificName,
                'source_rank' => $rank,
                'mapping_status' => 'candidate',
                'match_type' => $taxon->isCanonical() ? 'taxref_scientific_name' : 'exact_name',
                'confidence' => $taxon->isCanonical() ? .95 : .8,
                'is_preferred' => false,
                'raw_data' => [],
            ]);
        }

        return $taxon;
    }

    private function attach(Observation $observation, ?int $collectionId, ?int $monitoringRuleId): void
    {
        if ($collectionId && DataCollection::find($collectionId)) {
            $observation->collections()->syncWithoutDetaching([$collectionId => ['attached_at' => now()]]);
        }
        if ($monitoringRuleId && MonitoringRule::find($monitoringRuleId)) {
            $observation->monitoringRules()->syncWithoutDetaching([$monitoringRuleId => ['detected_at' => now()]]);
        }
    }

    private function recordCandidates(Observation $observation): void
    {
        if (! $observation->taxon_id || ! $observation->observed_at
            || $observation->latitude === null || $observation->longitude === null) {
            return;
        }
        $candidates = Observation::query()->where('id', '!=', $observation->id)->where('taxon_id', $observation->taxon_id)
            ->whereBetween('observed_at', [$observation->observed_at->subMinute(), $observation->observed_at->addMinute()])
            ->whereBetween('latitude', [$observation->latitude - .002, $observation->latitude + .002])
            ->whereBetween('longitude', [$observation->longitude - .002, $observation->longitude + .002])->limit(5)->get();
        foreach ($candidates as $candidate) {
            ObservationDeduplicationCandidate::firstOrCreate([
                'observation_id' => min($observation->id, $candidate->id),
                'candidate_observation_id' => max($observation->id, $candidate->id),
            ], [
                'score' => .75,
                'reasons' => ['same_taxon', 'time_within_one_minute', 'coordinates_within_approximately_200m'],
            ]);
        }
    }

    private function date(?string $value): ?CarbonImmutable
    {
        if (! $value) {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
