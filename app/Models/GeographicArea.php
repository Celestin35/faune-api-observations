<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class GeographicArea extends Model
{
    protected $guarded = [];

    /** @return list<string> */
    public static function fauneFranceDepartmentCodes(): array
    {
        return self::query()
            ->where('type', 'department')
            ->where('faune_portal', 'faune_france')
            ->orderBy('code')
            ->pluck('code')
            ->all();
    }

    protected function casts(): array
    {
        return ['geometry_geojson' => 'array', 'is_overseas' => 'boolean'];
    }
}
