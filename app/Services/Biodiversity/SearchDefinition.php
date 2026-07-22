<?php

namespace App\Services\Biodiversity;

use App\Models\Taxon;

final readonly class SearchDefinition
{
    /** @param array<string, mixed> $zone @param list<string> $sources */
    public function __construct(
        public ?Taxon $taxon,
        public string $dateFrom,
        public string $dateTo,
        public array $zone,
        public array $sources,
    ) {}

    public function zoneType(): string
    {
        return (string) $this->zone['type'];
    }

    public function zoneHash(): string
    {
        $zone = $this->zone;
        if (isset($zone['department_codes'])) {
            sort($zone['department_codes']);
        }

        return hash('sha256', json_encode($zone, JSON_THROW_ON_ERROR));
    }
}
