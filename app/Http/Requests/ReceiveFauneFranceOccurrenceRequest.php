<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ReceiveFauneFranceOccurrenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'source_occurrence_id' => ['required', 'string', 'max:512'],
            'source_dataset_id' => ['nullable', 'string', 'max:512'],
            'scientific_name' => ['nullable', 'string', 'max:512'],
            'vernacular_name' => ['nullable', 'string', 'max:512'],
            'source_taxon_id' => ['nullable', 'string', 'max:512'],
            'classification' => ['sometimes', 'array'],
            'observed_at' => ['nullable', 'date'],
            'source_created_at' => ['nullable', 'date'],
            'source_updated_at' => ['nullable', 'date'],
            'published_at' => ['nullable', 'date'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'coordinate_uncertainty_m' => ['nullable', 'numeric', 'min:0'],
            'individual_count' => ['nullable', 'integer', 'min:0'],
            'validation_status' => ['nullable', 'string', 'max:255'],
            'observer_name' => ['nullable', 'string', 'max:512'],
            'license' => ['nullable', 'string', 'max:512'],
            'source_url' => ['nullable', 'url:http,https', 'max:2048'],
            'media' => ['sometimes', 'array', 'max:50'],
            'media.*.url' => ['nullable', 'url:http,https', 'max:2048'],
            'raw_data' => ['sometimes', 'array'],
        ];
    }
}
