<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('observations', function (Blueprint $table): void {
            $table->decimal('elevation_m', 9, 2)->nullable()->after('coordinate_uncertainty_m');
            $table->string('elevation_source', 100)->nullable()->after('elevation_m');
            $table->timestampTz('elevation_resolved_at')->nullable()->after('elevation_source');
            $table->timestampTz('geography_enrichment_attempted_at')->nullable()->after('geography_resolved_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('observations', function (Blueprint $table): void {
            $table->dropIndex(['geography_enrichment_attempted_at']);
            $table->dropColumn([
                'elevation_m', 'elevation_source', 'elevation_resolved_at',
                'geography_enrichment_attempted_at',
            ]);
        });
    }
};
