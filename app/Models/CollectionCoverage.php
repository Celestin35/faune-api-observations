<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class CollectionCoverage extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['covered_from' => 'date', 'covered_to' => 'date', 'last_synced_at' => 'immutable_datetime'];
    }
}
