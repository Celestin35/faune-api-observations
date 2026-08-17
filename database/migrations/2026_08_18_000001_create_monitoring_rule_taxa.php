<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_rule_taxa', function (Blueprint $table): void {
            $table->foreignId('monitoring_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('taxon_id')->constrained()->cascadeOnDelete();
            $table->string('taxon_scope', 20)->default('exact');
            $table->foreignId('taxonomic_reference_version_id')->nullable()
                ->constrained('taxonomic_reference_versions')->nullOnDelete();
            $table->string('taxon_label_snapshot', 512)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->primary(['monitoring_rule_id', 'taxon_id']);
            $table->unique(['monitoring_rule_id', 'position']);
        });

        $now = now();
        DB::table('monitoring_rules')->whereNotNull('taxon_id')->orderBy('id')
            ->get(['id', 'taxon_id', 'taxon_scope', 'taxonomic_reference_version_id', 'taxon_label_snapshot'])
            ->each(fn (object $rule) => DB::table('monitoring_rule_taxa')->insert([
                'monitoring_rule_id' => $rule->id,
                'taxon_id' => $rule->taxon_id,
                'taxon_scope' => $rule->taxon_scope ?: 'exact',
                'taxonomic_reference_version_id' => $rule->taxonomic_reference_version_id,
                'taxon_label_snapshot' => $rule->taxon_label_snapshot,
                'position' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_rule_taxa');
    }
};
