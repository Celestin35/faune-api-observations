import type { Page } from "playwright";
import { createHash } from "node:crypto";
import { buildDepartmentMask, toFrenchDate } from "./config.js";

export const BASE_URL = "https://www.faune-france.org/";
export const SEARCH_URL = "https://www.faune-france.org/index.php?m_id=94";
export const RESULTS_URL = "https://www.faune-france.org/index.php?m_id=1351&content=observations_by_page";
export const REQUEST_TIMEOUT_MS = 45_000;
export const LOGOUT_MARKER_SELECTOR = 'a[href*="logout=1"], a[href*="logout"], [data-action="logout"]';

const HTML_HEADERS = {
  Accept: "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
  "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8"
};

const JSON_HEADERS = {
  Accept: "application/json, text/javascript, */*; q=0.01",
  "X-Requested-With": "XMLHttpRequest",
  "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8"
};

export interface RawNetworkResponse {
  status: number;
  url: string;
  redirected: boolean;
  contentType: string;
  body: string;
}

export interface SearchParameterInput {
  filter:
    | { mode: "species"; taxonomicGroupId: number; fauneFranceId: string }
    | { mode: "group"; taxonomicGroupId: number };
  dateFrom: string;
  dateTo: string;
  departments?: string[];
  zone?: { type: "radius"; latitude: number; longitude: number; radiusKm: number };
}

export class SessionExpiredError extends Error {
  constructor(details?: string) {
    super(`Session Faune-France expirée ou absente. Lancez « npm run login », connectez-vous manuellement, puis relancez la recherche.${details ? ` (${details})` : ""}`);
    this.name = "SessionExpiredError";
  }
}

export class FauneFranceResultsTimeoutError extends Error {
  constructor(pageNumber: number) {
    super(`Résultats page ${pageNumber} : Faune-France n’a pas répondu à temps.`);
    this.name = "FauneFranceResultsTimeoutError";
  }
}

export function buildRadiusPolygonWkt(latitude: number, longitude: number, radiusKm: number, vertexCount = 64): string {
  if (!Number.isFinite(latitude) || latitude < -90 || latitude > 90 ||
      !Number.isFinite(longitude) || longitude < -180 || longitude > 180 ||
      !Number.isFinite(radiusKm) || radiusKm <= 0 || radiusKm > 200) {
    throw new Error("Coordonnées ou rayon invalides pour le polygone Faune-France.");
  }
  if (!Number.isInteger(vertexCount) || vertexCount < 8 || vertexCount > 360) {
    throw new Error("Le polygone Faune-France doit contenir entre 8 et 360 sommets.");
  }

  const angularDistance = radiusKm / 6371.0088;
  const startLatitude = latitude * Math.PI / 180;
  const startLongitude = longitude * Math.PI / 180;
  const coordinates: Array<[number, number]> = [];

  for (let index = 0; index < vertexCount; index += 1) {
    const bearing = 2 * Math.PI * index / vertexCount;
    const targetLatitude = Math.asin(
      Math.sin(startLatitude) * Math.cos(angularDistance) +
      Math.cos(startLatitude) * Math.sin(angularDistance) * Math.cos(bearing)
    );
    const targetLongitude = startLongitude + Math.atan2(
      Math.sin(bearing) * Math.sin(angularDistance) * Math.cos(startLatitude),
      Math.cos(angularDistance) - Math.sin(startLatitude) * Math.sin(targetLatitude)
    );
    const normalizedLongitude = ((targetLongitude * 180 / Math.PI + 540) % 360) - 180;
    coordinates.push([normalizedLongitude, targetLatitude * 180 / Math.PI]);
  }
  coordinates.push(coordinates[0]);

  return `POLYGON((${coordinates.map(([lon, lat]) => `${lon.toFixed(7)} ${lat.toFixed(7)}`).join(",")}))`;
}

