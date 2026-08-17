<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_fetch_jobs', function (Blueprint $table): void {
            $table->foreignId('import_job_id')->nullable()->unique()->after('monitoring_rule_id')
                ->constrained('import_jobs')->nullOnDelete();
        });

        Schema::table('observations', function (Blueprint $table): void {
            $table->string('temporal_precision', 20)->default('unknown')->after('observed_at');
            $table->string('location_status', 30)->default('unavailable')->after('coordinate_uncertainty_m')->index();
            $table->string('country_code', 2)->nullable();
            $table->string('country_name')->nullable();
            $table->string('region_name')->nullable();
            $table->string('department_code', 20)->nullable()->index();
            $table->string('department_name')->nullable();
            $table->string('municipality_code', 20)->nullable()->index();
            $table->string('municipality_name')->nullable();
            $table->string('locality_name')->nullable();
            $table->string('geography_resolution_method', 20)->default('none');
            $table->timestampTz('geography_resolved_at')->nullable();
            $table->string('life_stage')->nullable();
            $table->string('sex')->nullable();
            $table->string('behavior')->nullable();
            $table->index(['taxon_id', 'observed_at']);
        });

        Schema::table('observation_sources', function (Blueprint $table): void {
            $table->string('source_scientific_name', 512)->nullable();
            $table->string('source_vernacular_name', 512)->nullable();
            $table->timestampTz('source_observed_at')->nullable();
            $table->string('source_temporal_precision', 20)->default('unknown');
            $table->decimal('public_latitude', 10, 7)->nullable();
            $table->decimal('public_longitude', 10, 7)->nullable();
            $table->decimal('coordinate_uncertainty_m', 12, 2)->nullable();
            $table->string('location_status', 30)->default('unavailable')->index();
            $table->string('source_location_precision')->nullable();
            $table->string('source_location_name')->nullable();
            $table->string('source_country_code', 2)->nullable();
            $table->string('source_country_name')->nullable();
            $table->string('source_region_name')->nullable();
            $table->string('source_department_code', 20)->nullable();
            $table->string('source_department_name')->nullable();
            $table->string('source_municipality_code', 20)->nullable();
            $table->string('source_municipality_name')->nullable();
            $table->string('source_observer_name', 512)->nullable();
            $table->boolean('observer_is_public')->default(false);
            $table->unsignedInteger('source_individual_count')->nullable();
            $table->string('source_validation_status')->nullable();
            $table->string('life_stage')->nullable();
            $table->string('sex')->nullable();
            $table->string('behavior')->nullable();
            $table->text('remarks')->nullable();
        });

        Schema::create('observation_source_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('observation_source_id')->constrained()->cascadeOnDelete();
            $table->string('media_type', 40)->nullable();
            $table->string('url', 2048);
            $table->string('thumbnail_url', 2048)->nullable();
            $table->string('source_page_url', 2048)->nullable();
            $table->string('license', 512)->nullable();
            $table->string('attribution', 1024)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['observation_source_id', 'url']);
        });

        DB::table('observations')->whereNotNull('observed_at')->update(['temporal_precision' => 'unknown']);
        DB::table('observations')->whereNull('latitude')->orWhereNull('longitude')->update(['location_status' => 'unavailable']);
        DB::table('observations')->whereNotNull('latitude')->whereNotNull('longitude')->update(['location_status' => 'approximate']);

        $this->backfillSources();
    }

    public function down(): void
    {
        Schema::dropIfExists('observation_source_media');

        Schema::table('observation_sources', function (Blueprint $table): void {
            $table->dropColumn([
                'source_scientific_name', 'source_vernacular_name', 'source_observed_at', 'source_temporal_precision',
                'public_latitude', 'public_longitude', 'coordinate_uncertainty_m', 'location_status',
                'source_location_precision', 'source_location_name', 'source_country_code', 'source_country_name',
                'source_region_name', 'source_department_code', 'source_department_name', 'source_municipality_code',
                'source_municipality_name', 'source_observer_name', 'observer_is_public', 'source_individual_count',
                'source_validation_status', 'life_stage', 'sex', 'behavior', 'remarks',
            ]);
        });

        Schema::table('observations', function (Blueprint $table): void {
            $table->dropIndex(['taxon_id', 'observed_at']);
            $table->dropColumn([
                'temporal_precision', 'location_status', 'country_code', 'country_name', 'region_name',
                'department_code', 'department_name', 'municipality_code', 'municipality_name', 'locality_name',
                'geography_resolution_method', 'geography_resolved_at', 'life_stage', 'sex', 'behavior',
            ]);
        });

        Schema::table('external_fetch_jobs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('import_job_id');
        });
    }

    private function backfillSources(): void
    {
        DB::table('observation_sources')->orderBy('id')->chunkById(200, function ($sources): void {
            foreach ($sources as $source) {
                $raw = is_array($source->raw_data)
                    ? $source->raw_data
                    : json_decode((string) $source->raw_data, true);
                if (! is_array($raw)) {
                    continue;
                }
                $values = $this->historicalValues((string) $source->source, $raw);
                DB::table('observation_sources')->where('id', $source->id)->update($values);

                $canonical = array_filter([
                    'country_code' => $values['source_country_code'] ?? null,
                    'country_name' => $values['source_country_name'] ?? null,
                    'region_name' => $values['source_region_name'] ?? null,
                    'department_code' => $values['source_department_code'] ?? null,
                    'department_name' => $values['source_department_name'] ?? null,
                    'municipality_code' => $values['source_municipality_code'] ?? null,
                    'municipality_name' => $values['source_municipality_name'] ?? null,
                    'locality_name' => $values['source_location_name'] ?? null,
                    'geography_resolution_method' => 'source',
                    'geography_resolved_at' => now(),
                ], fn (mixed $value): bool => $value !== null);
                if (count($canonical) > 2) {
                    DB::table('observations')->where('id', $source->observation_id)->update($canonical);
                }
            }
        });
    }

    /** @return array<string, mixed> */
    private function historicalValues(string $source, array $raw): array
    {
        $latitude = $longitude = $uncertainty = null;
        $status = 'unavailable';
        $precision = null;
        $location = null;
        $values = [
            'source_scientific_name' => null,
            'source_vernacular_name' => null,
            'source_temporal_precision' => 'unknown',
            'location_status' => 'unavailable',
            'observer_is_public' => false,
        ];

        if ($source === 'gbif') {
            $observedAt = $this->date($raw['eventDate'] ?? null);
            $latitude = $this->number($raw['decimalLatitude'] ?? null);
            $longitude = $this->number($raw['decimalLongitude'] ?? null);
            $uncertainty = $this->number($raw['coordinateUncertaintyInMeters'] ?? null);
            $masked = ! empty($raw['informationWithheld']) || ! empty($raw['dataGeneralizations']);
            $status = $this->status($latitude, $longitude, $masked, $uncertainty === 0);
            $values = array_replace($values, [
                'source_scientific_name' => $this->text($raw['scientificName'] ?? null),
                'source_vernacular_name' => $this->text($raw['vernacularName'] ?? null),
                'source_observed_at' => $observedAt,
                'source_temporal_precision' => $this->temporalPrecision($raw['eventDate'] ?? null, $observedAt),
                'source_location_name' => $this->text($raw['locality'] ?? $raw['verbatimLocality'] ?? null),
                'source_country_code' => $this->code($raw['countryCode'] ?? null, 2),
                'source_country_name' => $this->text($raw['country'] ?? null),
                'source_region_name' => $this->text($raw['stateProvince'] ?? null),
                'source_department_name' => $this->text($raw['county'] ?? null),
                'source_municipality_name' => $this->text($raw['municipality'] ?? null),
                'source_observer_name' => $this->text(is_array($raw['recordedBy'] ?? null) ? implode(', ', $raw['recordedBy']) : ($raw['recordedBy'] ?? null)),
                'observer_is_public' => isset($raw['recordedBy']),
                'source_individual_count' => $this->integer($raw['individualCount'] ?? null),
                'source_validation_status' => $this->text($raw['identificationVerificationStatus'] ?? $raw['occurrenceStatus'] ?? null),
                'life_stage' => $this->text($raw['lifeStage'] ?? null),
                'sex' => $this->text($raw['sex'] ?? null),
                'behavior' => $this->text($raw['behavior'] ?? null),
                'remarks' => $this->text($raw['occurrenceRemarks'] ?? null),
            ]);
        } elseif ($source === 'inaturalist') {
            $rawObservedAt = $raw['time_observed_at'] ?? $raw['observed_on'] ?? null;
            $observedAt = $this->date($rawObservedAt);
            $coordinates = $raw['geojson']['coordinates'] ?? null;
            $longitude = is_array($coordinates) ? $this->number($coordinates[0] ?? null) : null;
            $latitude = is_array($coordinates) ? $this->number($coordinates[1] ?? null) : null;
            $uncertainty = $this->number($raw['public_positional_accuracy'] ?? $raw['positional_accuracy'] ?? null);
            $privacy = strtolower((string) ($raw['geoprivacy'] ?? ''));
            $masked = in_array($privacy, ['obscured', 'private'], true);
            $status = $this->status($latitude, $longitude, $masked, $privacy === 'open' && $uncertainty === 0);
            $values = array_replace($values, [
                'source_scientific_name' => $this->text($raw['taxon']['name'] ?? null),
                'source_vernacular_name' => $this->text($raw['taxon']['preferred_common_name'] ?? $raw['species_guess'] ?? null),
                'source_observed_at' => $observedAt,
                'source_temporal_precision' => $this->temporalPrecision($rawObservedAt, $observedAt),
                'source_location_name' => $this->text($raw['place_guess'] ?? null),
                'source_observer_name' => $this->text($raw['user']['login'] ?? $raw['user']['name'] ?? null),
                'observer_is_public' => isset($raw['user']['login']) || isset($raw['user']['name']),
                'source_validation_status' => $this->text($raw['quality_grade'] ?? null),
            ]);
            $precision = $privacy !== '' ? $privacy : null;
        } elseif ($source === 'faune-france') {
            $info = $this->fauneObserverInfo($raw);
            $rawObservedAt = $raw['date_raw'] ?? $raw['date'] ?? null;
            $observedAt = $this->date($rawObservedAt, 'Europe/Paris');
            $latitude = $this->number($info['lat'] ?? $raw['lat'] ?? null);
            $longitude = $this->number($info['lon'] ?? $raw['lon'] ?? null);
            $precision = $this->text($info['precision'] ?? $raw['precision'] ?? null);
            $masked = $this->truthy($raw['is_hidden'] ?? null) || $this->truthy($raw['is_admin_hidden'] ?? null);
            $status = $this->status($latitude, $longitude, $masked, strtolower((string) $precision) === 'precise');
            if ($masked) {
                // Faune-France's hidden coordinates are internal source data, not a public
                // obscured point. They must never be copied into the public columns.
                $latitude = null;
                $longitude = null;
            }
            $location = $this->clean($raw['listSubmenu']['title'] ?? $raw['location'] ?? null);
            $values = array_replace($values, [
                'source_scientific_name' => $this->text($raw['species_array']['latin_name'] ?? null),
                'source_vernacular_name' => $this->text($raw['species_array']['name'] ?? null),
                'source_observed_at' => $observedAt,
                'source_temporal_precision' => $this->temporalPrecision($rawObservedAt, $observedAt),
                'source_location_name' => $location,
                'source_individual_count' => $this->integer($info['count'] ?? $raw['birds_count'] ?? null),
                'remarks' => $this->fauneRemarks($raw['remarks'] ?? null),
            ]);
        }

        return array_replace($values, [
            'public_latitude' => $latitude,
            'public_longitude' => $longitude,
            'coordinate_uncertainty_m' => $uncertainty,
            'location_status' => $status,
            'source_location_precision' => $precision,
        ]);
    }

    private function status(?float $latitude, ?float $longitude, bool $masked, bool $explicitlyPrecise): string
    {
        if ($latitude === null || $longitude === null) {
            return 'unavailable';
        }
        if ($masked) {
            return 'source_masked';
        }

        return $explicitlyPrecise ? 'exact' : 'approximate';
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function integer(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value >= 0 ? (int) $value : null;
    }

    private function date(mixed $value, string $timezone = 'UTC'): ?CarbonImmutable
    {
        $value = $this->text($value);
        if ($value === null) {
            return null;
        }
        try {
            return CarbonImmutable::parse($value, $timezone)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function temporalPrecision(mixed $raw, ?CarbonImmutable $parsed): string
    {
        if ($parsed === null) {
            return 'unknown';
        }

        return is_string($raw) && preg_match('/T\\d{2}:\\d{2}/', $raw) === 1 ? 'datetime' : 'date';
    }

    private function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function code(mixed $value, int $length): ?string
    {
        $value = strtoupper((string) ($this->text($value) ?? ''));

        return strlen($value) === $length ? $value : null;
    }

    private function truthy(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true'], true);
    }

    /** @return array<string, mixed> */
    private function fauneObserverInfo(array $raw): array
    {
        foreach (($raw['opt_observers'] ?? []) as $observer) {
            $info = is_array($observer) ? ($observer['opt_observer_info'][0] ?? null) : null;
            if (is_array($info)) {
                return $info;
            }
        }

        return [];
    }

    private function clean(mixed $value): ?string
    {
        $value = $this->text($value);
        $value = $value === null ? null : trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');

        return $value !== '' ? $value : null;
    }

    private function fauneRemarks(mixed $value): ?string
    {
        if (! is_array($value)) {
            return $this->clean($value);
        }
        $parts = [];
        foreach ($value as $remark) {
            if (is_array($remark) && ($part = $this->clean($remark['content'] ?? $remark['title'] ?? null)) !== null) {
                $parts[] = $part;
            }
        }

        return $parts !== [] ? implode("\n", $parts) : null;
    }
};
