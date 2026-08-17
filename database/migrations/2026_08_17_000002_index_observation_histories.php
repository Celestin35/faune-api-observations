<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitoring_rule_observations', function (Blueprint $table): void {
            $table->index('detected_at', 'monitoring_history_detected_at_index');
            $table->index('observation_id', 'monitoring_history_observation_index');
        });

        Schema::table('collection_observations', function (Blueprint $table): void {
            $table->index('observation_id', 'collection_history_observation_index');
        });
    }

    public function down(): void
    {
        Schema::table('monitoring_rule_observations', function (Blueprint $table): void {
            $table->dropIndex('monitoring_history_detected_at_index');
            $table->dropIndex('monitoring_history_observation_index');
        });

        Schema::table('collection_observations', function (Blueprint $table): void {
            $table->dropIndex('collection_history_observation_index');
        });
    }
};
