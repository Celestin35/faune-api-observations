<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TaxonSourceMapping extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['raw_data' => 'array'];
    }

    public function taxon(): BelongsTo
    {
        return $this->belongsTo(Taxon::class);
    }
}
