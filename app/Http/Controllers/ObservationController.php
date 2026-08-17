<?php

namespace App\Http\Controllers;

use App\Http\Resources\ObservationDetailResource;
use App\Http\Resources\ObservationListResource;
use App\Models\Observation;
use App\Models\Taxon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ObservationController
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['taxon_id' => ['nullable', 'integer'], 'taxon_scope' => ['nullable', 'in:exact,subtree'], 'source' => ['nullable', 'in:gbif,inaturalist,faune-france'],
            'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date'], 'validation_status' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'between:1,1000'], 'per_page' => ['nullable', 'integer', 'between:1,500'],
            'page' => ['nullable', 'integer', 'min:1']]);
        $query = Observation::query()->with(['taxon', 'sources.media']);
        $query->when($request->integer('taxon_id'), function (Builder $q, int $id) use ($request): void {
            $taxon = Taxon::query()->find($id);
            if ($request->string('taxon_scope')->toString() === 'subtree' && $taxon?->taxref_version_id !== null) {
                $q->whereIn('taxon_id', \DB::table('taxon_paths')->where('taxonomic_reference_version_id', $taxon->taxref_version_id)
                    ->where('ancestor_taxon_id', $taxon->id)->select('descendant_taxon_id'));
            } else {
                $q->where('taxon_id', $id);
            }
        });
        $query->when($request->input('date_from'), fn (Builder $q, string $date) => $q->whereDate('observed_at', '>=', $date));
        $query->when($request->input('date_to'), fn (Builder $q, string $date) => $q->whereDate('observed_at', '<=', $date));
        $query->when($request->input('validation_status'), fn (Builder $q, string $status) => $q->where('validation_status', $status));
        $query->when($request->input('source'), fn (Builder $q, string $source) => $q->whereHas('sources', fn (Builder $sq) => $sq->where('source', $source)));

        $perPage = $request->integer('per_page', $request->integer('limit', 100));

        return ObservationListResource::collection($query->latest('observed_at')->paginate($perPage))->response();
    }

    public function show(Observation $observation): JsonResponse
    {
        $observation->load([
            'taxon.rankDefinition',
            'taxon.ancestorPaths.ancestor',
            'sources.media',
        ]);

        return (new ObservationDetailResource($observation))->response();
    }
}
