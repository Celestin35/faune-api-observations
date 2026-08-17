<?php

namespace Tests\Feature;

use App\Models\Taxon;
use App\Models\TaxonName;
use App\Models\TaxonomicReferenceVersion;
use App\Models\TaxonSourceMapping;
use App\Services\Biodiversity\FauneFrance\FauneFranceTaxonCatalogImporter;
use App\Services\Biodiversity\TaxonNameNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class FauneFranceTaxonCatalogImporterTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_imports_exact_accepted_synonym_and_unique_vernacular_matches_and_keeps_one_preferred_mapping(): void
    {
        $version = TaxonomicReferenceVersion::create(['provider' => 'taxref', 'version' => '18', 'status' => 'active']);
        $crow = $this->taxon($version->id, 4503, 'Corvus corone');
        $this->createName($crow->id, $version->id, 'Corvus corone', TaxonName::TYPE_ACCEPTED_SCIENTIFIC);
        $this->createName($crow->id, $version->id, 'Corvus corone oldname', TaxonName::TYPE_SCIENTIFIC_SYNONYM);
        $this->createName($crow->id, $version->id, 'Corneille noire', TaxonName::TYPE_VERNACULAR);

        $result = app(FauneFranceTaxonCatalogImporter::class)->import($this->catalog([
            $this->entry('358', 'Corvus corone', 'Corneille noire'),
            $this->entry('999', 'Corvus corone oldname', null),
            $this->entry('1002', 'Corvus newname', 'Corneille noire'),
            $this->entry('1000', 'Taxon absent', null),
            $this->entry('1001', 'Taxon invisible', null, false),
        ]));

        self::assertSame(3, $result['summary']['matched']);
        self::assertSame(1, $result['summary']['matchedAcceptedNames']);
        self::assertSame(1, $result['summary']['matchedSynonyms']);
        self::assertSame(1, $result['summary']['matchedVernacularNames']);
        self::assertSame(1, $result['summary']['unmatched']);
        self::assertSame(1, $result['summary']['hiddenEntries']);
        self::assertDatabaseHas('taxon_source_mappings', [
            'taxon_id' => $crow->id, 'source' => 'faune_france', 'source_taxon_id' => '358',
            'mapping_status' => 'validated', 'match_type' => 'exact_accepted_name', 'is_preferred' => true,
        ]);
        self::assertDatabaseHas('taxon_source_mappings', [
            'taxon_id' => $crow->id, 'source_taxon_id' => '999', 'match_type' => 'exact_scientific_synonym', 'is_preferred' => false,
        ]);
        self::assertDatabaseHas('taxon_source_mappings', [
            'taxon_id' => $crow->id, 'source_taxon_id' => '1002', 'match_type' => 'exact_vernacular_name', 'is_preferred' => false,
        ]);
    }

    #[Test]
    public function it_reports_ambiguities_conflicts_and_supports_a_dry_run(): void
    {
        $version = TaxonomicReferenceVersion::create(['provider' => 'taxref', 'version' => '18', 'status' => 'active']);
        $first = $this->taxon($version->id, 1, 'Duplicata alpha');
        $second = $this->taxon($version->id, 2, 'Duplicata beta');
        $this->createName($first->id, $version->id, 'Nom ambigu', TaxonName::TYPE_SCIENTIFIC_SYNONYM);
        $this->createName($second->id, $version->id, 'Nom ambigu', TaxonName::TYPE_SCIENTIFIC_SYNONYM);
        $this->createName($second->id, $version->id, 'Duplicata beta', TaxonName::TYPE_ACCEPTED_SCIENTIFIC);
        TaxonSourceMapping::create([
            'taxon_id' => $first->id, 'source' => 'faune_france', 'source_taxon_id' => '20',
            'mapping_status' => 'validated', 'match_type' => 'legacy', 'is_preferred' => true, 'raw_data' => [],
        ]);

        $catalog = $this->catalog([
            $this->entry('10', 'Nom ambigu', null),
            $this->entry('20', 'Duplicata beta', null),
        ]);
        $result = app(FauneFranceTaxonCatalogImporter::class)->import($catalog, false);

        self::assertSame(1, $result['summary']['ambiguous']);
        self::assertSame(1, $result['summary']['conflicts']);
        self::assertSame(1, TaxonSourceMapping::count());
        self::assertSame('legacy', TaxonSourceMapping::firstOrFail()->match_type);
    }

    private function taxon(int $versionId, int $cdRef, string $name): Taxon
    {
        return Taxon::create([
            'taxref_version_id' => $versionId, 'taxref_cd_ref' => $cdRef,
            'scientific_name' => $name, 'accepted_scientific_name' => $name,
            'rank' => 'species', 'taxonomic_status' => 'canonical',
        ]);
    }

    private function createName(int $taxonId, int $versionId, string $name, string $type): void
    {
        TaxonName::create([
            'taxon_id' => $taxonId, 'taxonomic_reference_version_id' => $versionId,
            'name' => $name, 'normalized_name' => app(TaxonNameNormalizer::class)->normalize($name),
            'name_type' => $type, 'is_preferred' => $type === TaxonName::TYPE_ACCEPTED_SCIENTIFIC, 'source' => 'taxref',
        ]);
    }

    private function catalog(array $entries): array
    {
        return [
            'schemaVersion' => 1, 'source' => 'faune_france', 'sourceUrl' => 'https://www.faune-france.org/index.php?m_id=8',
            'exportedAt' => '2026-07-23T00:00:00Z', 'sourceLastUpdateTimestamp' => 1784722783, 'entries' => $entries,
        ];
    }

    private function entry(string $id, string $scientificName, ?string $vernacularName, bool $selectable = true): array
    {
        return [
            'fauneFranceId' => $id, 'scientificName' => $scientificName, 'vernacularName' => $vernacularName,
            'taxonomicGroupId' => 1, 'selectable' => $selectable, 'order' => 1, 'category' => null,
        ];
    }
}
