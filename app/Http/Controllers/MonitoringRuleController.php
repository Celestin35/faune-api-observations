<?php

namespace App\Http\Controllers;

use App\Models\MonitoringRule;
use App\Models\Observation;
use App\Services\Biodiversity\MonitoringSynchronizer;
use App\Services\Biodiversity\MonitoringTaxonSelectionValidator;
use App\Services\Biodiversity\SearchDefinitionFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MonitoringRuleController
{
    public function index(): JsonResponse
    {
        $cutoff = now()->subMonths((int) config('biodiversity.monitoring_history_months', 2));

        return response()->json(MonitoringRule::with(['taxon', 'taxa'])
            ->withCount(['observations' => fn ($query) => $query
                ->where('monitoring_rule_observations.detected_at', '>=', $cutoff)])
            ->latest()->paginate(25));
    }

    public function store(
        Request $request,
        SearchDefinitionFactory $factory,
        MonitoringTaxonSelectionValidator $selectionValidator,
    ): JsonResponse {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'window_minutes' => ['required', 'integer', 'min:5'],
            'frequency_minutes' => ['required', 'integer', 'min:5'],
            'is_active' => ['sometimes', 'boolean'],
            'taxon_id' => ['required_without:taxa', 'nullable', 'integer', 'exists:taxa,id'],
            'taxon_scope' => ['sometimes', 'string', 'in:exact,subtree'],
            'taxa' => ['required_without:taxon_id', 'array', 'min:1'],
            'taxa.*.taxon_id' => ['required', 'integer', 'distinct', 'exists:taxa,id'],
            'taxa.*.taxon_scope' => ['required', 'string', 'in:exact,subtree'],
        ]);
        $selections = $request->has('taxa')
            ? array_values((array) $request->input('taxa'))
            : [[
                'taxon_id' => $request->integer('taxon_id'),
                'taxon_scope' => (string) $request->input('taxon_scope', 'exact'),
            ]];
        $criteria = array_map(function (array $selection) use ($factory, $request) {
            return $factory->slidingCriteria([
                ...$request->all(),
                'taxon_id' => $selection['taxon_id'],
                'taxon_scope' => $selection['taxon_scope'],
            ]);
        }, $selections);
        $selectionValidator->validate($criteria);

        $first = $criteria[0];
        $minimum = array_intersect(['gbif', 'faune-france'], $first->sources) ? 30 : 5;
        if ($request->integer('frequency_minutes') < $minimum) {
            return response()->json(['message' => "La fréquence minimale est de {$minimum} minutes pour ces sources."], 422);
        }

        $rule = DB::transaction(function () use ($criteria, $first, $request): MonitoringRule {
            $rule = MonitoringRule::create([
                'name' => $request->string('name'), 'taxon_id' => $first->taxon->id,
                'zone_type' => $first->zone['type'], 'zone_data' => $first->zone,
                'zone_hash' => $first->resolve()->zoneHash(),
                'sources' => $first->sources, 'window_minutes' => $first->windowMinutes,
                'frequency_minutes' => $request->integer('frequency_minutes'), 'is_active' => $request->boolean('is_active', true),
                'taxonomic_reference_version_id' => $first->taxonomicReferenceVersionId,
                'taxon_scope' => $first->taxonScope, 'taxon_label_snapshot' => $first->taxonLabelSnapshot,
                'next_sync_at' => now(),
            ]);
            foreach ($criteria as $position => $selection) {
                $rule->taxa()->attach($selection->taxon->id, [
                    'taxon_scope' => $selection->taxonScope,
                    'taxonomic_reference_version_id' => $selection->taxonomicReferenceVersionId,
                    'taxon_label_snapshot' => $selection->taxonLabelSnapshot,
                    'position' => $position,
                ]);
            }

            return $rule;
        });

        return response()->json(['data' => $rule->load('taxa')], 201);
    }

    public function show(MonitoringRule $monitoring): JsonResponse
    {
        $cutoff = now()->subMonths((int) config('biodiversity.monitoring_history_months', 2));

        return response()->json(['data' => $monitoring->load(['taxon', 'taxa'])
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
        abort_if($monitoring->hasSynchronizationInProgress(), 409,
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
        abort_if($monitoring->hasSynchronizationInProgress(), 409, 'Une synchronisation de cette surveillance est déjà en cours.');

        return response()->json(['data' => $synchronizer->sync($monitoring)], 202);
    }
}
