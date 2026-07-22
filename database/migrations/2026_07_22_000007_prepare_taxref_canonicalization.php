<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('taxa', function (Blueprint $table): void {
            $table->dropUnique('taxa_scientific_name_unique');
            $table->index('scientific_name', 'taxa_scientific_name_index');
            $table->string('taxonomic_status', 40)->default('local_unresolved')->index();
        });

        Schema::table('taxon_source_mappings', function (Blueprint $table): void {
            $table->dropUnique('taxon_source_mappings_taxon_id_source_unique');
            $table->string('source_accepted_taxon_id', 255)->nullable();
            $table->string('source_scientific_name', 512)->nullable();
            $table->string('source_rank', 80)->nullable();
            $table->string('source_reference_version', 120)->nullable();
            $table->string('mapping_status', 30)->default('validated')->index();
            $table->string('match_type', 30)->default('legacy');
            $table->decimal('confidence', 5, 4)->nullable();
            $table->boolean('is_preferred')->default(true)->index();
            $table->timestampTz('valid_from')->nullable();
            $table->timestampTz('valid_to')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
        });
        DB::table('taxon_source_mappings')->where('source', 'faune-france')->update(['source' => 'faune_france']);
        DB::table('taxon_source_mappings')->update([
            'mapping_status' => 'validated',
            'match_type' => 'legacy',
            'confidence' => 1,
            'is_preferred' => true,
        ]);

        foreach (['monitoring_rules', 'data_collections', 'collection_coverages', 'import_jobs'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('taxonomic_reference_version_id')->nullable()
                    ->constrained('taxonomic_reference_versions')->nullOnDelete();
                $table->string('taxon_scope', 20)->default('exact')->index();
                $table->string('taxon_label_snapshot', 512)->nullable();
            });
            DB::table($tableName)->whereNotNull('taxon_id')->update([
                'taxon_scope' => 'exact',
                'taxon_label_snapshot' => DB::raw('(SELECT scientific_name FROM taxa WHERE taxa.id = '.$tableName.'.taxon_id)'),
            ]);
        }

        Schema::table('external_fetch_jobs', function (Blueprint $table): void {
            $table->foreignId('taxon_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('taxon_source_mapping_id')->nullable()->constrained()->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE taxa DROP CONSTRAINT IF EXISTS taxa_taxref_version_id_taxref_cd_ref_unique');
            DB::statement('CREATE UNIQUE INDEX taxa_taxref_identity_unique ON taxa (taxref_version_id, taxref_cd_ref) WHERE taxref_version_id IS NOT NULL AND taxref_cd_ref IS NOT NULL');
            DB::statement("CREATE UNIQUE INDEX taxref_records_one_accepted_concept ON taxref_records (taxonomic_reference_version_id, cd_ref) WHERE name_status = 'accepted'");
            DB::statement('CREATE UNIQUE INDEX taxon_names_concept_name_unique ON taxon_names (taxon_id, taxonomic_reference_version_id, name_type, normalized_name)');
            DB::statement("CREATE UNIQUE INDEX taxon_names_one_preferred ON taxon_names (taxon_id, name_type, coalesce(language_code, '')) WHERE is_preferred");
            DB::statement("CREATE UNIQUE INDEX mappings_one_preferred_validated ON taxon_source_mappings (taxon_id, source) WHERE is_preferred AND mapping_status = 'validated' AND valid_to IS NULL");
            DB::statement("ALTER TABLE taxa ADD CONSTRAINT taxa_taxonomic_status_check CHECK (taxonomic_status IN ('canonical', 'local_outside_taxref', 'local_provisional', 'local_unresolved', 'ignored_candidate'))");
            foreach (['monitoring_rules', 'data_collections', 'collection_coverages', 'import_jobs'] as $tableName) {
                DB::statement("ALTER TABLE {$tableName} ADD CONSTRAINT {$tableName}_taxon_scope_check CHECK (taxon_scope IN ('exact', 'subtree'))");
            }
        } else {
            DB::statement('CREATE UNIQUE INDEX taxa_taxref_identity_unique ON taxa (taxref_version_id, taxref_cd_ref) WHERE taxref_version_id IS NOT NULL AND taxref_cd_ref IS NOT NULL');
            DB::statement("CREATE UNIQUE INDEX taxref_records_one_accepted_concept ON taxref_records (taxonomic_reference_version_id, cd_ref) WHERE name_status = 'accepted'");
            DB::statement('CREATE UNIQUE INDEX taxon_names_concept_name_unique ON taxon_names (taxon_id, taxonomic_reference_version_id, name_type, normalized_name)');
            DB::statement("CREATE UNIQUE INDEX taxon_names_one_preferred ON taxon_names (taxon_id, name_type, ifnull(language_code, '')) WHERE is_preferred = 1");
            DB::statement("CREATE UNIQUE INDEX mappings_one_preferred_validated ON taxon_source_mappings (taxon_id, source) WHERE is_preferred = 1 AND mapping_status = 'validated' AND valid_to IS NULL");
        }
    }

    public function down(): void
    {
        foreach (['monitoring_rules', 'data_collections', 'collection_coverages', 'import_jobs'] as $tableName) {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("ALTER TABLE {$tableName} DROP CONSTRAINT IF EXISTS {$tableName}_taxon_scope_check");
            }
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('taxonomic_reference_version_id');
                $table->dropColumn(['taxon_scope', 'taxon_label_snapshot']);
            });
        }
        Schema::table('external_fetch_jobs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('taxon_source_mapping_id');
            $table->dropConstrainedForeignId('taxon_id');
        });
        DB::statement('DROP INDEX IF EXISTS mappings_one_preferred_validated');
        DB::statement('DROP INDEX IF EXISTS taxon_names_one_preferred');
        DB::statement('DROP INDEX IF EXISTS taxon_names_concept_name_unique');
        DB::statement('DROP INDEX IF EXISTS taxref_records_one_accepted_concept');
        DB::statement('DROP INDEX IF EXISTS taxa_taxref_identity_unique');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE taxa DROP CONSTRAINT IF EXISTS taxa_taxonomic_status_check');
        }
        Schema::table('taxon_source_mappings', function (Blueprint $table): void {
            $table->dropColumn([
                'source_accepted_taxon_id', 'source_scientific_name', 'source_rank', 'source_reference_version',
                'mapping_status', 'match_type', 'confidence', 'is_preferred', 'valid_from', 'valid_to', 'reviewed_at',
            ]);
            $table->unique(['taxon_id', 'source']);
        });
        Schema::table('taxa', function (Blueprint $table): void {
            $table->dropIndex('taxa_scientific_name_index');
            $table->dropColumn('taxonomic_status');
        });
    }
};
