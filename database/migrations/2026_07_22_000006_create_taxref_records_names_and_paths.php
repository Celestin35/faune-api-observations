<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taxref_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('taxonomic_reference_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('taxon_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('cd_nom');
            $table->unsignedBigInteger('cd_ref');
            $table->unsignedBigInteger('parent_cd_ref')->nullable();
            $table->string('scientific_name', 512);
            $table->string('authorship', 512)->nullable();
            $table->string('rank_code', 30)->nullable();
            $table->foreign('rank_code')->references('code')->on('taxon_ranks')->nullOnDelete();
            $table->enum('name_status', ['accepted', 'synonym', 'other']);
            $table->jsonb('raw_data');
            $table->timestamps();
            $table->unique(['taxonomic_reference_version_id', 'cd_nom']);
            $table->index(['taxonomic_reference_version_id', 'cd_ref']);
            $table->index(['taxonomic_reference_version_id', 'parent_cd_ref']);
            $table->index('taxon_id');
        });

        Schema::table('taxa', function (Blueprint $table): void {
            $table->foreignId('current_taxref_record_id')->nullable()->after('merged_into_taxon_id')
                ->constrained('taxref_records')->nullOnDelete();
        });

        Schema::create('taxon_names', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('taxon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('taxonomic_reference_version_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->foreignId('taxref_record_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 512);
            $table->string('normalized_name', 512);
            $table->enum('name_type', ['accepted_scientific', 'scientific_synonym', 'vernacular']);
            $table->string('language_code', 12)->nullable();
            $table->string('authorship', 512)->nullable();
            $table->boolean('is_preferred')->default(false)->index();
            $table->string('source', 40);
            $table->timestamps();
            $table->index(['taxon_id', 'name_type', 'language_code']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX taxon_names_normalized_trgm ON taxon_names USING gin (normalized_name gin_trgm_ops)');
            DB::statement('CREATE INDEX taxon_names_normalized_prefix ON taxon_names (normalized_name text_pattern_ops)');
        } else {
            Schema::table('taxon_names', function (Blueprint $table): void {
                $table->index('normalized_name', 'taxon_names_normalized_prefix');
            });
        }

        Schema::create('taxon_paths', function (Blueprint $table): void {
            $table->foreignId('taxonomic_reference_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ancestor_taxon_id')->constrained('taxa')->cascadeOnDelete();
            $table->foreignId('descendant_taxon_id')->constrained('taxa')->cascadeOnDelete();
            $table->unsignedInteger('depth');
            $table->primary([
                'taxonomic_reference_version_id', 'ancestor_taxon_id', 'descendant_taxon_id',
            ]);
            $table->index([
                'taxonomic_reference_version_id', 'ancestor_taxon_id', 'depth',
            ], 'taxon_paths_descendants_lookup');
            $table->index([
                'taxonomic_reference_version_id', 'descendant_taxon_id', 'depth',
            ], 'taxon_paths_ancestors_lookup');
        });

        if (DB::getDriverName() === 'sqlite') {
            DB::statement("CREATE TRIGGER taxa_cannot_merge_into_itself_insert BEFORE INSERT ON taxa WHEN NEW.merged_into_taxon_id IS NOT NULL AND NEW.merged_into_taxon_id = NEW.id BEGIN SELECT RAISE(ABORT, 'A taxon cannot be merged into itself.'); END");
            DB::statement("CREATE TRIGGER taxa_cannot_merge_into_itself_update BEFORE UPDATE OF merged_into_taxon_id ON taxa WHEN NEW.merged_into_taxon_id IS NOT NULL AND NEW.merged_into_taxon_id = NEW.id BEGIN SELECT RAISE(ABORT, 'A taxon cannot be merged into itself.'); END");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS taxa_cannot_merge_into_itself_insert');
            DB::statement('DROP TRIGGER IF EXISTS taxa_cannot_merge_into_itself_update');
        }

        Schema::dropIfExists('taxon_paths');
        Schema::dropIfExists('taxon_names');

        Schema::table('taxa', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('current_taxref_record_id');
        });

        Schema::dropIfExists('taxref_records');
    }
};
