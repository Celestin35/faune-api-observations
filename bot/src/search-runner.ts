import { mkdir, writeFile } from "node:fs/promises";
import path from "node:path";
import { firefox } from "playwright";
import { OUTPUT_DIR, PROFILE_DIR } from "./config.js";
import {
  assertLiveAuthenticatedSession,
  assertSuccessfulResponse,
  buildSearchParameters,
  decidePagination,
  parseResultsResponse,
  postFromPage,
  RESULTS_URL,
  SEARCH_URL,
  SessionExpiredError
} from "./faune-france.js";
import { FauneFranceAuthenticator } from "./faune-france-auth.js";
import type { SearchJob } from "./job.js";
import { analyzeRawEntry } from "./raw-analysis.js";
import type { Page } from "playwright";

function timestampForPath(): string {
  return new Date().toISOString().replace(/[:.]/g, "-");
}

function pause(durationMs: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, durationMs));
}

export interface SearchRunResult {
  runDirectory: string;
  combinedPath: string;
  totalPages: number;
  entries: number;
  truncatedBySafetyLimit: boolean;
  stopReason: string | null;
  observations: unknown[];
}

interface SearchAttemptResult {
  initialization: Awaited<ReturnType<typeof postFromPage>>;
  combinedData: unknown[];
  pages: Array<{
    page: number;
    entries: number;
    dataIsFinished: { value: boolean | number | string; type: "boolean" | "number" | "string"; finished: boolean };
    repeated: boolean;
  }>;
  rawPages: Array<{ page: number; body: string }>;
  truncatedBySafetyLimit: boolean;
  stopReason: string | null;
}

export async function runWithAuthenticationRetry<T>(
  ensureAuthenticated: () => Promise<void>,
  executeSearch: () => Promise<T>
): Promise<T> {
  await ensureAuthenticated();
  try {
    return await executeSearch();
  } catch (error) {
    if (!(error instanceof SessionExpiredError)) {
      throw error;
    }
    await ensureAuthenticated();
    return executeSearch();
  }
}

async function performSearchAttempt(page: Page, job: SearchJob): Promise<SearchAttemptResult> {
  console.log("Initialisation de la recherche via m_id=94…");
  const initialization = await postFromPage(page, SEARCH_URL, buildSearchParameters(job, 1), false);
  assertSuccessfulResponse(initialization, "Initialisation m_id=94");
  await assertLiveAuthenticatedSession(page, "Initialisation m_id=94");
  console.log(`Initialisation réussie : HTTP ${initialization.status}, ${initialization.contentType || "type inconnu"}.`);

  const combinedData: unknown[] = [];
  const pages: SearchAttemptResult["pages"] = [];
  const rawPages: SearchAttemptResult["rawPages"] = [];
  let previousFingerprint: string | null = null;
  let truncatedBySafetyLimit = false;
  let stopReason: string | null = null;

  for (let pageNumber = 1; pageNumber <= job.maxPages; pageNumber += 1) {
    console.log(`Récupération de la page ${pageNumber}/${job.maxPages} via m_id=1351…`);
    const response = await postFromPage(page, RESULTS_URL, buildSearchParameters(job, pageNumber), true);
    await assertLiveAuthenticatedSession(page, `Résultats page ${pageNumber}`);
    const payload = parseResultsResponse(response, pageNumber);
    const decision = decidePagination(payload, pageNumber, job.maxPages, previousFingerprint);

    rawPages.push({ page: pageNumber, body: response.body });
    pages.push({
      page: pageNumber,
      entries: payload.data.length,
      dataIsFinished: decision.dataIsFinished,
      repeated: decision.repeated
    });
    if (!decision.repeated) {
      combinedData.push(...payload.data);
    }
    console.log(`Page ${pageNumber} reçue : ${payload.data.length} entrée(s), data_is_finished=${JSON.stringify(decision.dataIsFinished.value)} (${decision.dataIsFinished.type}).`);

    if (!decision.continue) {
      stopReason = decision.stopReason;
      truncatedBySafetyLimit = decision.truncatedBySafetyLimit;
      break;
    }
    previousFingerprint = decision.fingerprint;
    console.log(`Pause de ${job.pagePauseMs} ms avant la page suivante…`);
    await pause(job.pagePauseMs);
  }

  return { initialization, combinedData, pages, rawPages, truncatedBySafetyLimit, stopReason };
}

