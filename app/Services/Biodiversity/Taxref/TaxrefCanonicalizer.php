<?php

namespace App\Services\Biodiversity\Taxref;

use App\Models\Taxon;
use App\Models\TaxonomicReferenceVersion;
use App\Models\TaxrefRecord;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class TaxrefCanonicalizer
{
    public function __construct(private readonly LocalTaxaDecisionReader $decisionReader) {}

    /** @return array<string, int|float> */
    public function canonicalize(TaxonomicReferenceVersion $version, string $decisionFile): array
    {
        $started = microtime(true);
        if ($version->status !== TaxonomicReferenceVersion::STATUS_STAGING) {
            throw new RuntimeException("La version {$version->version} doit être en staging.");
        }
        $records = TaxrefRecord::query()->where('taxonomic_reference_version_id', $version->id)->count();
        $accepted = TaxrefRecord::query()->where('taxonomic_reference_version_id', $version->id)
            ->where('name_status', TaxrefRecord::STATUS_ACCEPTED)->count();
        if ($version->version === '18' && ($records !== 708685 || $accepted !== 300377)) {
            throw new RuntimeException("TAXREF v18 incomplet : {$records} lignes et {$accepted} concepts acceptés.");
        }

        $decisions = $this->decisionReader->read($decisionFile, $version);
        $historicalIds = array_column($decisions, 'local_taxon_id');
        $historicalObservationLinks = DB::table('observations')->whereIn('taxon_id', $historicalIds)
            ->orderBy('id')->pluck('taxon_id', 'id')->all();
        $historicalMappingIds = DB::table('taxon_source_mappings')->whereIn('taxon_id', $historicalIds)
            ->orderBy('id')->pluck('taxon_id', 'id')->all();

        DB::transaction(function () use ($decisions, $version): void {
            foreach ($decisions as $decision) {
                $status = match ($decision['decision']) {
                    'map_taxref' => 'canonical',
                    'keep_local_outside_taxref' => 'local_outside_taxref',
                    'keep_local_provisional' => 'local_provisional',
                    'ignore_unused_candidate' => 'ignored_candidate',
                    default => 'local_unresolved',
                };
                $attributes = ['taxonomic_status' => $status];
                if ($decision['decision'] === 'map_taxref') {
                    $record = TaxrefRecord::query()->where('taxonomic_reference_version_id', $version->id)
                        ->where('name_status', TaxrefRecord::STATUS_ACCEPTED)
                        ->where('cd_ref', $decision['taxref_cd_ref'])->firstOrFail();
                    $attributes += $this->taxonAttributes($record, $version);
                }
                Taxon::query()->whereKey($decision['local_taxon_id'])->update($attributes);
            }
        });

        if (DB::getDriverName() === 'pgsql') {
            $this->canonicalizePostgresql($version);
        } else {
            $this->canonicalizePortable($version);
        }

        $canonical = Taxon::query()->where('taxref_version_id', $version->id)->count();
        $distinct = Taxon::query()->where('taxref_version_id', $version->id)->distinct('taxref_cd_ref')->count('taxref_cd_ref');
        $linkedAccepted = TaxrefRecord::query()->where('taxonomic_reference_version_id', $version->id)
            ->where('name_status', TaxrefRecord::STATUS_ACCEPTED)->whereNotNull('taxon_id')->count();
        $linkedRecords = TaxrefRecord::query()->where('taxonomic_reference_version_id', $version->id)->whereNotNull('taxon_id')->count();
        if ($canonical !== $accepted || $distinct !== $accepted || $linkedAccepted !== $accepted || $linkedRecords !== $records) {
            throw new RuntimeException("Canonicalisation incomplète : taxa={$canonical}, distinct={$distinct}, acceptés liés={$linkedAccepted}, lignes liées={$linkedRecords}.");
        }
        if ($historicalObservationLinks !== DB::table('observations')->whereIn('taxon_id', $historicalIds)->orderBy('id')->pluck('taxon_id', 'id')->all()) {
            throw new RuntimeException('Les rattachements historiques des observations ont changé.');
        }
        if ($historicalMappingIds !== DB::table('taxon_source_mappings')->whereIn('taxon_id', $historicalIds)->orderBy('id')->pluck('taxon_id', 'id')->all()) {
            throw new RuntimeException('Les rattachements historiques des mappings ont changé.');
        }

        return [
            'records' => $records,
            'accepted_concepts' => $accepted,
            'canonical_taxa' => $canonical,
            'linked_records' => $linkedRecords,
            'historical_taxa' => count($historicalIds),
            'local_outside_taxref' => Taxon::query()->whereIn('taxonomic_status', ['local_outside_taxref', 'local_provisional', 'local_unresolved'])->count(),
            'missing_parents' => Taxon::query()->where('taxref_version_id', $version->id)->whereNull('parent_id')
                ->whereHas('currentTaxrefRecord', fn ($query) => $query->whereNotNull('parent_cd_ref'))->count(),
            'duration_seconds' => round(microtime(true) - $started, 3),
        ];
    }

    private function canonicalizePostgresql(TaxonomicReferenceVersion $version): void
    {
        $id = (int) $version->id;
        DB::statement(<<<'SQL'
            INSERT INTO taxa (
                taxref_version_id, taxref_cd_ref, rank_code, scientific_name, vernacular_name, rank,
                classification, accepted_scientific_name, authorship, status, taxonomic_status,
                current_taxref_record_id, created_at, updated_at
            )
            SELECT ?, r.cd_ref, r.rank_code, r.scientific_name, NULL,
                coalesce(r.rank_code, nullif(r.raw_data->>'RANG', '')),
                jsonb_strip_nulls(jsonb_build_object(
                    'kingdom', nullif(r.raw_data->>'REGNE', ''), 'phylum', nullif(r.raw_data->>'PHYLUM', ''),
                    'class', nullif(r.raw_data->>'CLASSE', ''), 'order', nullif(r.raw_data->>'ORDRE', ''),
                    'family', nullif(r.raw_data->>'FAMILLE', ''), 'subfamily', nullif(r.raw_data->>'SOUS_FAMILLE', ''),
                    'tribe', nullif(r.raw_data->>'TRIBU', '')
                )), r.scientific_name, r.authorship, 'active', 'canonical', r.id, now(), now()
            FROM taxref_records r
            WHERE r.taxonomic_reference_version_id = ? AND r.name_status = 'accepted'
              AND NOT EXISTS (
                SELECT 1 FROM taxa t WHERE t.taxref_version_id = ? AND t.taxref_cd_ref = r.cd_ref
              )
            ON CONFLICT DO NOTHING
            SQL, [$id, $id, $id]);

        DB::statement(<<<'SQL'
            UPDATE taxref_records r SET taxon_id = t.id, updated_at = now()
            FROM taxa t
            WHERE r.taxonomic_reference_version_id = ?
              AND t.taxref_version_id = ? AND t.taxref_cd_ref = r.cd_ref
              AND r.taxon_id IS DISTINCT FROM t.id
            SQL, [$id, $id]);

        DB::statement(<<<'SQL'
            UPDATE taxa t SET parent_id = hierarchy.parent_taxon_id, updated_at = now()
            FROM (
                SELECT child.cd_ref, parent_taxon.id AS parent_taxon_id
                FROM taxref_records child
                LEFT JOIN taxref_records parent_record
                  ON parent_record.taxonomic_reference_version_id = child.taxonomic_reference_version_id
                 AND parent_record.cd_nom = child.parent_cd_ref
                LEFT JOIN taxa parent_taxon
                  ON parent_taxon.taxref_version_id = child.taxonomic_reference_version_id
                 AND parent_taxon.taxref_cd_ref = parent_record.cd_ref
                WHERE child.taxonomic_reference_version_id = ? AND child.name_status = 'accepted'
            ) hierarchy
            WHERE t.taxref_version_id = ? AND t.taxref_cd_ref = hierarchy.cd_ref
              AND t.parent_id IS DISTINCT FROM hierarchy.parent_taxon_id
            SQL, [$id, $id]);

        DB::statement(<<<'SQL'
            UPDATE taxon_source_mappings m SET
                source_scientific_name = coalesce(m.source_scientific_name, t.scientific_name),
                source_rank = coalesce(m.source_rank, t.rank), updated_at = now()
            FROM taxa t WHERE t.id = m.taxon_id
            SQL);
        $this->backfillBusinessObjects($version);
    }

    private function canonicalizePortable(TaxonomicReferenceVersion $version): void
    {
        TaxrefRecord::query()->where('taxonomic_reference_version_id', $version->id)
            ->where('name_status', TaxrefRecord::STATUS_ACCEPTED)->orderBy('id')->chunkById(500, function ($records) use ($version): void {
                foreach ($records as $record) {
                    Taxon::query()->firstOrCreate(
                        ['taxref_version_id' => $version->id, 'taxref_cd_ref' => $record->cd_ref],
                        $this->taxonAttributes($record, $version),
                    );
                }
            });
        TaxrefRecord::query()->where('taxonomic_reference_version_id', $version->id)->orderBy('id')->chunkById(500, function ($records) use ($version): void {
            $taxa = Taxon::query()->where('taxref_version_id', $version->id)
                ->whereIn('taxref_cd_ref', $records->pluck('cd_ref'))->pluck('id', 'taxref_cd_ref');
            foreach ($records as $record) {
                $record->update(['taxon_id' => $taxa[$record->cd_ref]]);
            }
        });
        $acceptedByCdNom = TaxrefRecord::query()->where('taxonomic_reference_version_id', $version->id)
            ->pluck('cd_ref', 'cd_nom');
        $taxaByCdRef = Taxon::query()->where('taxref_version_id', $version->id)->pluck('id', 'taxref_cd_ref');
        TaxrefRecord::query()->where('taxonomic_reference_version_id', $version->id)
            ->where('name_status', TaxrefRecord::STATUS_ACCEPTED)->each(function ($record) use ($acceptedByCdNom, $taxaByCdRef): void {
                $parentCdRef = $acceptedByCdNom[$record->parent_cd_ref] ?? null;
                Taxon::query()->where('taxref_cd_ref', $record->cd_ref)->update(['parent_id' => $parentCdRef ? ($taxaByCdRef[$parentCdRef] ?? null) : null]);
            });
        $this->backfillBusinessObjects($version);
    }

    private function backfillBusinessObjects(TaxonomicReferenceVersion $version): void
    {
        foreach (['monitoring_rules', 'data_collections', 'collection_coverages', 'import_jobs'] as $table) {
            DB::table($table)->whereIn('taxon_id', Taxon::query()->where('taxref_version_id', $version->id)->select('id'))
                ->update(['taxonomic_reference_version_id' => $version->id]);
        }
        DB::table('monitoring_rule_taxa')
            ->whereIn('taxon_id', Taxon::query()->where('taxref_version_id', $version->id)->select('id'))
            ->update(['taxonomic_reference_version_id' => $version->id]);
    }

    /** @return array<string, mixed> */
    private function taxonAttributes(TaxrefRecord $record, TaxonomicReferenceVersion $version): array
    {
        $raw = $record->raw_data ?? [];
        $classification = array_filter([
            'kingdom' => $raw['REGNE'] ?? null, 'phylum' => $raw['PHYLUM'] ?? null,
            'class' => $raw['CLASSE'] ?? null, 'order' => $raw['ORDRE'] ?? null,
            'family' => $raw['FAMILLE'] ?? null, 'subfamily' => $raw['SOUS_FAMILLE'] ?? null,
            'tribe' => $raw['TRIBU'] ?? null,
        ]);

        return [
            'taxref_version_id' => $version->id,
            'taxref_cd_ref' => $record->cd_ref,
            'rank_code' => $record->rank_code,
            'scientific_name' => $record->scientific_name,
            'rank' => $record->rank_code ?: ($raw['RANG'] ?? null),
            'classification' => $classification,
            'accepted_scientific_name' => $record->scientific_name,
            'authorship' => $record->authorship,
            'status' => 'active',
            'taxonomic_status' => 'canonical',
            'current_taxref_record_id' => $record->id,
        ];
    }
}
