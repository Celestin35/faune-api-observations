<?php

namespace App\Services\Biodiversity\Taxref;

use App\Models\TaxonName;
use App\Models\TaxonomicReferenceVersion;
use App\Models\TaxrefRecord;
use App\Services\Biodiversity\TaxonNameNormalizer;
use App\Services\Biodiversity\TaxrefVernacularNameExtractor;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class TaxrefNameBuilder
{
    private const BATCH_SIZE = 1000;

    public function __construct(
        private readonly TaxonNameNormalizer $normalizer,
        private readonly TaxrefVernacularNameExtractor $vernacularExtractor,
    ) {}

    /** @return array<string, int|float> */
    public function build(TaxonomicReferenceVersion $version): array
    {
        $started = microtime(true);
        $batch = [];
        $currentCdRef = null;
        $group = [];
        $records = TaxrefRecord::query()->where('taxonomic_reference_version_id', $version->id)
            ->whereNotNull('taxon_id')->select(['id', 'taxon_id', 'cd_ref', 'name_status', 'scientific_name', 'authorship', 'raw_data'])
            ->orderBy('cd_ref')->orderByRaw("case when name_status='accepted' then 0 else 1 end")->orderBy('id')->cursor();
        foreach ($records as $record) {
            if ($currentCdRef !== null && (int) $record->cd_ref !== $currentCdRef) {
                $this->appendConcept($group, $batch, $version);
                $group = [];
            }
            $currentCdRef = (int) $record->cd_ref;
            $group[] = $record;
        }
        if ($group !== []) {
            $this->appendConcept($group, $batch, $version);
        }
        $this->flush($batch);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                UPDATE taxa t SET preferred_french_name = preferred.name,
                    vernacular_name = preferred.name, updated_at = now()
                FROM taxon_names preferred
                WHERE preferred.taxon_id = t.id AND preferred.name_type = 'vernacular'
                  AND preferred.language_code = 'fr' AND preferred.is_preferred
                  AND t.taxref_version_id = ?
                  AND (t.preferred_french_name IS DISTINCT FROM preferred.name OR t.vernacular_name IS DISTINCT FROM preferred.name)
                SQL, [$version->id]);
        } else {
            TaxonName::query()->where('taxonomic_reference_version_id', $version->id)
                ->where('name_type', TaxonName::TYPE_VERNACULAR)->where('is_preferred', true)
                ->each(fn (TaxonName $name) => DB::table('taxa')->where('id', $name->taxon_id)->update([
                    'preferred_french_name' => $name->name, 'vernacular_name' => $name->name, 'updated_at' => now(),
                ]));
        }

        $accepted = TaxonName::query()->where('taxonomic_reference_version_id', $version->id)
            ->where('name_type', TaxonName::TYPE_ACCEPTED_SCIENTIFIC)->count();
        $synonyms = TaxonName::query()->where('taxonomic_reference_version_id', $version->id)
            ->where('name_type', TaxonName::TYPE_SCIENTIFIC_SYNONYM)->count();
        $vernacular = TaxonName::query()->where('taxonomic_reference_version_id', $version->id)
            ->where('name_type', TaxonName::TYPE_VERNACULAR)->count();
        $concepts = TaxrefRecord::query()->where('taxonomic_reference_version_id', $version->id)
            ->where('name_status', TaxrefRecord::STATUS_ACCEPTED)->count();
        if ($accepted !== $concepts) {
            throw new RuntimeException("Noms acceptés incomplets : {$accepted}/{$concepts}.");
        }

        return [
            'accepted_scientific' => $accepted,
            'scientific_synonyms' => $synonyms,
            'vernacular_french' => $vernacular,
            'total' => $accepted + $synonyms + $vernacular,
            'preferred_french' => TaxonName::query()->where('taxonomic_reference_version_id', $version->id)
                ->where('name_type', TaxonName::TYPE_VERNACULAR)->where('is_preferred', true)->count(),
            'duration_seconds' => round(microtime(true) - $started, 3),
        ];
    }

    /** @param list<TaxrefRecord> $records @param list<array<string, mixed>> $batch */
    private function appendConcept(array $records, array &$batch, TaxonomicReferenceVersion $version): void
    {
        $accepted = collect($records)->firstWhere('name_status', TaxrefRecord::STATUS_ACCEPTED);
        if (! $accepted instanceof TaxrefRecord) {
            throw new RuntimeException('Concept sans enregistrement accepté : '.$records[0]->cd_ref);
        }
        $seenScientific = [];
        $seenVernacular = [];
        $this->append($batch, $this->row(
            $accepted, $version, $accepted->scientific_name, TaxonName::TYPE_ACCEPTED_SCIENTIFIC, 'la', true,
        ));
        $hasPreferredFrench = false;
        foreach ($records as $record) {
            if ($record->name_status === TaxrefRecord::STATUS_SYNONYM) {
                $key = $this->normalizer->normalize($record->scientific_name);
                if ($key !== '' && ! isset($seenScientific[$key])) {
                    $seenScientific[$key] = true;
                    $this->append($batch, $this->row(
                        $record, $version, $record->scientific_name, TaxonName::TYPE_SCIENTIFIC_SYNONYM, 'la', false,
                    ));
                }
            }
            $raw = $record->raw_data ?? [];
            foreach ($this->vernacularExtractor->extract($raw['NOM_VERN'] ?? null, $accepted->scientific_name) as $name) {
                $key = $this->normalizer->normalize($name);
                if ($key === '' || isset($seenVernacular[$key])) {
                    continue;
                }
                $seenVernacular[$key] = true;
                $preferred = ! $hasPreferredFrench;
                $hasPreferredFrench = true;
                $this->append($batch, $this->row(
                    $record, $version, $name, TaxonName::TYPE_VERNACULAR, 'fr', $preferred,
                ));
            }
        }
    }

    /** @return array<string, mixed> */
    private function row(TaxrefRecord $record, TaxonomicReferenceVersion $version, string $name, string $type, string $language, bool $preferred): array
    {
        return [
            'taxon_id' => $record->taxon_id,
            'taxonomic_reference_version_id' => $version->id,
            'taxref_record_id' => $record->id,
            'name' => $name,
            'normalized_name' => $this->normalizer->normalize($name),
            'name_type' => $type,
            'language_code' => $language,
            'authorship' => $type === TaxonName::TYPE_VERNACULAR ? null : $record->authorship,
            'is_preferred' => $preferred,
            'source' => 'taxref',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /** @param list<array<string, mixed>> $batch @param array<string, mixed> $row */
    private function append(array &$batch, array $row): void
    {
        $batch[] = $row;
        if (count($batch) >= self::BATCH_SIZE) {
            $this->flush($batch);
        }
    }

    /** @param list<array<string, mixed>> $batch */
    private function flush(array &$batch): void
    {
        if ($batch !== []) {
            DB::table('taxon_names')->insertOrIgnore($batch);
            $batch = [];
        }
    }
}
