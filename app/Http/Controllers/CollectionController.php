<?php

namespace App\Http\Controllers;

use App\Models\DataCollection;
use App\Models\Observation;
use App\Services\Biodiversity\SearchDefinitionFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CollectionController
{
    public function index(): JsonResponse
    {
        return response()->json(DataCollection::query()
            ->with(['taxon', 'imports' => fn ($query) => $query->latest()])
            ->withCount('observations')->latest()->paginate(25));
    }

    public function store(Request $request, SearchDefinitionFactory $factory): JsonResponse
    {
        $request->validate(['name' => ['required', 'string', 'max:255'], 'is_permanent' => ['sometimes', 'boolean']]);
        $definition = $factory->make($request->all());
        $collection = DataCollection::create([
            'name' => $request->string('name'), 'taxon_id' => $definition->taxon?->id,
            'date_from' => $definition->dateFrom, 'date_to' => $definition->dateTo,
            'zone_type' => $definition->zoneType(), 'zone_data' => $definition->zone,
            'zone_hash' => $definition->zoneHash(), 'sources' => $definition->sources,
            'is_permanent' => $request->boolean('is_permanent'),
            'taxonomic_reference_version_id' => $definition->taxonomicReferenceVersionId,
            'taxon_scope' => $definition->taxonScope, 'taxon_label_snapshot' => $definition->taxonLabel(),
        ]);

        return response()->json(['data' => $collection], 201);
    }

    public function show(DataCollection $collection): JsonResponse
    {
        return response()->json(['data' => $collection
            ->load(['taxon', 'coverages', 'imports' => fn ($query) => $query->latest()])
            ->loadCount('observations')]);
    }

    public function destroy(DataCollection $collection): JsonResponse
    {
        abort_if($collection->imports()->whereIn('status', ['pending', 'running'])->exists(), 409,
            'Cette recherche possède encore un import en cours. Attendez sa fin ou annulez-le avant de la supprimer.');

        DB::transaction(function () use ($collection): void {
            $observationIds = $collection->observations()->pluck('observations.id');
            $collection->delete();
            $observationIds->chunk(500)->each(function ($ids): void {
                Observation::query()->whereKey($ids)
                    ->whereDoesntHave('collections')
                    ->whereDoesntHave('monitoringRules')
                    ->delete();
            });
        });

        return response()->json([], 204);
    }
}
