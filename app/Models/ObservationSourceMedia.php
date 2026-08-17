<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ObservationSourceMedia extends Model
{
    protected $table = 'observation_source_media';

    protected $guarded = [];

    public function source(): BelongsTo
    {
        return $this->belongsTo(ObservationSource::class, 'observation_source_id');
    }
}
