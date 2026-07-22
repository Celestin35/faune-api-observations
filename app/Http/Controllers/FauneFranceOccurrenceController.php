<?php

namespace App\Http\Controllers;

use App\Services\Biodiversity\Inbound\FauneFranceInboundConnector;
use App\Services\Biodiversity\OccurrencePersister;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class FauneFranceOccurrenceController
{
    public function __invoke(
        Request $request,
        FauneFranceInboundConnector $connector,
        OccurrencePersister $persister,
    ): JsonResponse {
        $expected = (string) config('biodiversity.faune_france_token');
        $provided = (string) ($request->bearerToken() ?: $request->header('X-Faune-France-Token'));
        if ($expected === '' || $provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Jeton Faune-France invalide.'], 401);
        }

        $payload = $request->json()->all();
        $batch = array_is_list($payload) ? $payload : [$payload];
        $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0];
        $errors = [];
        foreach ($batch as $index => $record) {
            $validator = Validator::make(is_array($record) ? $record : [], $this->rules());
            if ($validator->fails()) {
                if (! array_is_list($payload)) {
                    throw new ValidationException($validator);
                }
                $counts['failed']++;
                $errors[$index] = $validator->errors();

                continue;
            }
            try {
                $result = $persister->persist($connector->normalizeInbound($validator->validated()));
                $counts[$result->status]++;
            } catch (\Throwable $exception) {
                report($exception);
                $counts['failed']++;
                $errors[$index] = ['persistence' => [$exception->getMessage()]];
            }
        }

        return response()->json([
            'status' => $counts['failed'] > 0 ? 'partially_accepted' : 'accepted',
            'counts' => $counts,
            'errors' => (object) $errors,
        ], 202);
    }

    /** @return array<string, list<string>> */
    private function rules(): array
    {
        return [
            'source_occurrence_id' => ['required', 'string', 'max:512'], 'source_dataset_id' => ['nullable', 'string', 'max:512'],
            'scientific_name' => ['nullable', 'string', 'max:512'], 'vernacular_name' => ['nullable', 'string', 'max:512'],
            'source_taxon_id' => ['nullable', 'string', 'max:512'], 'classification' => ['sometimes', 'array'],
            'observed_at' => ['nullable', 'date'], 'source_created_at' => ['nullable', 'date'],
            'source_updated_at' => ['nullable', 'date'], 'published_at' => ['nullable', 'date'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'coordinate_uncertainty_m' => ['nullable', 'numeric', 'min:0'], 'individual_count' => ['nullable', 'integer', 'min:0'],
            'validation_status' => ['nullable', 'string', 'max:255'], 'observer_name' => ['nullable', 'string', 'max:512'],
            'license' => ['nullable', 'string', 'max:512'], 'source_url' => ['nullable', 'url:http,https', 'max:2048'],
            'media' => ['sometimes', 'array', 'max:50'], 'raw_data' => ['sometimes', 'array'],
        ];
    }
}
