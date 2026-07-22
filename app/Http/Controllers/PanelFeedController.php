<?php

namespace App\Http\Controllers;

use App\Models\Observation;
use Illuminate\Http\JsonResponse;

final class PanelFeedController
{
    public function __invoke(): JsonResponse
    {
        $items = Observation::query()->whereHas('monitoringRules', fn ($q) => $q->where('is_active', true))
            ->with(['taxon:id,scientific_name,vernacular_name', 'sources:id,observation_id,source'])
            ->latest('last_seen_at')->limit(20)->get();

        return response()->json(['generated_at' => now(), 'data' => $items]);
    }
}
