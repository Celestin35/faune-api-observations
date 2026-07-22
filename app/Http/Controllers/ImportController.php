<?php

namespace App\Http\Controllers;

use App\Models\ImportJob;
use App\Services\Biodiversity\ImportCoordinator;
use App\Services\Biodiversity\SearchDefinitionFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ImportController
{
    public function index(): JsonResponse
    {
        return response()->json(ImportJob::query()->latest()->paginate(25));
    }

    public function store(Request $request, SearchDefinitionFactory $factory, ImportCoordinator $coordinator): JsonResponse
    {
        $request->validate(['confirmed' => ['accepted'], 'data_collection_id' => ['nullable', 'exists:data_collections,id'],
            'estimates' => ['sometimes', 'array']]);
        $jobs = $coordinator->create($factory->make($request->all()), $request->integer('data_collection_id') ?: null,
            null, (array) $request->input('estimates', []));

        return response()->json(['data' => $jobs, 'message' => 'Import confirmé et placé dans la queue.'], 202);
    }

    public function show(ImportJob $import): JsonResponse
    {
        return response()->json(['data' => $import]);
    }

    public function cancel(ImportJob $import): JsonResponse
    {
        abort_unless($import->status === 'pending', 409, 'Seul un import non démarré peut être annulé.');
        $import->update(['status' => 'cancelled', 'finished_at' => now()]);

        return response()->json(['data' => $import]);
    }
}
