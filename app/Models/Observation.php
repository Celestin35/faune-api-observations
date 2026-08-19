<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Observation extends Model
{
    protected $guarded = [];

    protected $hidden = ['geometry'];

    protected function casts(): array
    {
        return [
            'observed_at' => 'immutable_datetime',
            'first_imported_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'retain_until' => 'immutable_datetime',
            'geography_resolved_at' => 'immutable_datetime',
            'latitude' => 'float', 'longitude' => 'float',
            'coordinate_uncertainty_m' => 'float',
            'elevation_m' => 'float',
            'elevation_resolved_at' => 'immutable_datetime',
            'geography_enrichment_attempted_at' => 'immutable_datetime',
        ];
    }

    public function taxon(): BelongsTo
    {
        return $this->belongsTo(Taxon::class);
    }

    public function sources(): HasMany
    {
        return $this->hasMany(ObservationSource::class);
    }

    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(DataCollection::class, 'collection_observations')->withPivot('attached_at');
    }

    public function monitoringRules(): BelongsToMany
    {
        return $this->belongsToMany(MonitoringRule::class, 'monitoring_rule_observations')->withPivot('detected_at');
    }
}
