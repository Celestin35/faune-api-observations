<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taxonomic_reference_versions', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 40);
            $table->string('version', 80);
            $table->date('published_on')->nullable();
            $table->text('source_uri')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->enum('status', ['staging', 'active', 'archived', 'failed'])->default('staging')->index();
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('imported_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'version']);
        });
        DB::statement("CREATE UNIQUE INDEX taxonomic_reference_versions_one_active_per_provider ON taxonomic_reference_versions (provider) WHERE status = 'active'");

        Schema::create('taxon_ranks', function (Blueprint $table): void {
            $table->string('code', 30)->primary();
            $table->string('label_fr', 80);
            $table->unsignedSmallInteger('sort_order')->unique();
            $table->boolean('selectable')->default(false)->index();
            $table->jsonb('taxref_rank_codes');
            $table->timestamps();
        });

        Schema::table('taxa', function (Blueprint $table): void {
            $table->foreignId('taxref_version_id')->nullable()->after('id')
                ->constrained('taxonomic_reference_versions')->nullOnDelete();
            $table->unsignedBigInteger('taxref_cd_ref')->nullable()->after('taxref_version_id');
            $table->string('rank_code', 30)->nullable()->after('rank');
            $table->foreign('rank_code')->references('code')->on('taxon_ranks')->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->after('rank_code')->constrained('taxa')->nullOnDelete();
            $table->string('accepted_scientific_name', 512)->nullable()->after('scientific_name');
            $table->string('authorship', 512)->nullable()->after('accepted_scientific_name');
            $table->string('preferred_french_name', 512)->nullable()->after('vernacular_name');
            $table->enum('status', ['active', 'retired', 'merged'])->default('active')->after('classification')->index();
            $table->foreignId('merged_into_taxon_id')->nullable()->after('status')->constrained('taxa')->nullOnDelete();
            $table->unique(['taxref_version_id', 'taxref_cd_ref']);
            $table->index(['parent_id', 'rank_code']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE taxa ADD CONSTRAINT taxa_cannot_merge_into_itself CHECK (merged_into_taxon_id IS NULL OR merged_into_taxon_id <> id)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE taxa DROP CONSTRAINT IF EXISTS taxa_cannot_merge_into_itself');
        }

        Schema::table('taxa', function (Blueprint $table): void {
            $table->dropUnique(['taxref_version_id', 'taxref_cd_ref']);
            $table->dropIndex(['parent_id', 'rank_code']);
            $table->dropIndex(['status']);
            $table->dropForeign(['taxref_version_id']);
            $table->dropForeign(['rank_code']);
            $table->dropForeign(['parent_id']);
            $table->dropForeign(['merged_into_taxon_id']);
            $table->dropColumn([
                'taxref_version_id', 'taxref_cd_ref', 'rank_code', 'parent_id',
                'accepted_scientific_name', 'authorship', 'preferred_french_name',
                'status', 'merged_into_taxon_id',
            ]);
        });

        Schema::dropIfExists('taxon_ranks');
        Schema::dropIfExists('taxonomic_reference_versions');
    }
};