export function buildSearchParameters(config: SearchParameterInput, page: number): URLSearchParams {
  const spatialParameters: Record<string, string> = config.zone?.type === "radius"
    ? {
        sp_PChoice: "polygon",
        sp_Polygon: buildRadiusPolygonWkt(config.zone.latitude, config.zone.longitude, config.zone.radiusKm)
      }
    : {
        sp_PChoice: "canton",
        sp_cC: buildDepartmentMask(config.departments ?? [])
      };

  const taxonParameters: Record<string, string> = config.filter.mode === "species"
    ? { sp_SChoice: "species", sp_S: config.filter.fauneFranceId }
    : { sp_SChoice: "all" };

  return new URLSearchParams({
    backlink: "skip",
    p_c: "duration",
    p_cc: "-",
    sp_tg: String(config.filter.taxonomicGroupId),
    sp_DChoice: "range",
    sp_DFrom: toFrenchDate(config.dateFrom),
    sp_DTo: toFrenchDate(config.dateTo),
    sp_DCa: "0",
    ...taxonParameters,
    ...spatialParameters,
    sp_project: "0",
    sp_FChoice: "list",
    sp_FDisplay: "DATE_PLACE_SPECIES",
    sp_DFormat: "DESC",
    sp_FMapFormat: "none",
    sp_FExportFormat: "XLS",
    mp_current_page: String(page),
    txid: "1"
  });
}

export async function postFromPage(
  page: Page,
  url: string,
  parameters: URLSearchParams,
  expectsJson: boolean
): Promise<RawNetworkResponse> {
  return page.evaluate(async ({ requestUrl, body, headers, timeoutMs }) => {
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), timeoutMs);
    try {
      const response = await window.fetch(requestUrl, {
        method: "POST",
        credentials: "include",
        headers,
        body,
        redirect: "follow",
        signal: controller.signal
      });
      return {
        status: response.status,
        url: response.url,
        redirected: response.redirected,
        contentType: response.headers.get("content-type") || "",
        body: await response.text()
      };
    } finally {
      window.clearTimeout(timeout);
    }
  }, {
    requestUrl: url,
    body: parameters.toString(),
    headers: expectsJson ? JSON_HEADERS : HTML_HEADERS,
    timeoutMs: REQUEST_TIMEOUT_MS
  });
}

export function buildResultsUrl(parameters: URLSearchParams): string {
  const url = new URL(RESULTS_URL);
  for (const [name, value] of parameters) url.searchParams.set(name, value);
  return url.toString();
}

export async function getFromPage(page: Page, parameters: URLSearchParams): Promise<RawNetworkResponse> {
  return page.evaluate(async ({ requestUrl, headers, timeoutMs }) => {
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), timeoutMs);
    try {
      const response = await window.fetch(requestUrl, {
        method: "GET",
        credentials: "include",
        headers,
        redirect: "follow",
        signal: controller.signal
      });
      return {
        status: response.status,
        url: response.url,
        redirected: response.redirected,
        contentType: response.headers.get("content-type") || "",
        body: await response.text()
      };
    } finally {
      window.clearTimeout(timeout);
    }
  }, { requestUrl: buildResultsUrl(parameters), headers: JSON_HEADERS, timeoutMs: REQUEST_TIMEOUT_MS });
}

export function looksLikeLoginResponse(response: RawNetworkResponse): boolean {
  try {
    if (new URL(response.url).searchParams.get("m_id") === "30494") {
      return true;
    }
  } catch {
    // Les marqueurs HTML ci-dessous restent vérifiés.
  }

  const body = String(response.body || "").toLowerCase();
  if (/logout(?:=1|%3d1)/i.test(body)) {
    return false;
  }
  const hasCredentialsForm = body.includes('type="password"') &&
    (body.includes('name="username"') || body.includes('id="loginemail"') || body.includes('name="email"') || body.includes('type="email"'));
  return hasCredentialsForm;
}

export async function assertLiveAuthenticatedSession(page: Page, step: string): Promise<void> {
  const probe = await page.evaluate(async ({ baseUrl, logoutSelector, timeoutMs }) => {
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), timeoutMs);
    try {
      const response = await window.fetch(baseUrl, {
        method: "GET",
        credentials: "include",
        cache: "no-store",
        signal: controller.signal
      });
      const document = new DOMParser().parseFromString(await response.text(), "text/html");
      return { status: response.status, authenticated: document.querySelector(logoutSelector) !== null };
    } finally {
      window.clearTimeout(timeout);
    }
  }, { baseUrl: BASE_URL, logoutSelector: LOGOUT_MARKER_SELECTOR, timeoutMs: REQUEST_TIMEOUT_MS });

  if (probe.status !== 200) {
    throw new Error(`${step} : impossible de contrôler la session (HTTP ${probe.status}).`);
  }
  if (!probe.authenticated) {
    throw new SessionExpiredError(step);
  }
}

export function assertSuccessfulResponse(response: RawNetworkResponse, step: string): void {
  if (looksLikeLoginResponse(response)) {
    throw new SessionExpiredError(step);
  }
  if (response.status !== 200) {
    throw new Error(`${step} : Faune-France a répondu avec le statut HTTP ${response.status}.`);
  }
}

