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
        public string $taxonScope = 'exact',
        public ?int $taxonomicReferenceVersionId = null,
    ) {}

    public function zoneType(): string
    {
        return (string) $this->zone['type'];
    }

    public function taxonLabel(): ?string
    {
        return $this->taxon === null
            ? null
            : ($this->taxon->preferred_french_name ?: $this->taxon->accepted_scientific_name ?: $this->taxon->vernacular_name ?: $this->taxon->scientific_name);
    }

    public function zoneHash(): string
    {
        $zone = $this->zone;
        unset($zone['address']);
        if (isset($zone['department_codes'])) {
            sort($zone['department_codes']);
        }

        return hash('sha256', json_encode($zone, JSON_THROW_ON_ERROR));
    }
}
