import type { Page } from "playwright";

export const TAXON_CATALOG_URL = "https://www.faune-france.org/index.php?m_id=8";

export interface FauneFranceTaxonCatalogEntry {
  fauneFranceId: string;
  scientificName: string;
  vernacularName: string | null;
  taxonomicGroupId: number;
  selectable: boolean;
  order: number | null;
  category: unknown;
}

export interface FauneFranceTaxonCatalog {
  schemaVersion: 1;
  source: "faune_france";
  sourceUrl: string;
  exportedAt: string;
  sourceLastUpdateTimestamp: number | null;
  entries: FauneFranceTaxonCatalogEntry[];
}

interface IndexedDbSpecies {
  id?: unknown;
  lat?: unknown;
  sp?: unknown;
  idtg?: unknown;
  v?: unknown;
  order?: unknown;
  cat?: unknown;
}

export function normalizeCatalogEntries(rows: IndexedDbSpecies[]): FauneFranceTaxonCatalogEntry[] {
  if (!Array.isArray(rows)) {
    throw new Error("Le catalogue Faune-France doit être un tableau.");
  }

  const ids = new Set<string>();
  return rows.map((row, index) => {
    const id = typeof row.id === "number" && Number.isInteger(row.id) && row.id > 0 ? String(row.id) : "";
    const scientificName = typeof row.lat === "string" ? row.lat.trim() : "";
    const taxonomicGroupId = typeof row.idtg === "number" && Number.isInteger(row.idtg) && row.idtg > 0 ? row.idtg : 0;
    if (!id || !scientificName || !taxonomicGroupId) {
      throw new Error(`Entrée Faune-France invalide à l’index ${index}.`);
    }
    if (ids.has(id)) {
      throw new Error(`Identifiant Faune-France dupliqué : ${id}.`);
    }
    ids.add(id);

    const vernacularName = typeof row.sp === "string" && row.sp.trim() !== "" ? row.sp.trim() : null;
    return {
      fauneFranceId: id,
      scientificName,
      vernacularName,
      taxonomicGroupId,
      selectable: row.v === true,
      order: typeof row.order === "number" && Number.isInteger(row.order) ? row.order : null,
      category: row.cat ?? null
    };
  });
}

export async function readFauneFranceTaxonCatalog(page: Page): Promise<FauneFranceTaxonCatalog> {
  const loadPage = async (): Promise<void> => {
    await page.goto(TAXON_CATALOG_URL, { waitUntil: "networkidle", timeout: 60_000 });
    await page.waitForTimeout(3_000);
  };
  await loadPage();

  const readRaw = async (): Promise<{ rows: IndexedDbSpecies[]; sourceLastUpdateTimestamp: number | null } | null> => page.evaluate(async () => {
    const databases = await indexedDB.databases();
    if (!databases.some((database) => database.name === "Species")) return null;
    const rows = await new Promise<IndexedDbSpecies[] | null>((resolve, reject) => {
      const request = indexedDB.open("Species");
      request.onerror = () => reject(request.error);
      request.onsuccess = () => {
        const database = request.result;
        if (!database.objectStoreNames.contains("species")) {
          database.close();
          resolve(null);
          return;
        }
        const entriesRequest = database.transaction("species", "readonly").objectStore("species").getAll();
        entriesRequest.onerror = () => { database.close(); reject(entriesRequest.error); };
        entriesRequest.onsuccess = () => { database.close(); resolve(entriesRequest.result as IndexedDbSpecies[]); };
      };
    });
    if (rows === null) return null;

    let sourceLastUpdateTimestamp: number | null = null;
    const script = [...document.scripts].find((element) => (element.textContent ?? "").includes("new SpeciesSelectorComponent"));
    const source = script?.textContent ?? "";
    const prefix = "const dto = ";
    const start = source.indexOf(prefix);
    if (start >= 0) {
      const jsonStart = start + prefix.length;
      const jsonEnd = source.indexOf("\n", jsonStart);
      try {
        const dto = JSON.parse(source.slice(jsonStart, jsonEnd).trim()) as { speciesMetadata?: { lastUpdateTimestamp?: unknown } };
        if (typeof dto.speciesMetadata?.lastUpdateTimestamp === "number") {
          sourceLastUpdateTimestamp = dto.speciesMetadata.lastUpdateTimestamp;
        }
      } catch {
        // Le catalogue reste exploitable ; seule sa version source sera absente.
      }
    }
    return { rows, sourceLastUpdateTimestamp };
  });

  let raw = await readRaw();
  if (raw === null || raw.rows.length === 0) {
    // Répare un cache interrompu avant la création des object stores, puis laisse le composant officiel le reconstruire.
    await page.goto("https://www.faune-france.org/", { waitUntil: "domcontentloaded", timeout: 60_000 });
    await page.evaluate(async () => {
      const databases = await indexedDB.databases();
      if (!databases.some((database) => database.name === "Species")) return;
      await new Promise<void>((resolve, reject) => {
        const request = indexedDB.deleteDatabase("Species");
        request.onerror = () => reject(request.error);
        request.onblocked = () => reject(new Error("La base Species est encore utilisée par une page Faune-France."));
        request.onsuccess = () => resolve();
      });
    });
    await loadPage();
    raw = await readRaw();
  }
  if (raw === null || raw.rows.length === 0) {
    throw new Error("Faune-France n’a pas initialisé son catalogue IndexedDB Species.");
  }

  const entries = normalizeCatalogEntries(raw.rows);
  if (entries.length < 1_000) {
    throw new Error(`Le catalogue Faune-France paraît incomplet (${entries.length} entrées).`);
  }

  return {
    schemaVersion: 1,
    source: "faune_france",
    sourceUrl: TAXON_CATALOG_URL,
    exportedAt: new Date().toISOString(),
    sourceLastUpdateTimestamp: raw.sourceLastUpdateTimestamp,
    entries
  };
}
