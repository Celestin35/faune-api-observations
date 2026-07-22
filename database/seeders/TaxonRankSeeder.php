<?php

namespace Database\Seeders;

use App\Models\TaxonRank;
use Illuminate\Database\Seeder;

final class TaxonRankSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->ranks() as $rank) {
            TaxonRank::query()->updateOrCreate(['code' => $rank['code']], $rank);
        }
    }

    /** @return list<array{code: string, label_fr: string, sort_order: int, selectable: bool, taxref_rank_codes: list<string>}> */
    private function ranks(): array
    {
        return [
            ['code' => 'kingdom', 'label_fr' => 'Règne', 'sort_order' => 10, 'selectable' => true, 'taxref_rank_codes' => ['KD', 'KINGDOM']],
            ['code' => 'phylum', 'label_fr' => 'Embranchement', 'sort_order' => 20, 'selectable' => true, 'taxref_rank_codes' => ['PH', 'PHYLUM']],
            ['code' => 'class', 'label_fr' => 'Classe', 'sort_order' => 30, 'selectable' => true, 'taxref_rank_codes' => ['CL', 'CLASS']],
            ['code' => 'order', 'label_fr' => 'Ordre', 'sort_order' => 40, 'selectable' => true, 'taxref_rank_codes' => ['OR', 'ORDER']],
            ['code' => 'family', 'label_fr' => 'Famille', 'sort_order' => 50, 'selectable' => true, 'taxref_rank_codes' => ['FM', 'FAMILY']],
            ['code' => 'genus', 'label_fr' => 'Genre', 'sort_order' => 60, 'selectable' => true, 'taxref_rank_codes' => ['GN', 'GENUS']],
            ['code' => 'species', 'label_fr' => 'Espèce', 'sort_order' => 70, 'selectable' => true, 'taxref_rank_codes' => ['ES', 'SPECIES']],
            ['code' => 'subspecies', 'label_fr' => 'Sous-espèce', 'sort_order' => 80, 'selectable' => false, 'taxref_rank_codes' => ['SSES', 'SUBSPECIES']],
        ];
    }
}
