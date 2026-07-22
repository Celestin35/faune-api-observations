<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ImportJob extends Model
{
    protected $table = 'import_jobs';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['zone_data' => 'array', 'date_from' => 'date', 'date_to' => 'date',
            'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }

    public function taxon(): BelongsTo
    {
        return $this->belongsTo(Taxon::class);
    }

    public function referenceVersion(): BelongsTo
    {
        return $this->belongsTo(TaxonomicReferenceVersion::class, 'taxonomic_reference_version_id');
    }
}
