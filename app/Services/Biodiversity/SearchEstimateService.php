<?php

namespace App\Services\Biodiversity;

use App\Models\CollectionCoverage;
use App\Services\Biodiversity\Sources\GbifConnector;
use App\Services\Biodiversity\Sources\INaturalistConnector;

final class SearchEstimateService
{
    public function __construct(
        private SearchQueryFactory $queries,
        private LocalObservationQuery $local,
        private CoverageCalculator $coverage,
        private GbifConnector $gbif,
        private INaturalistConnector $inaturalist,
    ) {}

    /** @return array<string, mixed> */
    public function estimate(SearchDefinition $definition): array
    {
        $local = $this->local->results($definition);
        $external = [];
        foreach ($definition->sources as $source) {
            if ($source === 'faune-france') {
                $external[$source] = [
                    'available' => true,
                    'estimable' => false,
                    'count' => null,
                    'message' => 'Estimation indisponible pour Faune-France. Le nombre de résultats sera connu pendant la récupération.',
                ];

                continue;
            }
            try {
                $external[$source] = array_sum(array_map(
                    fn ($query): int => $source === 'gbif' ? $this->gbif->count($query) : $this->inaturalist->count($query),
                    $this->queries->forSource($definition, $source),
                ));
            } catch (\Throwable $exception) {
                report($exception);
                $external[$source] = ['error' => $exception->getMessage()];
            }
        }

        $inatInGbif = null;
        if (in_array('gbif', $definition->sources, true) && in_array('inaturalist', $definition->sources, true)) {
            try {
                $inatInGbif = array_sum(array_map(fn ($query): int => $this->gbif->countINaturalistDataset($query),
                    $this->queries->forSource($definition, 'gbif')));
            } catch (\Throwable $exception) {
                report($exception);
            }
        }
        $inatTotal = is_int($external['inaturalist'] ?? null) ? $external['inaturalist'] : null;
        $missing = [];
        foreach ($definition->sources as $source) {
            $ranges = CollectionCoverage::query()->where('taxon_id', $definition->taxon?->id)
                ->where('taxon_scope', $definition->taxonScope)
                ->where('taxonomic_reference_version_id', $definition->taxonomicReferenceVersionId)
                ->where('zone_hash', $definition->zoneHash())->where('source', $source)->where('status', 'completed')
                ->get()->map(fn (CollectionCoverage $item): array => [
                    'from' => $item->covered_from->toDateString(), 'to' => $item->covered_to->toDateString(),
                ])->all();
            $missing[$source] = $this->coverage->missing($definition->dateFrom, $definition->dateTo, $ranges);
        }

        return [
            'local' => ['count' => $local->count(), 'covered_from' => $local->min('observed_at')?->toDateString(),
                'covered_to' => $local->max('observed_at')?->toDateString()],
            'external' => $external,
            'overlap' => [
                'inaturalist_in_gbif' => $inatInGbif,
                'estimated_inaturalist_missing_from_gbif' => $inatTotal !== null && $inatInGbif !== null ? max(0, $inatTotal - $inatInGbif) : null,
                'is_approximation' => true,
            ],
            'coverage_complete' => collect($missing)->every(fn (array $ranges): bool => $ranges === []),
            'missing_periods' => $missing,
            'import_limit_per_source' => (int) config('biodiversity.import_limit_per_source'),
            'warning' => 'Les compteurs externes sont indicatifs et la déduplication finale a lieu pendant la persistance.',
        ];
    }
}
