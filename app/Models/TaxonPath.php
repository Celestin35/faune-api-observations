<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TaxonPath extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    public function referenceVersion(): BelongsTo
    {
        return $this->belongsTo(TaxonomicReferenceVersion::class, 'taxonomic_reference_version_id');
    }

    public function ancestor(): BelongsTo
    {
        return $this->belongsTo(Taxon::class, 'ancestor_taxon_id');
    }

    public function descendant(): BelongsTo
    {
        return $this->belongsTo(Taxon::class, 'descendant_taxon_id');
    }

    public function scopeDescendantsOf(Builder $query, int $versionId, int $taxonId): Builder
    {
        return $query->where('taxonomic_reference_version_id', $versionId)
            ->where('ancestor_taxon_id', $taxonId);
    }

    public function scopeAncestorsOf(Builder $query, int $versionId, int $taxonId): Builder
    {
        return $query->where('taxonomic_reference_version_id', $versionId)
            ->where('descendant_taxon_id', $taxonId);
    }
}
