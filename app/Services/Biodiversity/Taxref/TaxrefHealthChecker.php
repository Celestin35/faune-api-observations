<?php

namespace App\Services\Biodiversity\Taxref;

use App\Models\Taxon;
use App\Models\TaxonName;
use App\Models\TaxonomicReferenceVersion;
use App\Models\TaxonPath;
use App\Models\TaxrefRecord;
use Illuminate\Support\Facades\DB;

final class TaxrefHealthChecker
{
    /** @return array{healthy:bool,checks:array<string,array{actual:int|string|bool,expected:int|string|bool,ok:bool}>} */
    public function check(TaxonomicReferenceVersion $version, bool $requireActive = true): array
    {
        $checks = [];
        $this->add($checks, 'version_active', $version->status, $requireActive ? TaxonomicReferenceVersion::STATUS_ACTIVE : $version->status);
        $recordCount = TaxrefRecord::query()->where('taxonomic_reference_version_id', $version->id)->count();
        $conceptCount = TaxrefRecord::query()->where('taxonomic_reference_version_id', $version->id)->where('name_status', 'accepted')->count();
        $this->add($checks, 'records', $recordCount, $version->version === '18' ? 708685 : $recordCount);
        $this->add($checks, 'canonical_taxa', Taxon::query()->where('taxref_version_id', $version->id)->count(), $version->version === '18' ? 300377 : $conceptCount);
        $this->add($checks, 'accepted_names', TaxonName::query()->where('taxonomic_reference_version_id', $version->id)->where('name_type', 'accepted_scientific')->count(), $version->version === '18' ? 300377 : $conceptCount);
        if ($version->version === '18') {
            $this->add($checks, 'all_names', TaxonName::query()->where('taxonomic_reference_version_id', $version->id)->count(), 752887);
            $this->add($checks, 'paths', TaxonPath::query()->where('taxonomic_reference_version_id', $version->id)->count(), 5479172);
            $this->add($checks, 'roots', $this->roots($version, false), 2);
            $this->add($checks, 'technical_orphans', $this->roots($version, true), 8);
            $this->add($checks, 'max_depth', (int) TaxonPath::query()->where('taxonomic_reference_version_id', $version->id)->max('depth'), 35);
            $this->add($checks, 'cycles', TaxonPath::query()->where('taxonomic_reference_version_id', $version->id)
                ->whereColumn('ancestor_taxon_id', 'descendant_taxon_id')->where('depth', '>', 0)->count(), 0);
            // These are non-regression floors, not immutable totals: mappings and
            // observations are expected to grow after the initial TAXREF import.
            $this->addMinimum($checks, 'historical_mappings', DB::table('taxon_source_mappings')->count(), 21);
            $this->addMinimum($checks, 'historical_observations', DB::table('observations')->count(), 11);
            $this->add($checks, 'local_outside_taxref', Taxon::query()->whereIn('taxonomic_status', ['local_outside_taxref', 'local_provisional', 'local_unresolved'])->count(), 17);
            foreach (['Animalia', 'Chordata', 'Aves', 'Mammalia', 'Tichodroma muraria', 'Vulpes vulpes', 'Papilio machaon'] as $name) {
                $taxon = Taxon::query()->where('taxref_version_id', $version->id)->where('accepted_scientific_name', $name)->first();
                $this->add($checks, 'sample_'.$this->key($name), $taxon !== null && TaxonPath::query()
                    ->where('taxonomic_reference_version_id', $version->id)->where('descendant_taxon_id', $taxon?->id)->exists(), true);
            }
        }

        return ['healthy' => collect($checks)->every('ok'), 'checks' => $checks];
    }

    /** @param array<string,array{actual:mixed,expected:mixed,ok:bool}> $checks */
    private function add(array &$checks, string $name, mixed $actual, mixed $expected): void
    {
        $checks[$name] = ['actual' => $actual, 'expected' => $expected, 'ok' => $actual === $expected];
    }

    /** @param array<string,array{actual:mixed,expected:mixed,ok:bool}> $checks */
    private function addMinimum(array &$checks, string $name, int $actual, int $minimum): void
    {
        $checks[$name] = ['actual' => $actual, 'expected' => ">= {$minimum}", 'ok' => $actual >= $minimum];
    }

    private function roots(TaxonomicReferenceVersion $version, bool $orphans): int
    {
        return Taxon::query()->where('taxref_version_id', $version->id)->whereNull('parent_id')
            ->whereHas('currentTaxrefRecord', fn ($query) => $orphans ? $query->whereNotNull('parent_cd_ref') : $query->whereNull('parent_cd_ref'))->count();
    }

    private function key(string $value): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '_', $value) ?? $value);
    }
}
