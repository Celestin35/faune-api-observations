<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TaxonSourceMapping extends Model
{
    protected $guarded = [];

    public static function normalizeSource(string $source): string
    {
        return $source === 'faune-france' ? 'faune_france' : str_replace('-', '_', $source);
    }

    public function setSourceAttribute(string $source): void
    {
        $this->attributes['source'] = self::normalizeSource($source);
    }

    protected function casts(): array
    {
        return ['raw_data' => 'array'];
    }

    public function taxon(): BelongsTo
    {
        return $this->belongsTo(Taxon::class);
    }
}
