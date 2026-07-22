<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ExternalFetchJobBatch extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_last_batch' => 'boolean',
            'counts' => 'array',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(ExternalFetchJob::class, 'external_fetch_job_id');
    }
}
