<?php

namespace App\Services\Biodiversity\Contracts;

use App\Services\Biodiversity\Data\NormalizedOccurrence;
use App\Services\Biodiversity\Data\OccurrenceQuery;
use App\Services\Biodiversity\Data\SourceResult;

interface OccurrenceSourceConnector
{
    public function key(): string;

    public function search(OccurrenceQuery $query, int $limit = 3): SourceResult;

    public function count(OccurrenceQuery $query): int;

    /** @param array<string, mixed> $record */
    public function normalize(array $record): NormalizedOccurrence;
}
