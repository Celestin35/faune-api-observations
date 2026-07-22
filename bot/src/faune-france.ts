import type { Page } from "playwright";
import { buildDepartmentMask, toFrenchDate, type SearchConfig } from "./config.js";

export const BASE_URL = "https://www.faune-france.org/";
export const SEARCH_URL = "https://www.faune-france.org/index.php?m_id=94";
export const RESULTS_URL = "https://www.faune-france.org/index.php?m_id=1351&content=observations_by_page";
export const MAX_PAGES = 2;
export const REQUEST_TIMEOUT_MS = 45_000;

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

export class SessionExpiredError extends Error {
  constructor(details?: string) {
    super(`Session Faune-France expirée ou absente. Lancez « npm run login », connectez-vous manuellement, puis relancez « npm run test-search ».${details ? ` (${details})` : ""}`);
    this.name = "SessionExpiredError";
  }
}

export function buildSearchParameters(config: SearchConfig, page: number): URLSearchParams {
  return new URLSearchParams({
    backlink: "skip",
    p_c: "duration",
    p_cc: "-",
    sp_tg: "1",
    sp_DChoice: "range",
    sp_DFrom: toFrenchDate(config.dateFrom),
    sp_DTo: toFrenchDate(config.dateTo),
    sp_DCa: "0",
    sp_SChoice: "species",
    sp_S: "383",
    sp_PChoice: "canton",
    sp_cC: buildDepartmentMask(config.departments),
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
    (body.includes('name="email"') || body.includes('type="email"'));
  const isDedicatedLoginPage =
    body.includes("pour accéder à la page demandée") ||
    body.includes("pour acc&eacute;der &agrave; la page demand&eacute;e") ||
    /<title[^>]*>\s*login\s*<\/title>/i.test(body);
  return hasCredentialsForm && isDedicatedLoginPage;
}

export function assertSuccessfulResponse(response: RawNetworkResponse, step: string): void {
  if (looksLikeLoginResponse(response)) {
    throw new SessionExpiredError(step);
  }
  if (response.status !== 200) {
    throw new Error(`${step} : Faune-France a répondu avec le statut HTTP ${response.status}.`);
  }
}

function valueAtPath(object: unknown, path: string): unknown {
  return path.split(".").reduce<unknown>((value, key) => {
    if (!value || typeof value !== "object") {
      return undefined;
    }
    return (value as Record<string, unknown>)[key];
  }, object);
}

export function hasNextPage(payload: unknown, currentPage: number): boolean {
  const booleanPaths = ["has_next", "hasNext", "has_more", "hasMore", "pagination.has_next", "pagination.hasNext"];
  for (const path of booleanPaths) {
    const value = valueAtPath(payload, path);
    if (typeof value === "boolean") {
      return value;
    }
    if (value === 0 || value === 1 || value === "0" || value === "1") {
      return value === 1 || value === "1";
    }
  }

  const totalPaths = ["total_pages", "totalPages", "nb_pages", "number_pages", "pagination.total_pages", "pagination.totalPages", "pager.total_pages"];
  for (const path of totalPaths) {
    const total = Number(valueAtPath(payload, path));
    if (Number.isInteger(total) && total > 0) {
      return currentPage < total;
    }
  }

  const nextPaths = ["next_page", "nextPage", "pagination.next_page", "pagination.nextPage"];
  for (const path of nextPaths) {
    const next = valueAtPath(payload, path);
    if (next !== undefined && next !== null && next !== false && next !== "") {
      const nextPage = Number(next);
      return !Number.isFinite(nextPage) || nextPage > currentPage;
    }
  }
  return false;
}

export async function pageClearlyShowsLogin(page: Page): Promise<boolean> {
  return page.evaluate(() => {
    const moduleId = new URL(window.location.href).searchParams.get("m_id");
    if (moduleId === "30494") {
      return true;
    }
    const hasLogout = document.querySelector('a[href*="logout=1"], a[href*="logout"], [data-action="logout"]') !== null;
    const hasPassword = document.querySelector('input[type="password"], input[name="password"]') !== null;
    const hasEmail = document.querySelector('input[type="email"], input[name="email"]') !== null;
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
  if (!text || text.startsWith("<")) {
    throw new Error(`Résultats page ${pageNumber} : Faune-France n’a pas renvoyé de JSON. Vérifiez la session avec « npm run login ».`);
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
