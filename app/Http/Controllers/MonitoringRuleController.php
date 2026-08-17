<?php

namespace App\Http\Controllers;

use App\Models\MonitoringRule;
use App\Models\Observation;
use App\Services\Biodiversity\MonitoringSynchronizer;
use App\Services\Biodiversity\SearchDefinitionFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MonitoringRuleController
{
    public function index(): JsonResponse
    {
        $cutoff = now()->subMonths((int) config('biodiversity.monitoring_history_months', 2));

        return response()->json(MonitoringRule::with('taxon')
            ->withCount(['observations' => fn ($query) => $query
                ->where('monitoring_rule_observations.detected_at', '>=', $cutoff)])
            ->latest()->paginate(25));
    }

    public function store(Request $request, SearchDefinitionFactory $factory): JsonResponse
    {
        $request->validate(['name' => ['required', 'string', 'max:255'], 'window_minutes' => ['required', 'integer', 'min:5'],
            'frequency_minutes' => ['required', 'integer', 'min:5'], 'is_active' => ['sometimes', 'boolean']]);
        $criteria = $factory->slidingCriteria($request->all());
        if (in_array('faune-france', $criteria->sources, true)
            && ($criteria->taxon === null
                || ($criteria->taxon->rank_code ?: $criteria->taxon->rank) !== 'species'
                || $criteria->taxonScope !== 'exact')) {
            throw ValidationException::withMessages([
                'sources' => 'Les recherches Faune-France par groupe ou tous les animaux sont disponibles dans Explorer, pas encore dans les surveillances.',
            ]);
        }
        $minimum = array_intersect(['gbif', 'faune-france'], $criteria->sources) ? 30 : 5;
        if ($request->integer('frequency_minutes') < $minimum) {
            return response()->json(['message' => "La fréquence minimale est de {$minimum} minutes pour ces sources."], 422);
        }
        $rule = MonitoringRule::create([
            'name' => $request->string('name'), 'taxon_id' => $criteria->taxon?->id,
            'zone_type' => $criteria->zone['type'], 'zone_data' => $criteria->zone,
            'zone_hash' => $criteria->resolve()->zoneHash(),
            'sources' => $criteria->sources, 'window_minutes' => $criteria->windowMinutes,
            'frequency_minutes' => $request->integer('frequency_minutes'), 'is_active' => $request->boolean('is_active', true),
            'taxonomic_reference_version_id' => $criteria->taxonomicReferenceVersionId,
            'taxon_scope' => $criteria->taxonScope, 'taxon_label_snapshot' => $criteria->taxonLabelSnapshot,
            'next_sync_at' => now(),
        ]);

        return response()->json(['data' => $rule], 201);
    }

    public function show(MonitoringRule $monitoring): JsonResponse
    {
        $cutoff = now()->subMonths((int) config('biodiversity.monitoring_history_months', 2));

        return response()->json(['data' => $monitoring->load('taxon')
            ->loadCount(['observations' => fn ($query) => $query
                ->where('monitoring_rule_observations.detected_at', '>=', $cutoff)])]);
    }

    public function update(Request $request, MonitoringRule $monitoring): JsonResponse
    {
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:255'], 'is_active' => ['sometimes', 'boolean'],
            'frequency_minutes' => ['sometimes', 'integer', 'min:5'], 'window_minutes' => ['sometimes', 'integer', 'min:5']]);
        $minimum = array_intersect(['gbif', 'faune-france'], $monitoring->sources) ? 30 : 5;
        if (isset($data['frequency_minutes']) && $data['frequency_minutes'] < $minimum) {
            return response()->json(['message' => "La fréquence minimale est de {$minimum} minutes."], 422);
        }
        $monitoring->update($data);

        return response()->json(['data' => $monitoring]);
    }

    public function destroy(MonitoringRule $monitoring): JsonResponse
    {
        abort_if($monitoring->imports()->whereIn('status', ['pending', 'running'])->exists()
            || $monitoring->externalFetchJobs()->whereIn('status', ['pending', 'claimed', 'running'])->exists(), 409,
            'Cette surveillance possède encore une synchronisation en cours. Attendez sa fin avant de la supprimer.');

        DB::transaction(function () use ($monitoring): void {
            $observationIds = $monitoring->observations()->pluck('observations.id');
            $monitoring->delete();
            $observationIds->chunk(500)->each(function ($ids): void {
                Observation::query()->whereKey($ids)
                    ->whereDoesntHave('collections')
                    ->whereDoesntHave('monitoringRules')
                    ->delete();
            });
        });

        return response()->json([], 204);
    }

    public function sync(MonitoringRule $monitoring, MonitoringSynchronizer $synchronizer): JsonResponse
    {
        return response()->json(['data' => $synchronizer->sync($monitoring)], 202);
    }
}
