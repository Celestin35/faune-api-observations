<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class SourceSyncState extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['state' => 'array', 'last_synced_at' => 'immutable_datetime'];
    }
}
