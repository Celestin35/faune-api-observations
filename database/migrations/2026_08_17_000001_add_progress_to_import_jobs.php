<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_jobs', function (Blueprint $table): void {
            $table->string('progress_stage', 30)->nullable()->after('status');
            $table->unsignedBigInteger('progress_current')->default(0)->after('progress_stage');
            $table->unsignedBigInteger('progress_total')->nullable()->after('progress_current');
            $table->string('progress_message')->nullable()->after('progress_total');
        });

        DB::table('import_jobs')->where('status', 'pending')->update([
            'progress_stage' => 'queued',
            'progress_message' => 'En attente d’un worker.',
        ]);
        DB::table('import_jobs')->where('status', 'running')->update([
            'progress_stage' => 'saving',
            'progress_message' => 'Import en cours.',
        ]);
        DB::table('import_jobs')->whereIn('status', ['completed', 'partial', 'failed', 'cancelled'])->update([
            'progress_stage' => 'finished',
            'progress_message' => 'Import terminé.',
        ]);
        DB::table('import_jobs')->whereIn('status', ['completed', 'partial', 'failed', 'cancelled'])
            ->update(['progress_current' => DB::raw('processed_count')]);
        DB::table('import_jobs')->where('status', 'completed')
            ->update(['progress_total' => DB::raw('processed_count')]);
        DB::table('import_jobs')->where('status', 'partial')->update([
            'progress_message' => 'Limite de sécurité atteinte : des résultats supplémentaires peuvent exister.',
        ]);
    }

    public function down(): void
    {
        Schema::table('import_jobs', function (Blueprint $table): void {
            $table->dropColumn(['progress_stage', 'progress_current', 'progress_total', 'progress_message']);
        });
    }
};
