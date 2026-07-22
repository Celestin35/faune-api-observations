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
    public function __construct(private DeduplicationHints $hints, private TaxonNameNormalizer $nameNormalizer) {}

    public function persist(NormalizedOccurrence $item, ?int $collectionId = null, ?int $monitoringRuleId = null): PersistOutcome
    {
        return DB::transaction(function () use ($item, $collectionId, $monitoringRuleId): PersistOutcome {
            $existing = ObservationSource::query()->where('source', $item->source)
                ->where('source_occurrence_id', $item->sourceOccurrenceId)->first();
            $identifiers = $this->hints->for($item);
            $originKey = $this->hints->primaryFor($item);

            if ($existing) {
                $before = hash('sha256', json_encode($existing->raw_data, JSON_THROW_ON_ERROR));
                $existing->fill($this->sourceAttributes($item, $identifiers, $originKey))->save();
                $observation = $existing->observation;
                $observation->update($this->observationAttributes($item) + ['last_seen_at' => now()]);
                $this->attach($observation, $collectionId, $monitoringRuleId);
                $after = hash('sha256', json_encode($item->rawData, JSON_THROW_ON_ERROR));

                return new PersistOutcome($observation->fresh(['taxon', 'sources']), $before === $after ? 'unchanged' : 'updated');
            }

            $matchingSource = ObservationSource::query()->where('origin_key', $originKey)->first();
            $taxon = $this->taxon($item);
            $observation = $matchingSource?->observation;
            $created = false;
            if (! $observation) {
                $created = true;
                $observation = Observation::create($this->observationAttributes($item, $taxon?->id) + [
                    'first_imported_at' => now(), 'last_seen_at' => now(),
                    'geometry' => DB::getDriverName() === 'sqlite' && $item->longitude !== null
                        ? json_encode(['type' => 'Point', 'coordinates' => [$item->longitude, $item->latitude]], JSON_THROW_ON_ERROR) : null,
                ]);
                if (DB::getDriverName() === 'pgsql' && $item->longitude !== null && $item->latitude !== null) {
                    DB::statement('UPDATE observations SET geometry = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ?',
                        [$item->longitude, $item->latitude, $observation->id]);
                }
            }

            ObservationSource::create(['observation_id' => $observation->id]
                + $this->sourceAttributes($item, $identifiers, $originKey));
            $this->attach($observation, $collectionId, $monitoringRuleId);
            if ($created) {
                $this->recordCandidates($observation);
            }

            return new PersistOutcome($observation->fresh(['taxon', 'sources']), $created ? 'created' : 'updated');
        });
    }

    /** @return array<string, mixed> */
    private function observationAttributes(NormalizedOccurrence $item, ?int $taxonId = null): array
    {
        $attributes = [
            'observed_at' => $this->date($item->observedAt),
            'latitude' => $item->latitude,
            'longitude' => $item->longitude,
            'coordinate_uncertainty_m' => $item->coordinateUncertaintyM,
            'individual_count' => $item->individualCount,
            'validation_status' => $item->validationStatus,
            'observer_name' => $item->observerName,
            'location_name' => $item->locationName,
            'remarks' => $item->remarks,
        ];
        if ($taxonId !== null) {
            $attributes['taxon_id'] = $taxonId;
        }

        return $attributes;
    }

    /** @return array<string, mixed> */
    private function sourceAttributes(NormalizedOccurrence $item, array $identifiers, string $originKey): array
    {
        return [
            'source' => $item->source, 'source_occurrence_id' => $item->sourceOccurrenceId,
            'source_dataset_id' => $item->sourceDatasetId, 'source_taxon_id' => $item->sourceTaxonId,
            'origin_key' => $originKey, 'source_url' => $item->sourceUrl, 'license' => $item->license,
            'source_created_at' => $this->date($item->sourceCreatedAt),
            'source_updated_at' => $this->date($item->sourceUpdatedAt),
            'published_at' => $this->date($item->publishedAt),
            'canonical_identifiers' => $identifiers, 'raw_data' => $item->rawData,
        ];
    }

    private function taxon(NormalizedOccurrence $item): ?Taxon
    {
        if ($item->sourceTaxonId !== null) {
            $mappingSource = TaxonSourceMapping::normalizeSource($item->source);
            $mapping = TaxonSourceMapping::query()->where('source', $mappingSource)
                ->where('source_taxon_id', $item->sourceTaxonId)->first();
            if ($mapping) {
                return $mapping->taxon;
            }
        }
        if (! $item->scientificName) {
            return null;
        }
        $rank = array_key_last($item->classification);
        $normalized = $this->nameNormalizer->normalize($item->scientificName);
        $candidates = Taxon::query()->whereIn('id', \DB::table('taxon_names')
            ->where('normalized_name', $normalized)->select('taxon_id'))->limit(10)->get();
        if ($rank !== null) {
            $ranked = $candidates->filter(fn (Taxon $candidate): bool => ($candidate->rank_code ?? $candidate->rank) === $rank);
            if ($ranked->isNotEmpty()) {
                $candidates = $ranked;
            }
        }
        $taxon = $candidates->count() === 1 ? $candidates->first() : null;
        if ($taxon === null && $candidates->isEmpty()) {
            $taxon = Taxon::query()->whereNull('taxref_version_id')->where('scientific_name', $item->scientificName)->firstOrCreate([
                'scientific_name' => $item->scientificName,
            ], [
                'vernacular_name' => $item->vernacularName, 'rank' => $rank,
                'classification' => $item->classification, 'taxonomic_status' => 'local_unresolved',
            ]);
        }
        if ($taxon === null) {
            return null;
        }
        if ($item->sourceTaxonId !== null) {
            TaxonSourceMapping::updateOrCreate(['source' => TaxonSourceMapping::normalizeSource($item->source), 'source_taxon_id' => $item->sourceTaxonId],
                [
                    'taxon_id' => $taxon->id, 'source_scientific_name' => $item->scientificName,
                    'source_rank' => $rank, 'mapping_status' => 'candidate', 'match_type' => 'exact_name',
                    'confidence' => .8, 'is_preferred' => false, 'raw_data' => [],
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
        if (! $observation->taxon_id || ! $observation->observed_at || $observation->latitude === null) {
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
            ], ['score' => .75, 'reasons' => ['same_taxon', 'time_within_one_minute', 'coordinates_within_approximately_200m']]);
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
