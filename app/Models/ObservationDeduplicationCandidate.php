<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ObservationDeduplicationCandidate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['reasons' => 'array', 'score' => 'float'];
    }
}
