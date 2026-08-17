<?php

namespace App\Services\Biodiversity;

final readonly class ObservationQueryExecution
{
    public function __construct(
        public string $purpose,
        public ?int $collectionId = null,
        public ?int $monitoringRuleId = null,
        public ?int $frequencyMinutes = null,
        public ?int $importLimit = null,
        public ?int $maxPages = null,
        public ?int $pagePauseMs = null,
    ) {}
}
