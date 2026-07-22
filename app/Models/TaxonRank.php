<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class TaxonRank extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'selectable' => 'boolean',
            'taxref_rank_codes' => 'array',
        ];
    }

    public function taxa(): HasMany
    {
        return $this->hasMany(Taxon::class, 'rank_code', 'code');
    }

    public function records(): HasMany
    {
        return $this->hasMany(TaxrefRecord::class, 'rank_code', 'code');
    }
}
