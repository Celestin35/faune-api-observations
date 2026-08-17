<?php

namespace App\Services\Biodiversity\FauneFrance;

use App\Models\Taxon;

final class FauneFranceTaxonomicGroups
{
    /** @var array<int, string> */
    private const GROUPS = [
        1 => 'Oiseaux',
        3 => 'Mammifères',
        4 => 'Mammifères marins',
        6 => 'Reptiles',
        7 => 'Amphibiens',
        8 => 'Odonates',
        9 => 'Papillons de jour',
        10 => 'Papillons de nuit',
        11 => 'Orthoptères',
        12 => 'Hyménoptères',
        18 => 'Mantes',
        19 => 'Cigales',
        20 => 'Punaises',
        21 => 'Coléoptères',
        22 => 'Névroptères',
        25 => 'Diptères',
        26 => 'Phasmes',
        27 => 'Araignées',
        28 => 'Scorpions',
        29 => 'Poissons',
        30 => 'Crustacés',
        31 => 'Gastéropodes',
        32 => 'Bivalves',
        33 => 'Branchiopodes',
        43 => 'Dermaptères',
        51 => 'Fourmis',
    ];

    /** @var array<string, list<int>> */
    private const TAXREF_GROUPS = [
        'Animalia' => [1, 3, 4, 6, 7, 8, 9, 10, 11, 12, 18, 19, 20, 21, 22, 25, 26, 27, 28, 29, 30, 31, 32, 33, 43, 51],
        'Vertebrata' => [1, 3, 4, 6, 7, 29],
        'Aves' => [1],
        'Mammalia' => [3, 4],
        'Reptilia' => [6],
        'Sauropsida' => [6],
        'Amphibia' => [7],
        'Arthropoda' => [8, 9, 10, 11, 12, 18, 19, 20, 21, 22, 25, 26, 27, 28, 30, 33, 43, 51],
        'Hexapoda' => [8, 9, 10, 11, 12, 18, 19, 20, 21, 22, 25, 26, 43, 51],
        'Insecta' => [8, 9, 10, 11, 12, 18, 19, 20, 21, 22, 25, 26, 43, 51],
        'Odonata' => [8],
        'Lepidoptera' => [9, 10],
        'Orthoptera' => [11],
        'Hymenoptera' => [12, 51],
        'Mantodea' => [18],
        'Auchenorrhyncha' => [19],
        'Heteroptera' => [20],
        'Coleoptera' => [21],
        'Neuroptera' => [22],
        'Diptera' => [25],
        'Phasmatodea' => [26],
        'Arachnida' => [27, 28],
        'Araneae' => [27],
        'Scorpiones' => [28],
        'Actinopterygii' => [29],
        'Elasmobranchii' => [29],
        'Chondrichthyes' => [29],
        'Malacostraca' => [30],
        'Crustacea' => [30, 33],
        'Mollusca' => [31, 32],
        'Gastropoda' => [31],
        'Bivalvia' => [32],
        'Branchiopoda' => [33],
        'Dermaptera' => [43],
        'Formicidae' => [51],
    ];

    /** @return list<array{id: int, label: string}> */
    public function all(): array
    {
        return array_map(
            static fn (int $id, string $label): array => ['id' => $id, 'label' => $label],
            array_keys(self::GROUPS),
            array_values(self::GROUPS),
        );
    }

    /** @return list<array{id: int, label: string}> */
    public function forTaxon(?Taxon $taxon): array
    {
        if ($taxon === null) {
            return $this->all();
        }

        $scientificName = $taxon->accepted_scientific_name ?: $taxon->scientific_name;
        $ids = self::TAXREF_GROUPS[$scientificName] ?? [];

        return array_values(array_map(
            static fn (int $id): array => ['id' => $id, 'label' => self::GROUPS[$id]],
            $ids,
        ));
    }

    public function supports(Taxon $taxon): bool
    {
        return $this->forTaxon($taxon) !== [];
    }

    public function label(int $id): ?string
    {
        return self::GROUPS[$id] ?? null;
    }
}
