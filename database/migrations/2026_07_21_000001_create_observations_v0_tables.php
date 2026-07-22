<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
        }

        Schema::create('taxa', function (Blueprint $table): void {
            $table->id();
            $table->string('scientific_name')->unique();
            $table->string('vernacular_name')->nullable();
            $table->string('rank')->nullable();
            $table->json('classification')->nullable();
            $table->timestamps();
        });

        Schema::create('taxon_source_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('taxon_id')->constrained()->cascadeOnDelete();
            $table->string('source', 40);
            $table->string('source_taxon_id', 255);
            $table->json('raw_data')->nullable();
            $table->timestamps();
            $table->unique(['source', 'source_taxon_id']);
            $table->unique(['taxon_id', 'source']);
        });

        Schema::create('geographic_areas', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 40);
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->json('geometry_geojson')->nullable();
            $table->string('gadm_gid')->nullable()->index();
            $table->unsignedBigInteger('inaturalist_place_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('taxon_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTimeTz('observed_at')->nullable()->index();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->text('geometry')->nullable(); // Replaced by a PostGIS geometry below.
            $table->decimal('coordinate_uncertainty_m', 12, 2)->nullable();
            $table->unsignedInteger('individual_count')->nullable();
            $table->string('validation_status')->nullable()->index();
            $table->string('observer_name')->nullable();
            $table->timestampTz('first_imported_at')->index();
            $table->timestampTz('last_seen_at')->index();
            $table->timestampTz('retain_until')->nullable()->index();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE observations DROP COLUMN geometry');
            DB::statement('ALTER TABLE observations ADD COLUMN geometry geometry(Point, 4326)');
            DB::statement('CREATE INDEX observations_geometry_gist ON observations USING GIST (geometry)');
        }

        Schema::create('observation_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('observation_id')->constrained()->cascadeOnDelete();
            $table->string('source', 40);
            $table->string('source_occurrence_id', 512);
            $table->string('source_dataset_id', 512)->nullable();
            $table->string('source_taxon_id', 255)->nullable();
            $table->string('origin_key', 768)->nullable()->index();
            $table->string('source_url', 2048)->nullable();
            $table->string('license')->nullable();
            $table->timestampTz('source_created_at')->nullable();
            $table->timestampTz('source_updated_at')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->json('canonical_identifiers')->nullable();
            $table->json('raw_data');
            $table->timestamps();
            $table->unique(['source', 'source_occurrence_id']);
        });

        Schema::create('observation_deduplication_candidates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('observation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('candidate_observation_id')->constrained('observations')->cascadeOnDelete();
            $table->decimal('score', 5, 4)->nullable();
            $table->json('reasons');
            $table->string('status', 30)->default('pending');
            $table->timestamps();
            $table->unique(['observation_id', 'candidate_observation_id']);
        });

        Schema::create('data_collections', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->foreignId('taxon_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();
            $table->string('zone_type', 30);
            $table->json('zone_data');
            $table->string('zone_hash', 64)->index();
            $table->json('sources');
            $table->boolean('is_permanent')->default(false)->index();
            $table->timestamps();
        });

        Schema::create('collection_coverages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('data_collection_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('taxon_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('geographic_area_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 40);
            $table->string('zone_hash', 64)->index();
            $table->date('covered_from');
            $table->date('covered_to');
            $table->unsignedBigInteger('observation_count')->default(0);
            $table->string('status', 30)->default('completed')->index();
            $table->timestampTz('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('monitoring_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->foreignId('taxon_id')->nullable()->constrained()->nullOnDelete();
            $table->string('zone_type', 30);
            $table->json('zone_data');
            $table->string('zone_hash', 64)->index();
            $table->json('sources');
            $table->unsignedInteger('window_minutes')->default(1440);
            $table->unsignedInteger('frequency_minutes');
            $table->boolean('is_active')->default(true)->index();
            $table->timestampTz('last_synced_at')->nullable();
            $table->timestampTz('next_sync_at')->nullable()->index();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('collection_observations', function (Blueprint $table): void {
            $table->foreignId('data_collection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('observation_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('attached_at')->useCurrent();
            $table->primary(['data_collection_id', 'observation_id']);
        });

        Schema::create('monitoring_rule_observations', function (Blueprint $table): void {
            $table->foreignId('monitoring_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('observation_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('detected_at')->useCurrent();
            $table->primary(['monitoring_rule_id', 'observation_id']);
        });

        Schema::create('import_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 40)->index();
            $table->foreignId('taxon_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('geographic_area_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('data_collection_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('monitoring_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date_from');
            $table->date('date_to');
            $table->string('zone_type', 30);
            $table->json('zone_data');
            $table->string('zone_hash', 64)->index();
            $table->string('status', 30)->default('pending')->index();
            $table->unsignedInteger('limit');
            $table->unsignedBigInteger('estimated_count')->nullable();
            $table->unsignedBigInteger('processed_count')->default(0);
            $table->unsignedBigInteger('created_count')->default(0);
            $table->unsignedBigInteger('updated_count')->default(0);
            $table->unsignedBigInteger('unchanged_count')->default(0);
            $table->unsignedBigInteger('failed_count')->default(0);
            $table->text('error_message')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('source_sync_states', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 40);
            $table->string('scope_key', 255);
            $table->string('cursor', 512)->nullable();
            $table->unsignedInteger('page')->nullable();
            $table->json('state')->nullable();
            $table->timestampTz('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['source', 'scope_key']);
        });

        Schema::create('jobs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        Schema::create('job_batches', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });
        Schema::create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach (['failed_jobs', 'job_batches', 'jobs', 'source_sync_states', 'import_jobs',
            'monitoring_rule_observations', 'collection_observations', 'monitoring_rules',
            'collection_coverages', 'data_collections', 'observation_deduplication_candidates',
            'observation_sources', 'observations', 'geographic_areas', 'taxon_source_mappings', 'taxa'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
