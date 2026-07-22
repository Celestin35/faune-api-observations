import { mkdir, writeFile } from "node:fs/promises";
import path from "node:path";
import { firefox } from "playwright";
import { loadConfig, OUTPUT_DIR, PROFILE_DIR } from "./config.js";
import {
  assertSuccessfulResponse,
  BASE_URL,
  buildSearchParameters,
  hasNextPage,
  MAX_PAGES,
  pageClearlyShowsLogin,
  pageShowsAuthenticatedSession,
  parseResultsResponse,
  postFromPage,
  RESULTS_URL,
  SEARCH_URL,
  SessionExpiredError
} from "./faune-france.js";
import { analyzeRawEntry } from "./raw-analysis.js";

function timestampForPath(): string {
  return new Date().toISOString().replace(/[:.]/g, "-");
}

function pause(durationMs: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, durationMs));
}

async function main(): Promise<void> {
  const config = await loadConfig();
  await mkdir(PROFILE_DIR, { recursive: true });
  await mkdir(OUTPUT_DIR, { recursive: true });
  const runDirectory = path.join(OUTPUT_DIR, timestampForPath());
  await mkdir(runDirectory, { recursive: false });

  console.log(`Profil réutilisé : ${PROFILE_DIR}`);
  console.log(`Sorties de ce lancement : ${runDirectory}`);
  console.log(`Recherche : Tichodrome échelette (sp_S=383), ${config.dateFrom} → ${config.dateTo}, départements ${config.departments.join(", ")}.`);

  const context = await firefox.launchPersistentContext(PROFILE_DIR, { headless: config.headless });
  try {
    const page = context.pages()[0] ?? await context.newPage();
    await page.goto(BASE_URL, { waitUntil: "domcontentloaded", timeout: 45_000 });
    if (await pageClearlyShowsLogin(page)) {
      throw new SessionExpiredError("contrôle de la page d’accueil");
    }
    if (!await pageShowsAuthenticatedSession(page)) {
      throw new SessionExpiredError("aucun marqueur de déconnexion détecté dans le profil dédié");
    }

    console.log("Initialisation de la recherche via m_id=94…");
    const initialization = await postFromPage(page, SEARCH_URL, buildSearchParameters(config, 1), false);
    assertSuccessfulResponse(initialization, "Initialisation m_id=94");
    console.log(`Initialisation réussie : HTTP ${initialization.status}, ${initialization.contentType || "type inconnu"}.`);

    const combinedData: unknown[] = [];
    let pagesFetched = 0;
    for (let pageNumber = 1; pageNumber <= MAX_PAGES; pageNumber += 1) {
      console.log(`Récupération de la page ${pageNumber}/${MAX_PAGES} via m_id=1351…`);
      const response = await postFromPage(page, RESULTS_URL, buildSearchParameters(config, pageNumber), true);
      const payload = parseResultsResponse(response, pageNumber);

      const rawPath = path.join(runDirectory, `page-${pageNumber}.raw.json`);
      await writeFile(rawPath, response.body, "utf8");
      combinedData.push(...payload.data);
      pagesFetched += 1;
      console.log(`Page ${pageNumber} sauvegardée : ${payload.data.length} entrée(s).`);

      if (payload.data.length === 0 || pageNumber === MAX_PAGES || !hasNextPage(payload, pageNumber)) {
        break;
      }
      console.log(`Pause de ${config.pagePauseMs} ms avant la page suivante…`);
      await pause(config.pagePauseMs);
    }

    const combinedPath = path.join(runDirectory, "combined-data.json");
    await writeFile(combinedPath, `${JSON.stringify(combinedData, null, 2)}\n`, "utf8");

    const analysis = analyzeRawEntry(combinedData[0]);
    const summary = {
      searchedAt: new Date().toISOString(),
      species: { id: "383", name: "Tichodrome échelette", scientificName: "Tichodroma muraria" },
      filters: config,
      initialization: { ok: true, status: initialization.status, url: initialization.url, contentType: initialization.contentType },
      results: { pagesFetched, entries: combinedData.length },
      firstEntry: analysis
    };
    await writeFile(path.join(runDirectory, "run-summary.json"), `${JSON.stringify(summary, null, 2)}\n`, "utf8");

    console.log(`Terminé : ${pagesFetched} page(s), ${combinedData.length} entrée(s) brutes.`);
    console.log(`Données combinées : ${combinedPath}`);
    if (combinedData.length === 0) {
      console.log("Aucune entrée brute : la structure et les coordonnées ne peuvent pas être déterminées sur cette recherche.");
    } else {
      console.log(`Coordonnées détectées dans la première entrée : ${analysis.coordinatesDetected ? "oui" : "non"}.`);
      if (analysis.coordinatePaths.length > 0) {
        console.log(`Chemins possibles : ${analysis.coordinatePaths.join(", ")}`);
      }
      console.log(`Structure de la première entrée :\n${JSON.stringify(analysis.structure, null, 2)}`);
    }
  } finally {
    await context.close();
  }
}

main().catch((error: unknown) => {
  if (error instanceof SessionExpiredError) {
    console.error(`Erreur de session : ${error.message}`);
  } else {
    console.error(error instanceof Error ? `Erreur : ${error.message}` : "Erreur inconnue pendant la recherche.");
  }
  process.exitCode = 1;
});
