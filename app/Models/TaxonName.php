<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TaxonName extends Model
{
    public const TYPE_ACCEPTED_SCIENTIFIC = 'accepted_scientific';

    public const TYPE_SCIENTIFIC_SYNONYM = 'scientific_synonym';

    public const TYPE_VERNACULAR = 'vernacular';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_preferred' => 'boolean'];
    }

    public function taxon(): BelongsTo
    {
        return $this->belongsTo(Taxon::class);
    }

    public function referenceVersion(): BelongsTo
    {
        return $this->belongsTo(TaxonomicReferenceVersion::class, 'taxonomic_reference_version_id');
    }

    public function taxrefRecord(): BelongsTo
    {
        return $this->belongsTo(TaxrefRecord::class);
    }
}
