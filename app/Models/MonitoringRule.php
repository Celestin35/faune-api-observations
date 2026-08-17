<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class MonitoringRule extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['zone_data' => 'array', 'sources' => 'array', 'is_active' => 'boolean',
            'last_synced_at' => 'immutable_datetime', 'next_sync_at' => 'immutable_datetime'];
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
        return $this->belongsToMany(Observation::class, 'monitoring_rule_observations')->withPivot('detected_at');
    }

    public function imports(): HasMany
    {
        return $this->hasMany(ImportJob::class);
    }

    public function externalFetchJobs(): HasMany
    {
        return $this->hasMany(ExternalFetchJob::class);
    }
}
