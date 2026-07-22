<?php

namespace App\Services\Biodiversity;

use App\Models\Observation;

final readonly class PersistOutcome
{
    public function __construct(public Observation $observation, public string $status) {}
}
