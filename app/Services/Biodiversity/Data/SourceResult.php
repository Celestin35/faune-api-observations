<?php

namespace App\Services\Biodiversity\Data;

final readonly class SourceResult
{
    /**
     * @param  list<NormalizedOccurrence>  $occurrences
     * @param  array<string, mixed>  $requestParameters
     * @param  array<string, string>  $quotaHeaders
     */
    public function __construct(
        public string $source,
        public int $total,
        public array $occurrences,
        public array $requestParameters,
        public array $quotaHeaders = [],
    ) {}
}
