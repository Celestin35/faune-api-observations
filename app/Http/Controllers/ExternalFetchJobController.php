<?php

namespace App\Http\Controllers;

use App\Models\ExternalFetchJob;
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

        return response()->json(['job' => $job->fresh()->botPayload()]);
    }

    public function complete(ExternalFetchJob $job): JsonResponse
    {
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
        $this->completeMonitoring($job->fresh(), $validated['errorMessage']);

        return response()->json(['status' => ExternalFetchJob::STATUS_FAILED]);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'jobId' => ['nullable', 'integer', 'min:1'],
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
            'last_synced_at' => now(),
            'next_sync_at' => now()->addMinutes($rule->frequency_minutes),
            'last_error' => $error,
        ]);
    }
}
