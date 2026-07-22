<?php

namespace App\Http\Controllers;

use App\Models\GeographicArea;
use Illuminate\Http\JsonResponse;

final class GeographicAreaController
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => GeographicArea::query()
            ->select(['id', 'type', 'code', 'name', 'region_name', 'is_overseas', 'faune_portal'])
            ->orderBy('code')->get()]);
    }
}
