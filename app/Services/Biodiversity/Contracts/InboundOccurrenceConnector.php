<?php

namespace App\Services\Biodiversity\Contracts;

use App\Services\Biodiversity\Data\NormalizedOccurrence;

interface InboundOccurrenceConnector
{
    /** @param array<string, mixed> $payload */
    public function normalizeInbound(array $payload): NormalizedOccurrence;
}
