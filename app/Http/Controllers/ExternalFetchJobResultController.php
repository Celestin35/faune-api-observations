<?php

namespace App\Http\Controllers;

use App\Models\ExternalFetchJob;
use App\Models\ExternalFetchJobBatch;
use App\Services\Biodiversity\Inbound\FauneFranceRawObservationNormalizer;
use App\Services\Biodiversity\OccurrencePersister;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ExternalFetchJobResultController
{
    public function __invoke(
        Request $request,
        ExternalFetchJob $job,
        FauneFranceRawObservationNormalizer $normalizer,
        OccurrencePersister $persister,
    ): JsonResponse {
        $validated = $request->validate([
            'batchNumber' => ['required', 'integer', 'min:1'],
            'isLastBatch' => ['required', 'boolean'],
            'observations' => ['required', 'array', 'max:100'],
            'observations.*' => ['array'],
        ]);
        $hash = hash('sha256', json_encode($validated, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($job, $validated, $hash, $normalizer, $persister): JsonResponse {
            $lockedJob = ExternalFetchJob::query()->lockForUpdate()->findOrFail($job->getKey());
            if ($lockedJob->source !== 'faune-france' || ! in_array($lockedJob->status, [ExternalFetchJob::STATUS_CLAIMED, ExternalFetchJob::STATUS_RUNNING], true)) {
                return response()->json(['message' => 'La tâche n’accepte plus de résultats.'], 409);
            }

            $existing = ExternalFetchJobBatch::query()
                ->where('external_fetch_job_id', $lockedJob->getKey())
                ->where('batch_number', $validated['batchNumber'])
                ->first();
            if ($existing) {
                if (! hash_equals($existing->payload_hash, $hash)) {
                    return response()->json(['message' => 'Ce numéro de lot existe déjà avec un contenu différent.'], 409);
                }

                return response()->json(['counts' => $existing->counts, 'replayed' => true]);
            }

            $taxon = $lockedJob->payload['taxon'] ?? null;
            if (! is_array($taxon)) {
                throw ValidationException::withMessages(['job.payload.taxon' => 'Le taxon de la tâche est invalide.']);
            }
            $normalized = [];
            foreach ($validated['observations'] as $index => $observation) {
                try {
                    $normalized[] = $normalizer->normalize($observation, $taxon);
                } catch (\Throwable $exception) {
                    throw ValidationException::withMessages(["observations.{$index}" => $exception->getMessage()]);
                }
            }

            $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
            foreach ($normalized as $occurrence) {
                $outcome = $persister->persist($occurrence, null, $lockedJob->monitoring_rule_id);
                $counts[$outcome->status]++;
            }
            ExternalFetchJobBatch::create([
                'external_fetch_job_id' => $lockedJob->getKey(),
                'batch_number' => $validated['batchNumber'],
                'is_last_batch' => $validated['isLastBatch'],
                'observation_count' => count($validated['observations']),
                'payload_hash' => $hash,
                'counts' => $counts,
            ]);
            $lockedJob->update([
                'status' => ExternalFetchJob::STATUS_RUNNING,
                'started_at' => $lockedJob->started_at ?? now(),
                'heartbeat_at' => now(),
            ]);

            return response()->json(['counts' => $counts, 'replayed' => false]);
        });
    }
}