export type PaginationStopReason = "finished" | "empty-page" | "repeated-page" | "safety-limit";

export interface DataIsFinishedState {
  value: boolean | number | string;
  type: "boolean" | "number" | "string";
  finished: boolean;
}

export interface PaginationDecision {
  continue: boolean;
  stopReason: PaginationStopReason | null;
  truncatedBySafetyLimit: boolean;
  repeated: boolean;
  dataIsFinished: DataIsFinishedState;
  fingerprint: string;
}

export function parseDataIsFinished(value: unknown): DataIsFinishedState {
  if (typeof value === "boolean") {
    return { value, type: "boolean", finished: value };
  }
  if (typeof value === "number" && (value === 0 || value === 1)) {
    return { value, type: "number", finished: value === 1 };
  }
  if (typeof value === "string") {
    const normalized = value.trim().toLowerCase();
    if (normalized === "0" || normalized === "false") {
      return { value, type: "string", finished: false };
    }
    if (normalized === "1" || normalized === "true") {
      return { value, type: "string", finished: true };
    }
  }
  throw new Error(`Valeur data_is_finished inattendue : ${JSON.stringify(value)} (${typeof value}).`);
}

export function fingerprintPageData(data: unknown[]): string {
  return createHash("sha256").update(JSON.stringify(data)).digest("hex");
}

export function decidePagination(
  payload: { data: unknown[]; data_is_finished?: unknown },
  currentPage: number,
  maxPages: number,
  previousFingerprint: string | null
): PaginationDecision {
  const dataIsFinished = parseDataIsFinished(payload.data_is_finished);
  const fingerprint = fingerprintPageData(payload.data);
  const repeated = payload.data.length > 0 && previousFingerprint === fingerprint;

  if (payload.data.length === 0) {
    return { continue: false, stopReason: "empty-page", truncatedBySafetyLimit: false, repeated: false, dataIsFinished, fingerprint };
  }
  if (repeated) {
    return { continue: false, stopReason: "repeated-page", truncatedBySafetyLimit: false, repeated: true, dataIsFinished, fingerprint };
  }
  if (dataIsFinished.finished) {
    return { continue: false, stopReason: "finished", truncatedBySafetyLimit: false, repeated: false, dataIsFinished, fingerprint };
  }
  if (currentPage >= maxPages) {
    return { continue: false, stopReason: "safety-limit", truncatedBySafetyLimit: true, repeated: false, dataIsFinished, fingerprint };
  }
  return { continue: true, stopReason: null, truncatedBySafetyLimit: false, repeated: false, dataIsFinished, fingerprint };
}

export async function pageClearlyShowsLogin(page: Page): Promise<boolean> {
  return page.evaluate(() => {
    const moduleId = new URL(window.location.href).searchParams.get("m_id");
    if (moduleId === "30494") {
      return true;
    }
    const hasLogout = document.querySelector('a[href*="logout=1"], a[href*="logout"], [data-action="logout"]') !== null;
    const hasPassword = document.querySelector('input[type="password"], input[name="PASSWORD"], input[name="password"]') !== null;
    const hasEmail = document.querySelector('#loginemail, input[name="USERNAME"], input[type="email"], input[name="email"]') !== null;
    return !hasLogout && hasPassword && hasEmail;
  });
}

export async function pageShowsAuthenticatedSession(page: Page): Promise<boolean> {
  return page.evaluate(() =>
    document.querySelector('a[href*="logout=1"], a[href*="logout"], [data-action="logout"]') !== null
  );
}

export function parseResultsResponse(response: RawNetworkResponse, pageNumber: number): Record<string, unknown> & { data: unknown[] } {
  assertSuccessfulResponse(response, `Résultats page ${pageNumber}`);
  const text = response.body.trim();
  if (!text) {
    throw new FauneFranceResultsTimeoutError(pageNumber);
  }
  if (text.startsWith("<")) {
    throw new Error(`Résultats page ${pageNumber} : Faune-France a renvoyé une réponse inattendue malgré une session valide.`);
  }

  let payload: unknown;
  try {
    payload = JSON.parse(text);
  } catch (error) {
    throw new Error(`Résultats page ${pageNumber} : réponse JSON invalide.`, { cause: error });
  }
  if (!payload || typeof payload !== "object" || !Array.isArray((payload as Record<string, unknown>).data)) {
    throw new Error(`Résultats page ${pageNumber} : la réponse ne contient pas de tableau data.`);
  }
  return payload as Record<string, unknown> & { data: unknown[] };
}
