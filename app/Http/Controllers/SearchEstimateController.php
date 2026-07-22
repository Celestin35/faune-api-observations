<?php

namespace App\Http\Controllers;

use App\Services\Biodiversity\SearchDefinitionFactory;
use App\Services\Biodiversity\SearchEstimateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SearchEstimateController
{
    public function __invoke(Request $request, SearchDefinitionFactory $factory, SearchEstimateService $service): JsonResponse
    {
        return response()->json($service->estimate($factory->make($request->all())));
    }
}
