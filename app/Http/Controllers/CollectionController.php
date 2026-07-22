<?php

namespace App\Http\Controllers;

use App\Models\DataCollection;
use App\Services\Biodiversity\SearchDefinitionFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CollectionController
{
    public function index(): JsonResponse
    {
        return response()->json(DataCollection::withCount('observations')->latest()->paginate(25));
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
        ]);

        return response()->json(['data' => $collection], 201);
    }

    public function show(DataCollection $collection): JsonResponse
    {
        return response()->json(['data' => $collection->load(['taxon', 'coverages'])->loadCount('observations')]);
    }
}