export async function runSearchJob(job: SearchJob, headless = true): Promise<SearchRunResult> {
  await mkdir(PROFILE_DIR, { recursive: true });
  const jobOutputDirectory = path.join(OUTPUT_DIR, job.jobId);
  await mkdir(jobOutputDirectory, { recursive: true });
  const runDirectory = path.join(jobOutputDirectory, timestampForPath());
  await mkdir(runDirectory, { recursive: false });

  console.log(`Tâche : ${job.jobId}`);
  console.log(`Profil réutilisé : ${PROFILE_DIR}`);
  console.log(`Sorties de ce lancement : ${runDirectory}`);
  console.log(`Recherche : ${job.taxon.vernacularName} (${job.taxon.scientificName}, sp_S=${job.taxon.fauneFranceId}), ${job.dateFrom} → ${job.dateTo}, départements ${job.departments.join(", ")}.`);

  const context = await firefox.launchPersistentContext(PROFILE_DIR, { headless });
  try {
    const page = context.pages()[0] ?? await context.newPage();
    const authenticator = new FauneFranceAuthenticator();
    const attempt = await runWithAuthenticationRetry(
      () => authenticator.ensureAuthenticated(page),
      () => performSearchAttempt(page, job)
    );
    const { initialization, combinedData, pages, rawPages, truncatedBySafetyLimit, stopReason } = attempt;

    for (const rawPage of rawPages) {
      await writeFile(path.join(runDirectory, `page-${rawPage.page}.raw.json`), rawPage.body, "utf8");
    }

    const combinedPath = path.join(runDirectory, "combined-data.json");
    await writeFile(combinedPath, `${JSON.stringify(combinedData, null, 2)}\n`, "utf8");

    const analysis = analyzeRawEntry(combinedData[0]);
    const summary = {
      jobId: job.jobId,
      searchedAt: new Date().toISOString(),
      taxon: job.taxon,
      filters: {
        dateFrom: job.dateFrom,
        dateTo: job.dateTo,
        departments: job.departments,
        maxPages: job.maxPages,
        pagePauseMs: job.pagePauseMs
      },
      initialization: { ok: true, status: initialization.status, url: initialization.url, contentType: initialization.contentType },
      results: {
        totalPages: pages.length,
        entries: combinedData.length,
        truncatedBySafetyLimit,
        stopReason,
        pages
      },
      firstEntry: analysis
    };
    await writeFile(path.join(runDirectory, "run-summary.json"), `${JSON.stringify(summary, null, 2)}\n`, "utf8");

    console.log(`Terminé : ${pages.length} page(s), ${combinedData.length} entrée(s) brutes, arrêt=${stopReason}.`);
    console.log(`Résultats tronqués par la limite de sécurité : ${truncatedBySafetyLimit ? "oui" : "non"}.`);
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

    return {
      runDirectory,
      combinedPath,
      totalPages: pages.length,
      entries: combinedData.length,
      truncatedBySafetyLimit,
      stopReason,
      observations: combinedData
    };
  } finally {
    await context.close();
  }
}

export function reportSearchError(error: unknown): void {
  if (error instanceof SessionExpiredError) {
    console.error(`Erreur de session : ${error.message}`);
  } else {
    console.error(error instanceof Error ? `Erreur : ${error.message}` : "Erreur inconnue pendant la recherche.");
  }
  process.exitCode = 1;
}
