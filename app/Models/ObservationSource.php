<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ObservationSource extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'canonical_identifiers' => 'array', 'raw_data' => 'array',
            'source_created_at' => 'immutable_datetime', 'source_updated_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
        ];
    }

    public function observation(): BelongsTo
    {
        return $this->belongsTo(Observation::class);
    }
}
