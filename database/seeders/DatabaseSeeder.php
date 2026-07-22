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
        $departments = [
            ['35', 'Ille-et-Vilaine', 'FRA.3.3_1', 99551, -2.28754, 47.63136, -1.01820, 48.76666],
            ['29', 'Finistère', 'FRA.3.2_1', 49292, -5.19356, 47.65102, -3.38803, 48.80444],
            ['56', 'Morbihan', 'FRA.3.4_1', 30156, -3.73297, 47.22888, -2.03604, 48.21002],
            ['22', "Côtes-d'Armor", 'FRA.3.1_1', 30154, -3.66431, 48.03254, -1.90900, 49.15569],
            ['65', 'Hautes-Pyrénées', 'FRA.11.7_1', 30199, -0.32688, 42.67340, 0.64596, 43.61102],
            ['64', 'Pyrénées-Atlantiques', 'FRA.10.11_1', 30142, -1.79088, 42.77767, 0.02867, 43.59683],
            ['09', 'Ariège', 'FRA.11.1_1', 30195, 0.82668, 42.57232, 2.17552, 43.31549],
            ['38', 'Isère', 'FRA.1.8_1', 30227, 4.74315, 44.69696, 6.35855, 45.88278],
            ['73', 'Savoie', 'FRA.1.12_1', 30230, 5.62375, 45.05237, 7.18512, 45.93846],
            ['74', 'Haute-Savoie', 'FRA.1.7_1', 30226, 5.80547, 45.68271, 7.04628, 46.45704],
        ];
        foreach ($departments as [$code, $name, $gadm, $place, $west, $south, $east, $north]) {
            GeographicArea::updateOrCreate(['code' => $code], [
                'type' => 'department', 'name' => $name, 'gadm_gid' => $gadm, 'inaturalist_place_id' => $place,
                'geometry_geojson' => ['type' => 'Polygon', 'coordinates' => [[[$west, $south], [$west, $north], [$east, $north], [$east, $south], [$west, $south]]]],
            ]);
        }

        $wallcreeper = Taxon::updateOrCreate(['scientific_name' => 'Tichodroma muraria'], [
            'vernacular_name' => 'Tichodrome échelette', 'rank' => 'species',
            'classification' => ['kingdom' => 'Animalia', 'phylum' => 'Chordata', 'class' => 'Aves',
                'order' => 'Passeriformes', 'family' => 'Tichodromidae', 'genus' => 'Tichodroma', 'species' => 'Tichodroma muraria'],
        ]);
        foreach ([['gbif', '2484918'], ['inaturalist', '14840']] as [$source, $id]) {
            TaxonSourceMapping::updateOrCreate(['source' => $source, 'source_taxon_id' => $id], ['taxon_id' => $wallcreeper->id, 'raw_data' => []]);
        }
        foreach ([['Animalia', 'kingdom'], ['Delphinus delphis', 'species'], ['Vulpes vulpes', 'species'], ['Papilio machaon', 'species']] as [$name, $rank]) {
            Taxon::firstOrCreate(['scientific_name' => $name], ['rank' => $rank, 'classification' => ['kingdom' => 'Animalia']]);
        }
    }
}
