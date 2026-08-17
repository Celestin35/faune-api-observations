<?php

namespace App\Services\Biodiversity;

use App\Models\Taxon;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class ObservationQueryCriteria
{
    /**
     * @param  array<string, mixed>  $zone
     * @param  list<string>  $sources
     */
    public function __construct(
        public ?Taxon $taxon,
        public string $taxonScope,
        public ?int $taxonomicReferenceVersionId,
        public ?string $taxonLabelSnapshot,
        public string $periodType,
        public ?string $dateFrom,
        public ?string $dateTo,
        public ?int $windowMinutes,
        public array $zone,
        public array $sources,
    ) {
        if (! in_array($periodType, ['absolute', 'sliding'], true)) {
            throw new InvalidArgumentException('Le type de période doit être absolute ou sliding.');
        }
    }

    public function resolve(?CarbonImmutable $now = null): SearchDefinition
    {
        if ($this->periodType === 'absolute') {
            $from = (string) $this->dateFrom;
            $to = (string) $this->dateTo;
        } else {
            $now ??= CarbonImmutable::now();
            $from = $now->subMinutes((int) $this->windowMinutes)->toDateString();
            $to = $now->toDateString();
        }

        return new SearchDefinition(
            $this->taxon,
            $from,
            $to,
            $this->zone,
            $this->sources,
            $this->taxonScope,
            $this->taxonomicReferenceVersionId,
        );
    }
}
