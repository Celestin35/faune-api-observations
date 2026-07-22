<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('observations', function (Blueprint $table): void {
            $table->string('location_name')->nullable()->after('observer_name');
            $table->text('remarks')->nullable()->after('location_name');
        });

        Schema::create('external_fetch_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 40)->index();
            $table->string('status', 20)->default('pending')->index();
            $table->jsonb('payload');
            $table->timestampTz('claimed_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('heartbeat_at')->nullable()->index();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('external_fetch_job_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('external_fetch_job_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('batch_number');
            $table->boolean('is_last_batch');
            $table->unsignedInteger('observation_count');
            $table->string('payload_hash', 64);
            $table->json('counts');
            $table->timestamps();
            $table->unique(['external_fetch_job_id', 'batch_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_fetch_job_batches');
        Schema::dropIfExists('external_fetch_jobs');
        Schema::table('observations', function (Blueprint $table): void {
            $table->dropColumn(['location_name', 'remarks']);
        });
    }
};
