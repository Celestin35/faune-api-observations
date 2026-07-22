<?php

namespace App\Services\Biodiversity\Taxref;

use App\Models\Taxon;
use App\Models\TaxonomicReferenceVersion;
use App\Models\TaxrefRecord;
use RuntimeException;

final class LocalTaxaDecisionReader
{
    public const DECISIONS = [
        'map_taxref',
        'keep_local_outside_taxref',
        'keep_local_provisional',
        'keep_local_unresolved',
        'ignore_unused_candidate',
    ];

    /** @return list<array{local_taxon_id:int,scientific_name:string,decision:string,taxref_cd_ref:?int,reason:string}> */
    public function read(string $file, TaxonomicReferenceVersion $version): array
    {
        if (! is_readable($file)) {
            throw new RuntimeException("Fichier de décisions illisible : {$file}");
        }
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Impossible d’ouvrir le fichier de décisions : {$file}");
        }
        try {
            $headers = fgetcsv($handle, null, ',', '"', '');
            if (is_array($headers) && isset($headers[0])) {
                $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]) ?? $headers[0];
            }
            $expected = ['local_taxon_id', 'scientific_name', 'decision', 'taxref_cd_ref', 'reason'];
            if ($headers !== $expected) {
                throw new RuntimeException('En-tête invalide dans le fichier de décisions locales.');
            }
            $rows = [];
            while (($values = fgetcsv($handle, null, ',', '"', '')) !== false) {
                if ($values === [null] || count($values) === 0) {
                    continue;
                }
                if (count($values) !== count($expected)) {
                    throw new RuntimeException('Une ligne du fichier de décisions ne contient pas cinq colonnes.');
                }
                $row = array_combine($expected, $values);
                $id = filter_var($row['local_taxon_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($id === false || isset($rows[$id])) {
                    throw new RuntimeException("Identifiant local invalide ou dupliqué : {$row['local_taxon_id']}");
                }
                $taxon = Taxon::query()->find($id);
                if ($taxon === null || $taxon->scientific_name !== trim($row['scientific_name'])) {
                    throw new RuntimeException("Le taxon local {$id} ou son nom ne correspond pas à la base.");
                }
                $decision = trim($row['decision']);
                if (! in_array($decision, self::DECISIONS, true)) {
                    throw new RuntimeException("Décision invalide pour le taxon {$id} : {$decision}");
                }
                $cdRef = trim($row['taxref_cd_ref']) === '' ? null : filter_var($row['taxref_cd_ref'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($decision === 'map_taxref') {
                    if ($cdRef === false || $cdRef === null || ! TaxrefRecord::query()
                        ->where('taxonomic_reference_version_id', $version->id)->where('name_status', 'accepted')->where('cd_ref', $cdRef)->exists()) {
                        throw new RuntimeException("CD_REF accepté absent ou invalide pour le taxon {$id}.");
                    }
                } elseif ($cdRef !== null) {
                    throw new RuntimeException("Un taxon non mappé ne doit pas recevoir de CD_REF : {$id}.");
                }
                if (trim($row['reason']) === '') {
                    throw new RuntimeException("La justification est obligatoire pour le taxon {$id}.");
                }
                if ($decision === 'ignore_unused_candidate' && $this->isUsed($taxon)) {
                    throw new RuntimeException("Le taxon {$id} est utilisé et ne peut pas être ignoré.");
                }
                $rows[$id] = [
                    'local_taxon_id' => $id,
                    'scientific_name' => trim($row['scientific_name']),
                    'decision' => $decision,
                    'taxref_cd_ref' => $cdRef === false ? null : $cdRef,
                    'reason' => trim($row['reason']),
                ];
            }
        } finally {
            fclose($handle);
        }

        $missing = Taxon::query()->whereNull('taxref_version_id')->whereNotIn('id', array_keys($rows))->pluck('id')->all();
        if ($missing !== []) {
            throw new RuntimeException('Taxons locaux sans décision : '.implode(', ', $missing).'.');
        }

        return array_values($rows);
    }

    private function isUsed(Taxon $taxon): bool
    {
        foreach (['observations', 'monitoring_rules', 'data_collections', 'collection_coverages', 'import_jobs'] as $table) {
            if (\DB::table($table)->where('taxon_id', $taxon->id)->exists()) {
                return true;
            }
        }

        return false;
    }
}
