<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class ImportJob extends Model
{
    protected $table = 'import_jobs';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['zone_data' => 'array', 'date_from' => 'immutable_date:Y-m-d', 'date_to' => 'immutable_date:Y-m-d',
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

    public function externalFetchJob(): HasOne
    {
        return $this->hasOne(ExternalFetchJob::class);
    }
}
