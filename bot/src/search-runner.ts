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

export interface SearchProgress {
  page: number;
  maxPages: number;
  entries: number;
}

export type SearchProgressReporter = (progress: SearchProgress) => Promise<void> | void;

interface SearchAttemptResult {
  initialization: Awaited<ReturnType<typeof postFromPage>>;
  combinedData: unknown[];
  pages: Array<{
    page: number;
    entries: number;
    dataIsFinished: { value: boolean | number | string; type: "boolean" | "number" | "string"; finished: boolean };
    repeated: boolean;
  }>;
  truncatedBySafetyLimit: boolean;
  stopReason: string | null;
}

export interface SearchDateChunk {
  dateFrom: string;
  dateTo: string;
}

const MAX_SEARCH_DAYS = 31;

function isoDate(date: Date): string {
  return date.toISOString().slice(0, 10);
}

export function splitDateRange(dateFrom: string, dateTo: string, maxDays = MAX_SEARCH_DAYS): SearchDateChunk[] {
  if (!Number.isInteger(maxDays) || maxDays < 1) {
    throw new Error("La durée maximale d’une période doit être un entier positif.");
  }

  const chunks: SearchDateChunk[] = [];
  const lastDate = new Date(`${dateTo}T00:00:00Z`);
  let cursor = new Date(`${dateFrom}T00:00:00Z`);
  while (cursor <= lastDate) {
    const end = new Date(cursor);
    end.setUTCDate(end.getUTCDate() + maxDays - 1);
    if (end > lastDate) end.setTime(lastDate.getTime());
    chunks.push({ dateFrom: isoDate(cursor), dateTo: isoDate(end) });
    cursor = new Date(end);
    cursor.setUTCDate(cursor.getUTCDate() + 1);
  }
  return chunks;
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

async function performSearchAttempt(
  page: Page,
  job: SearchJob,
  reportProgress?: SearchProgressReporter,
  artifactDirectory?: string
): Promise<SearchAttemptResult> {
  console.log("Initialisation de la recherche via m_id=94…");
  const initialization = await postFromPage(page, SEARCH_URL, buildSearchParameters(job, 1), false);
  assertSuccessfulResponse(initialization, "Initialisation m_id=94");
  await assertLiveAuthenticatedSession(page, "Initialisation m_id=94");
  console.log(`Initialisation réussie : HTTP ${initialization.status}, ${initialization.contentType || "type inconnu"}.`);
  const initializationPauseMs = Math.max(1_500, job.pagePauseMs);
  console.log(`Pause de ${initializationPauseMs} ms pour laisser Faune-France préparer les résultats…`);
  await pause(initializationPauseMs);

  const combinedData: unknown[] = [];
  const pages: SearchAttemptResult["pages"] = [];
  let previousFingerprint: string | null = null;
  let truncatedBySafetyLimit = false;
  let stopReason: string | null = null;

  for (let pageNumber = 1; pageNumber <= job.maxPages; pageNumber += 1) {
    console.log(`Récupération de la page ${pageNumber}/${job.maxPages} via m_id=1351…`);
    let response = await postFromPage(page, RESULTS_URL, buildSearchParameters(job, pageNumber), true);
    if (response.status === 200 && response.body.trim() === "") {
      console.log(`Page ${pageNumber} encore vide côté Faune-France, nouvelle tentative unique après ${initializationPauseMs} ms…`);
      await pause(initializationPauseMs);
      response = await postFromPage(page, RESULTS_URL, buildSearchParameters(job, pageNumber), true);
    }
    await assertLiveAuthenticatedSession(page, `Résultats page ${pageNumber}`);
    const payload = parseResultsResponse(response, pageNumber);
    const decision = decidePagination(payload, pageNumber, job.maxPages, previousFingerprint);

    if (artifactDirectory) {
      await writeFile(path.join(artifactDirectory, `page-${pageNumber}.raw.json`), response.body, "utf8");
    }
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
    await reportProgress?.({ page: pageNumber, maxPages: job.maxPages, entries: combinedData.length });

    if (!decision.continue) {
      stopReason = decision.stopReason;
      truncatedBySafetyLimit = decision.truncatedBySafetyLimit;
      break;
    }
    previousFingerprint = decision.fingerprint;
    console.log(`Pause de ${job.pagePauseMs} ms avant la page suivante…`);
    await pause(job.pagePauseMs);
  }

  return { initialization, combinedData, pages, truncatedBySafetyLimit, stopReason };
}

export async function runSearchJob(
  job: SearchJob,
  headless = true,
  reportProgress?: SearchProgressReporter,
  persistArtifacts = true
): Promise<SearchRunResult> {
  await mkdir(PROFILE_DIR, { recursive: true });
  const jobOutputDirectory = path.join(OUTPUT_DIR, job.jobId);
  await mkdir(jobOutputDirectory, { recursive: true });
  const runDirectory = path.join(jobOutputDirectory, timestampForPath());
  await mkdir(runDirectory, { recursive: false });

  console.log(`Tâche : ${job.jobId}`);
  console.log(`Profil réutilisé : ${PROFILE_DIR}`);
  console.log(`Sorties de ce lancement : ${runDirectory}`);
  const spatialDescription = job.zone
    ? `point ${job.zone.latitude}, ${job.zone.longitude}, rayon ${job.zone.radiusKm} km (polygone Faune-France)`
    : `départements ${job.departments.join(", ")}`;
  const taxonDescription = job.filter.mode === "species"
    ? `${job.filter.vernacularName} (${job.filter.scientificName}, sp_S=${job.filter.fauneFranceId})`
    : `${job.filter.label} (toutes les espèces, sp_tg=${job.filter.taxonomicGroupId})`;
  console.log(`Recherche : ${taxonDescription}, ${job.dateFrom} → ${job.dateTo}, ${spatialDescription}.`);

  const context = await firefox.launchPersistentContext(PROFILE_DIR, { headless });
  try {
    const page = context.pages()[0] ?? await context.newPage();
    const authenticator = new FauneFranceAuthenticator();
    const dateChunks = splitDateRange(job.dateFrom, job.dateTo);
    const attempts: SearchAttemptResult[] = [];
    const combinedData: unknown[] = [];
    let completedPages = 0;

    for (const [index, dateChunk] of dateChunks.entries()) {
      if (dateChunks.length > 1) {
        console.log(`Période ${index + 1}/${dateChunks.length} : ${dateChunk.dateFrom} → ${dateChunk.dateTo}.`);
      }
      const artifactDirectory = persistArtifacts
        ? (dateChunks.length === 1 ? runDirectory : path.join(runDirectory, `periode-${index + 1}`))
        : undefined;
      if (artifactDirectory && dateChunks.length > 1) await mkdir(artifactDirectory, { recursive: false });

      const attempt = await runWithAuthenticationRetry(
        () => authenticator.ensureAuthenticated(page),
        () => performSearchAttempt(
          page,
          { ...job, ...dateChunk },
          reportProgress
            ? (progress) => reportProgress({
                page: completedPages + progress.page,
                maxPages: job.maxPages * dateChunks.length,
                entries: combinedData.length + progress.entries
              })
            : undefined,
          artifactDirectory
        )
      );
      attempts.push(attempt);
      combinedData.push(...attempt.combinedData);
      completedPages += attempt.pages.length;
    }

    const initialization = attempts[0].initialization;
    const pages = attempts.flatMap((attempt) => attempt.pages);
    const truncatedBySafetyLimit = attempts.some((attempt) => attempt.truncatedBySafetyLimit);
    const stopReason = truncatedBySafetyLimit ? "safety-limit" : attempts.at(-1)?.stopReason ?? null;

    const combinedPath = path.join(runDirectory, "combined-data.json");
    if (persistArtifacts) {
      await writeFile(combinedPath, `${JSON.stringify(combinedData)}\n`, "utf8");
    }

    const analysis = analyzeRawEntry(combinedData[0]);
    const summary = {
      jobId: job.jobId,
      searchedAt: new Date().toISOString(),
      filter: job.filter,
      filters: {
        dateFrom: job.dateFrom,
        dateTo: job.dateTo,
        dateChunks,
        ...(job.zone ? { zone: job.zone } : { departments: job.departments }),
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
    if (persistArtifacts) {
      await writeFile(path.join(runDirectory, "run-summary.json"), `${JSON.stringify(summary, null, 2)}\n`, "utf8");
    }

    console.log(`Terminé : ${pages.length} page(s), ${combinedData.length} entrée(s) brutes, arrêt=${stopReason}.`);
    console.log(`Résultats tronqués par la limite de sécurité : ${truncatedBySafetyLimit ? "oui" : "non"}.`);
    if (persistArtifacts) {
      console.log(`Données combinées : ${combinedPath}`);
    }
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
