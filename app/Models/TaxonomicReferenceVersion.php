<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class TaxonomicReferenceVersion extends Model
{
    public const STATUS_STAGING = 'staging';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUS_FAILED = 'failed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'published_on' => 'immutable_date',
            'metadata' => 'array',
            'imported_at' => 'immutable_datetime',
        ];
    }

    public function taxa(): HasMany
    {
        return $this->hasMany(Taxon::class, 'taxref_version_id');
    }

    public function records(): HasMany
    {
        return $this->hasMany(TaxrefRecord::class);
    }

    public function names(): HasMany
    {
        return $this->hasMany(TaxonName::class);
    }

    public function paths(): HasMany
    {
        return $this->hasMany(TaxonPath::class);
    }
}
