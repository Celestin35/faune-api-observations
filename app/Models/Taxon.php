<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Taxon extends Model
{
    protected $hidden = [
        'taxref_version_id',
        'taxref_cd_ref',
        'rank_code',
        'parent_id',
        'accepted_scientific_name',
        'authorship',
        'preferred_french_name',
        'status',
        'merged_into_taxon_id',
        'current_taxref_record_id',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['classification' => 'array'];
    }

    public function isCanonical(): bool
    {
        return $this->taxonomic_status === 'canonical' && $this->taxref_version_id !== null;
    }

    public function defaultScope(): string
    {
        return $this->rank_code === 'species' || $this->rank === 'species' ? 'exact' : 'subtree';
    }

    public function frenchName(): ?string
    {
        if (is_string($this->preferred_french_name) && trim($this->preferred_french_name) !== '') {
            return $this->preferred_french_name;
        }

        // A vernacular name coming from GBIF or iNaturalist is not guaranteed
        // to be French. Only use the compatibility field for curated taxa.
        if ($this->taxonomic_status !== 'local_unresolved'
            && is_string($this->vernacular_name) && trim($this->vernacular_name) !== '') {
            return $this->vernacular_name;
        }

        return null;
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(TaxonSourceMapping::class);
    }

    public function referenceVersion(): BelongsTo
    {
        return $this->belongsTo(TaxonomicReferenceVersion::class, 'taxref_version_id');
    }

    public function rankDefinition(): BelongsTo
    {
        return $this->belongsTo(TaxonRank::class, 'rank_code', 'code');
    }

    public function rank(): BelongsTo
    {
        return $this->rankDefinition();
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_taxon_id');
    }

    public function currentTaxrefRecord(): BelongsTo
    {
        return $this->belongsTo(TaxrefRecord::class, 'current_taxref_record_id');
    }

    public function names(): HasMany
    {
        return $this->hasMany(TaxonName::class);
    }

    public function ancestorPaths(): HasMany
    {
        return $this->hasMany(TaxonPath::class, 'descendant_taxon_id');
    }

    public function descendantPaths(): HasMany
    {
        return $this->hasMany(TaxonPath::class, 'ancestor_taxon_id');
    }
}
