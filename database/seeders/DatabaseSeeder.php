<?php

namespace Database\Seeders;

use App\Models\GeographicArea;
use App\Models\Taxon;
use App\Models\TaxonSourceMapping;
use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(TaxonRankSeeder::class);

        $gadmIdentifiers = $this->identifierMap('gbif_gadm_departments.tsv');
        $inaturalistPlaces = $this->identifierMap('inaturalist_department_places.tsv');

        $path = database_path('data/french_departments.tsv');
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Impossible de lire le référentiel {$path}.");
        }
        $header = fgetcsv($handle, null, "\t", '"', '');
        $count = 0;
        while (($values = fgetcsv($handle, null, "\t", '"', '')) !== false) {
            if ($values === [null] || $values === []) {
                continue;
            }
            $department = array_combine($header, $values);
            if ($department === false) {
                throw new \RuntimeException('Une ligne du référentiel des départements est invalide.');
            }
            $code = $department['code'];
            $gadm = $gadmIdentifiers[$code] ?? null;
            $place = isset($inaturalistPlaces[$code]) ? (int) $inaturalistPlaces[$code] : null;
            $west = (float) $department['west'];
            $south = (float) $department['south'];
            $east = (float) $department['east'];
            $north = (float) $department['north'];
            GeographicArea::updateOrCreate(['code' => $code], [
                'type' => 'department', 'name' => $department['name'], 'region_name' => $department['region_name'],
                'is_overseas' => $department['faune_portal'] !== 'faune_france',
                'faune_portal' => $department['faune_portal'], 'gadm_gid' => $gadm,
                'inaturalist_place_id' => $place,
                'geometry_geojson' => ['type' => 'Polygon', 'coordinates' => [[[$west, $south], [$west, $north], [$east, $north], [$east, $south], [$west, $south]]]],
            ]);
            $count++;
        }
        fclose($handle);
        if ($count !== 101) {
            throw new \RuntimeException("Le référentiel doit contenir 101 départements, {$count} trouvés.");
        }

        $wallcreeper = Taxon::updateOrCreate(['scientific_name' => 'Tichodroma muraria'], [
            'vernacular_name' => 'Tichodrome échelette', 'rank' => 'species',
            'classification' => ['kingdom' => 'Animalia', 'phylum' => 'Chordata', 'class' => 'Aves',
                'order' => 'Passeriformes', 'family' => 'Tichodromidae', 'genus' => 'Tichodroma', 'species' => 'Tichodroma muraria'],
        ]);
        foreach ([['gbif', '2484918'], ['inaturalist', '14840'], ['faune_france', '383']] as [$source, $id]) {
            TaxonSourceMapping::updateOrCreate(['source' => $source, 'source_taxon_id' => $id], ['taxon_id' => $wallcreeper->id, 'raw_data' => []]);
        }
        foreach ([['Animalia', 'kingdom'], ['Delphinus delphis', 'species'], ['Vulpes vulpes', 'species'], ['Papilio machaon', 'species']] as [$name, $rank]) {
            Taxon::firstOrCreate(['scientific_name' => $name], ['rank' => $rank, 'classification' => ['kingdom' => 'Animalia']]);
        }
    }

    /** @return array<string, string> */
    private function identifierMap(string $fileName): array
    {
        $path = database_path('data/'.$fileName);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Impossible de lire le référentiel {$path}.");
        }
        fgetcsv($handle, null, "\t", '"', '');
        $identifiers = [];
        while (($values = fgetcsv($handle, null, "\t", '"', '')) !== false) {
            if (isset($values[0], $values[1]) && $values[1] !== '') {
                $identifiers[$values[0]] = $values[1];
            }
        }
        fclose($handle);

        return $identifiers;
    }
}
