<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('geographic_areas', function (Blueprint $table): void {
            $table->string('region_name')->nullable()->after('name');
            $table->boolean('is_overseas')->default(false)->after('region_name')->index();
            $table->string('faune_portal', 40)->default('faune_france')->after('is_overseas')->index();
        });

        Schema::table('external_fetch_jobs', function (Blueprint $table): void {
            $table->foreignId('monitoring_rule_id')->nullable()->after('id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('external_fetch_jobs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('monitoring_rule_id');
        });

        Schema::table('geographic_areas', function (Blueprint $table): void {
            $table->dropColumn(['region_name', 'is_overseas', 'faune_portal']);
        });
    }
};
