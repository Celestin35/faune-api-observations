<?php

namespace App\Http\Controllers;

use App\Models\MonitoringRule;
use App\Services\Biodiversity\MonitoringSynchronizer;
use App\Services\Biodiversity\SearchDefinitionFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MonitoringRuleController
{
    public function index(): JsonResponse
    {
        return response()->json(MonitoringRule::with('taxon')->latest()->paginate(25));
    }

    public function store(Request $request, SearchDefinitionFactory $factory): JsonResponse
    {
        $request->validate(['name' => ['required', 'string', 'max:255'], 'window_minutes' => ['required', 'integer', 'min:5'],
            'frequency_minutes' => ['required', 'integer', 'min:5'], 'is_active' => ['sometimes', 'boolean']]);
        $definition = $factory->make($request->all(), allowFauneFrance: true);
        $minimum = array_intersect(['gbif', 'faune-france'], $definition->sources) ? 30 : 5;
        if ($request->integer('frequency_minutes') < $minimum) {
            return response()->json(['message' => "La fréquence minimale est de {$minimum} minutes pour ces sources."], 422);
        }
        $rule = MonitoringRule::create([
            'name' => $request->string('name'), 'taxon_id' => $definition->taxon?->id,
            'zone_type' => $definition->zoneType(), 'zone_data' => $definition->zone, 'zone_hash' => $definition->zoneHash(),
            'sources' => $definition->sources, 'window_minutes' => $request->integer('window_minutes'),
            'frequency_minutes' => $request->integer('frequency_minutes'), 'is_active' => $request->boolean('is_active', true),
            'taxonomic_reference_version_id' => $definition->taxonomicReferenceVersionId,
            'taxon_scope' => $definition->taxonScope, 'taxon_label_snapshot' => $definition->taxonLabel(),
            'next_sync_at' => now(),
        ]);

        return response()->json(['data' => $rule], 201);
    }

    public function show(MonitoringRule $monitoring): JsonResponse
    {
        return response()->json(['data' => $monitoring->load('taxon')->loadCount('observations')]);
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
        $monitoring->delete();

        return response()->json([], 204);
    }

    public function sync(MonitoringRule $monitoring, MonitoringSynchronizer $synchronizer): JsonResponse
    {
        return response()->json(['data' => $synchronizer->sync($monitoring)], 202);
    }
}
