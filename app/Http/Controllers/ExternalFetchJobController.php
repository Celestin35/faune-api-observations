<?php

namespace App\Http\Controllers;

use App\Models\ExternalFetchJob;
use App\Models\ImportJob;
use App\Models\MonitoringRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ExternalFetchJobController
{
    public function next(): JsonResponse
    {
        ExternalFetchJob::releaseStale();
        $job = ExternalFetchJob::query()
            ->where('source', 'faune-france')
            ->where('status', ExternalFetchJob::STATUS_PENDING)
            ->orderBy('id')
            ->first();

        return response()->json(['job' => $job?->botPayload()]);
    }

    public function claim(ExternalFetchJob $job): JsonResponse
    {
        $claimed = ExternalFetchJob::query()
            ->whereKey($job->getKey())
            ->where('source', 'faune-france')
            ->where('status', ExternalFetchJob::STATUS_PENDING)
            ->update([
                'status' => ExternalFetchJob::STATUS_CLAIMED,
                'claimed_at' => now(),
                'heartbeat_at' => now(),
                'error_message' => null,
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            return response()->json(['message' => 'Cette tâche n’est plus disponible.'], 409);
        }
        $this->startImport($job->fresh());

        return response()->json(['job' => $job->fresh()->botPayload()]);
    }

    public function complete(Request $request, ExternalFetchJob $job): JsonResponse
    {
        $validated = $request->validate(['partial' => ['sometimes', 'boolean']]);
        $completed = ExternalFetchJob::query()
            ->whereKey($job->getKey())
            ->where('source', 'faune-france')
            ->whereIn('status', [ExternalFetchJob::STATUS_CLAIMED, ExternalFetchJob::STATUS_RUNNING])
            ->update([
                'status' => ExternalFetchJob::STATUS_COMPLETED,
                'completed_at' => now(),
                'heartbeat_at' => now(),
                'updated_at' => now(),
            ]);

        if ($completed !== 1) {
            return response()->json(['message' => 'Cette tâche ne peut pas être terminée dans son état actuel.'], 409);
        }
        $this->finishImport($job->fresh(), ($validated['partial'] ?? false) ? 'partial' : 'completed');
        $this->completeMonitoring($job->fresh(), null);

        return response()->json(['status' => ExternalFetchJob::STATUS_COMPLETED]);
    }

    public function fail(Request $request, ExternalFetchJob $job): JsonResponse
    {
        $validated = $request->validate([
            'errorMessage' => ['required', 'string', 'max:10000'],
        ]);
        $failed = ExternalFetchJob::query()
            ->whereKey($job->getKey())
            ->where('source', 'faune-france')
            ->whereIn('status', [ExternalFetchJob::STATUS_CLAIMED, ExternalFetchJob::STATUS_RUNNING])
            ->update([
                'status' => ExternalFetchJob::STATUS_FAILED,
                'failed_at' => now(),
                'heartbeat_at' => now(),
                'error_message' => $validated['errorMessage'],
                'updated_at' => now(),
            ]);

        if ($failed !== 1) {
            return response()->json(['message' => 'Cette tâche ne peut pas être mise en échec dans son état actuel.'], 409);
        }
        $import = $job->fresh()->importJob;
        if ($import !== null) {
            $this->finishImport($job->fresh(), $import->processed_count > 0 ? 'partial' : 'failed', $validated['errorMessage']);
        }
        $this->completeMonitoring($job->fresh(), $validated['errorMessage']);

        return response()->json(['status' => ExternalFetchJob::STATUS_FAILED]);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'jobId' => ['nullable', 'integer', 'min:1'],
            'progress' => ['sometimes', 'array'],
            'progress.stage' => ['required_with:progress', 'string', 'in:fetching,saving'],
            'progress.current' => ['required_with:progress', 'integer', 'min:0'],
            'progress.total' => ['nullable', 'integer', 'min:0'],
            'progress.message' => ['nullable', 'string', 'max:255'],
        ]);

        if (isset($validated['jobId'])) {
            DB::transaction(function () use ($validated): void {
                $job = ExternalFetchJob::query()->lockForUpdate()->find($validated['jobId']);
                if (! $job || $job->source !== 'faune-france' || ! in_array($job->status, [ExternalFetchJob::STATUS_CLAIMED, ExternalFetchJob::STATUS_RUNNING], true)) {
                    abort(409, 'La tâche ne peut pas recevoir de heartbeat.');
                }
                $job->update([
                    'status' => ExternalFetchJob::STATUS_RUNNING,
                    'started_at' => $job->started_at ?? now(),
                    'heartbeat_at' => now(),
                ]);
                if (isset($validated['progress']) && $job->import_job_id !== null) {
                    $progress = $validated['progress'];
                    ImportJob::query()->whereKey($job->import_job_id)->update([
                        'progress_stage' => $progress['stage'],
                        'progress_current' => $progress['current'],
                        'progress_total' => $progress['total'] ?? null,
                        'progress_message' => $progress['message'] ?? null,
                    ]);
                }
            });
        }

        return response()->json(['status' => 'ok', 'serverTime' => now()->toIso8601String()]);
    }

    private function completeMonitoring(ExternalFetchJob $job, ?string $error): void
    {
        if (! $job->monitoring_rule_id) {
            return;
        }
        $rule = MonitoringRule::find($job->monitoring_rule_id);
        if (! $rule) {
            return;
        }
        $rule->update([
            ...($error === null ? ['last_synced_at' => now()] : []),
            'next_sync_at' => now()->addMinutes($rule->frequency_minutes),
            'last_error' => $error,
        ]);
    }

    private function startImport(ExternalFetchJob $job): void
    {
        if ($job->import_job_id === null) {
            return;
        }
        ImportJob::query()->whereKey($job->import_job_id)->where('status', 'pending')->update([
            'status' => 'running',
            'progress_stage' => 'fetching',
            'progress_current' => 0,
            'progress_total' => (int) ($job->payload['maxPages'] ?? 0) ?: null,
            'progress_message' => 'Préparation de la recherche Faune-France.',
            'started_at' => now(),
            'error_message' => null,
            'updated_at' => now(),
        ]);
    }

    private function finishImport(ExternalFetchJob $job, string $status, ?string $error = null): void
    {
        if ($job->import_job_id === null) {
            return;
        }
        $import = ImportJob::find($job->import_job_id);
        if ($import === null) {
            return;
        }
        $import->update([
            'status' => $status,
            'estimated_count' => $status === 'completed' ? $import->processed_count : null,
            'progress_stage' => 'finished',
            'progress_current' => $import->processed_count,
            'progress_total' => $status === 'completed' ? $import->processed_count : null,
            'progress_message' => match ($status) {
                'partial' => 'Limite de sécurité atteinte : des résultats supplémentaires peuvent exister.',
                'failed' => 'L’import a échoué.',
                default => 'Import terminé.',
            },
            'error_message' => $error,
            'finished_at' => now(),
        ]);
    }
}
