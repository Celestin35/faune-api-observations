<?php

namespace App\Http\Controllers;

use App\Models\Taxon;
use App\Services\Biodiversity\TaxonSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TaxonController
{
    public function search(Request $request, TaxonSearchService $service): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:200'], 'limit' => ['sometimes', 'integer', 'between:1,20']]);

        return response()->json(['data' => $service->search($data['q'], (int) ($data['limit'] ?? 10))]);
    }

    public function show(Taxon $taxon, TaxonSearchService $service): JsonResponse
    {
        return response()->json(['data' => $service->one($taxon->load(['mappings', 'rankDefinition', 'referenceVersion']))]);
    }
}
