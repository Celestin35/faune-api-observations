<?php

namespace App\Http\Controllers;

use App\Http\Resources\ObservationHistoryResource;
use App\Http\Resources\ObservationMapResource;
use App\Models\DataCollection;
use App\Models\MonitoringRule;
use App\Models\Observation;
use App\Services\Biodiversity\TaxonMapGroupResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ScopedObservationController
{
    public function __construct(private TaxonMapGroupResolver $taxonGroups) {}

    public function collection(Request $request, DataCollection $collection): JsonResponse
    {
        return $this->paginate($request, $this->collectionQuery($collection));
    }

    public function collectionMap(DataCollection $collection): JsonResponse
    {
        return $this->map($this->collectionQuery($collection));
    }

    public function monitoring(Request $request, MonitoringRule $monitoring): JsonResponse
    {
        return $this->paginate($request, $this->monitoringQuery($monitoring));
    }

    public function monitoringMap(MonitoringRule $monitoring): JsonResponse
    {
        return $this->map($this->monitoringQuery($monitoring));
    }

    private function collectionQuery(DataCollection $collection): Builder
    {
        return Observation::query()
            ->whereHas('collections', fn (Builder $query) => $query->whereKey($collection->id))
            ->addSelect(['history_at' => DB::table('collection_observations')
                ->select('attached_at')
                ->whereColumn('observation_id', 'observations.id')
                ->where('data_collection_id', $collection->id)
                ->limit(1)]);
    }

    private function monitoringQuery(MonitoringRule $monitoring): Builder
    {
        $cutoff = now()->subMonths((int) config('biodiversity.monitoring_history_months', 2));

        return Observation::query()
            ->whereHas('monitoringRules', fn (Builder $query) => $query
                ->whereKey($monitoring->id)
                ->where('monitoring_rule_observations.detected_at', '>=', $cutoff))
            ->addSelect(['history_at' => DB::table('monitoring_rule_observations')
                ->select('detected_at')
                ->whereColumn('observation_id', 'observations.id')
                ->where('monitoring_rule_id', $monitoring->id)
                ->where('detected_at', '>=', $cutoff)
                ->limit(1)]);
    }

    private function paginate(Request $request, Builder $query): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,200'],
        ]);
        $perPage = (int) ($validated['per_page'] ?? 50);

        return ObservationHistoryResource::collection(
            $query->with([
                'taxon',
                'sources' => fn ($sourceQuery) => $sourceQuery->select(['id', 'observation_id', 'source']),
            ])->orderByDesc('history_at')->paginate($perPage),
        )->response();
    }

    private function map(Builder $query): JsonResponse
    {
        $maximum = (int) config('biodiversity.map_observation_limit', 30000);
        $observations = $query->with([
            'taxon',
            'sources' => fn ($sourceQuery) => $sourceQuery->select(['id', 'observation_id', 'source']),
        ])->whereNotNull('latitude')->whereNotNull('longitude')
            ->orderByDesc('history_at')->limit($maximum + 1)->get();
        $truncated = $observations->count() > $maximum;
        $observations = $observations->take($maximum);
        $this->taxonGroups->apply($observations);

        return response()->json([
            'data' => ObservationMapResource::collection($observations),
            'meta' => [
                'returned' => $observations->count(),
                'truncated' => $truncated,
                'limit' => $maximum,
            ],
        ]);
    }
}
