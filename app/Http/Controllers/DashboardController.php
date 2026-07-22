<?php

namespace App\Http\Controllers;

use App\Models\ImportJob;
use App\Models\MonitoringRule;
use App\Models\Observation;
use Illuminate\Http\JsonResponse;

final class DashboardController
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['observations' => Observation::count(), 'active_monitoring' => MonitoringRule::where('is_active', true)->count(),
            'running_imports' => ImportJob::whereIn('status', ['pending', 'running'])->count(),
            'sources' => Observation::query()->join('observation_sources', 'observations.id', '=', 'observation_sources.observation_id')
                ->selectRaw('observation_sources.source, count(distinct observations.id) as count')->groupBy('observation_sources.source')->pluck('count', 'source')]);
    }
}
