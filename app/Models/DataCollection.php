<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class DataCollection extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['zone_data' => 'array', 'sources' => 'array', 'is_permanent' => 'boolean', 'date_from' => 'date', 'date_to' => 'date'];
    }

    public function taxon(): BelongsTo
    {
        return $this->belongsTo(Taxon::class);
    }

    public function referenceVersion(): BelongsTo
    {
        return $this->belongsTo(TaxonomicReferenceVersion::class, 'taxonomic_reference_version_id');
    }

    public function observations(): BelongsToMany
    {
        return $this->belongsToMany(Observation::class, 'collection_observations')->withPivot('attached_at');
    }

    public function imports(): HasMany
    {
        return $this->hasMany(ImportJob::class);
    }

    public function coverages(): HasMany
    {
        return $this->hasMany(CollectionCoverage::class);
    }
}
