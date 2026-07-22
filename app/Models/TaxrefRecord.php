<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class TaxrefRecord extends Model
{
    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_SYNONYM = 'synonym';

    public const STATUS_OTHER = 'other';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['raw_data' => 'array'];
    }

    public function referenceVersion(): BelongsTo
    {
        return $this->belongsTo(TaxonomicReferenceVersion::class, 'taxonomic_reference_version_id');
    }

    public function taxon(): BelongsTo
    {
        return $this->belongsTo(Taxon::class);
    }

    public function rank(): BelongsTo
    {
        return $this->belongsTo(TaxonRank::class, 'rank_code', 'code');
    }

    public function names(): HasMany
    {
        return $this->hasMany(TaxonName::class);
    }
}
