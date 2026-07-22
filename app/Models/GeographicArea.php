<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class GeographicArea extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['geometry_geojson' => 'array'];
    }
}
