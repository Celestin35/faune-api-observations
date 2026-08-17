<?php

namespace App\Http\Controllers;

use App\Models\ExternalFetchJob;
use App\Models\ImportJob;
use App\Services\Biodiversity\ImportCoordinator;
use App\Services\Biodiversity\SearchDefinitionFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ImportController
{
    public function index(): JsonResponse
    {
        return response()->json(ImportJob::query()->with([
            'taxon:id,scientific_name,vernacular_name,preferred_french_name',
            'externalFetchJob:id,import_job_id,status',
        ])->latest()->paginate(25));
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
        DB::transaction(function () use ($import): void {
            $locked = ImportJob::query()->lockForUpdate()->findOrFail($import->id);
            abort_unless($locked->status === 'pending', 409, 'Seul un import non démarré peut être annulé.');
            $external = ExternalFetchJob::query()->where('import_job_id', $locked->id)->lockForUpdate()->first();
            if ($external !== null) {
                abort_unless($external->status === ExternalFetchJob::STATUS_PENDING, 409,
                    'La tâche Faune-France est déjà réservée ; elle ne peut plus être annulée immédiatement.');
                $external->update([
                    'status' => ExternalFetchJob::STATUS_CANCELLED,
                    'failed_at' => now(),
                    'error_message' => 'Annulé par l’utilisateur avant réservation.',
                ]);
            }
            $locked->update(['status' => 'cancelled', 'finished_at' => now()]);
        });

        return response()->json(['data' => $import->fresh()]);
    }
}
