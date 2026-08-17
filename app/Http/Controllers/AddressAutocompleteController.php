<?php

namespace App\Http\Controllers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class AddressAutocompleteController
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:3', 'max:200'],
            'limit' => ['sometimes', 'integer', 'between:1,10'],
        ]);
        $query = trim($validated['q']);
        $limit = (int) ($validated['limit'] ?? 6);
        $cacheKey = 'geocoding:addresses:'.hash('sha256', mb_strtolower($query).'|'.$limit);

        try {
            $results = Cache::remember($cacheKey, now()->addDay(), function () use ($query, $limit): array {
                $response = Http::acceptJson()->timeout(5)->retry(2, 150, throw: false)->get(
                    (string) config('biodiversity.geocoding_autocomplete_url'),
                    [
                        'text' => $query,
                        'type' => 'StreetAddress,PositionOfInterest',
                        'maximumResponses' => $limit,
                    ],
                );
                $response->throw();

                return collect($response->json('results', []))
                    ->filter(fn (mixed $result): bool => is_array($result)
                        && is_numeric($result['x'] ?? null)
                        && is_numeric($result['y'] ?? null)
                        && is_string($result['fulltext'] ?? null))
                    ->take($limit)
                    ->map(fn (array $result): array => [
                        'label' => trim($result['fulltext']),
                        'latitude' => (float) $result['y'],
                        'longitude' => (float) $result['x'],
                        'kind' => is_string($result['kind'] ?? null) ? $result['kind'] : null,
                        'city' => is_string($result['city'] ?? null) ? $result['city'] : null,
                        'postcode' => is_string($result['zipcode'] ?? null) ? $result['zipcode'] : null,
                    ])->values()->all();
            });
        } catch (ConnectionException) {
            return response()->json(['message' => 'Le service officiel de recherche d’adresses est temporairement inaccessible.'], 503);
        } catch (\Throwable) {
            return response()->json(['message' => 'La recherche d’adresses n’a pas pu aboutir.'], 502);
        }

        return response()->json(['data' => $results]);
    }
}
