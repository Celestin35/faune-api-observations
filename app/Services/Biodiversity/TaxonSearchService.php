<?php

namespace App\Services\Biodiversity;

use App\Models\Taxon;
use App\Models\TaxonSourceMapping;
use App\Services\Biodiversity\Sources\GbifConnector;
use App\Services\Biodiversity\Sources\INaturalistConnector;
use Illuminate\Support\Str;

final class TaxonSearchService
{
    public function __construct(private GbifConnector $gbif, private INaturalistConnector $inaturalist) {}

    /** @return list<Taxon> */
    public function search(string $query, int $limit = 10): array
    {
        $local = Taxon::query()->with('mappings')->whereRaw('LOWER(scientific_name) LIKE ?', ['%'.strtolower($query).'%'])
            ->limit($limit)->get();

        try {
            $gbif = $this->gbif->searchTaxa($query, $limit);
        } catch (\Throwable $exception) {
            report($exception);
            $gbif = [];
        }
        try {
            $inat = $this->inaturalist->searchTaxa($query, $limit);
        } catch (\Throwable $exception) {
            report($exception);
            $inat = [];
        }
        $inatByName = collect($inat)->keyBy(fn (array $item): string => strtolower((string) ($item['name'] ?? '')));
        foreach ($gbif as $record) {
            $name = (string) ($record['canonicalName'] ?? $record['scientificName'] ?? '');
            $rank = strtolower((string) ($record['rank'] ?? ''));
            if ($name === '' || ! in_array($rank, ['species', 'genus', 'family', 'order', 'kingdom'], true)) {
                continue;
            }
            $classification = array_filter(array_combine(
                ['kingdom', 'phylum', 'class', 'order', 'family', 'genus', 'species'],
                array_map(fn (string $key) => $record[$key] ?? null, ['kingdom', 'phylum', 'class', 'order', 'family', 'genus', 'species'])
            ));
            $taxon = Taxon::updateOrCreate(['scientific_name' => $name], [
                'vernacular_name' => $record['vernacularNames'][0]['vernacularName'] ?? null,
                'rank' => $rank, 'classification' => $classification,
            ]);
            if (isset($record['key'])) {
                $this->storeMapping($taxon, 'gbif', (string) $record['key'], $record);
            }
            $inatRecord = $inatByName->get(strtolower($name));
            if (is_array($inatRecord) && isset($inatRecord['id'])) {
                $this->storeMapping($taxon, 'inaturalist', (string) $inatRecord['id'], $inatRecord);
                if (! $taxon->vernacular_name && isset($inatRecord['preferred_common_name'])) {
                    $taxon->update(['vernacular_name' => Str::limit((string) $inatRecord['preferred_common_name'], 255)]);
                }
            }
        }

        return Taxon::query()->with('mappings')->whereRaw('LOWER(scientific_name) LIKE ?', ['%'.strtolower($query).'%'])
            ->limit($limit)->get()->all() ?: $local->all();
    }

    /** @param array<string, mixed> $rawData */
    private function storeMapping(Taxon $taxon, string $source, string $sourceTaxonId, array $rawData): void
    {
        $sourceMapping = TaxonSourceMapping::query()
            ->where('source', $source)
            ->where('source_taxon_id', $sourceTaxonId)
            ->first();
        if ($sourceMapping !== null) {
            if ($sourceMapping->taxon_id === $taxon->id) {
                $sourceMapping->update(['raw_data' => $rawData]);
            }

            return;
        }

        $taxonMapping = TaxonSourceMapping::query()
            ->where('taxon_id', $taxon->id)
            ->where('source', $source)
            ->first();
        if ($taxonMapping !== null) {
            return;
        }

        TaxonSourceMapping::create([
            'taxon_id' => $taxon->id,
            'source' => $source,
            'source_taxon_id' => $sourceTaxonId,
            'raw_data' => $rawData,
        ]);
    }
}
