<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ObservationSource extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'canonical_identifiers' => 'array', 'raw_data' => 'array',
            'source_created_at' => 'immutable_datetime', 'source_updated_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'source_observed_at' => 'immutable_datetime',
            'public_latitude' => 'float',
            'public_longitude' => 'float',
            'coordinate_uncertainty_m' => 'float',
            'observer_is_public' => 'boolean',
        ];
    }

    public function observation(): BelongsTo
    {
        return $this->belongsTo(Observation::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(ObservationSourceMedia::class)->orderBy('position');
    }
}
