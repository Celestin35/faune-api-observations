import { readFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import path from "node:path";

export interface SearchConfig {
  dateFrom: string;
  dateTo: string;
  departments: string[];
  pagePauseMs: number;
  maxPages: number;
  headless: boolean;
}

export const BOT_ROOT = fileURLToPath(new URL("../", import.meta.url));
export const PROFILE_DIR = path.join(BOT_ROOT, "data", "browser-profile");
export const OUTPUT_DIR = path.join(BOT_ROOT, "data", "output");
export const CONFIG_PATH = path.join(BOT_ROOT, "config.json");

function isIsoDate(value: string): boolean {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) {
    return false;
  }
  const [year, month, day] = value.split("-").map(Number);
  const date = new Date(Date.UTC(year, month - 1, day));
  return date.getUTCFullYear() === year &&
    date.getUTCMonth() === month - 1 &&
    date.getUTCDate() === day;
}

export function normalizeDepartmentCode(value: unknown): string {
  const code = String(value ?? "").trim().toUpperCase();
  return /^\d$/.test(code) ? `0${code}` : code;
}

export function departmentIndex(code: string): number {
  if (/^(0[1-9]|1\d)$/.test(code)) {
    return Number(code) - 1;
  }
  if (code === "2A") {
    return 19;
  }
  if (code === "2B") {
    return 20;
  }
  if (/^(2[1-9]|[3-8]\d|9[0-5])$/.test(code)) {
    return Number(code);
  }
  return -1;
}

export function buildDepartmentMask(departments: string[]): string {
  const mask = Array<string>(100).fill("0");
  for (const rawCode of departments) {
    const code = normalizeDepartmentCode(rawCode);
    const index = departmentIndex(code);
    if (index === -1) {
      throw new Error(`Département Faune-France inconnu : ${rawCode}.`);
    }
    mask[index] = "1";
  }
  return mask.join("");
}

export function toFrenchDate(value: string): string {
  if (!isIsoDate(value)) {
    throw new Error(`Date invalide : ${value}. Le format attendu est YYYY-MM-DD.`);
  }
  const [year, month, day] = value.split("-");
  return `${day}.${month}.${year}`;
}

export function validateConfig(value: unknown): SearchConfig {
  if (!value || typeof value !== "object") {
    throw new Error("bot/config.json doit contenir un objet JSON.");
  }

  const input = value as Record<string, unknown>;
  const dateFrom = String(input.dateFrom ?? "");
  const dateTo = String(input.dateTo ?? "");
  if (!isIsoDate(dateFrom) || !isIsoDate(dateTo)) {
    throw new Error("dateFrom et dateTo doivent être des dates valides au format YYYY-MM-DD.");
  }
  if (dateFrom > dateTo) {
    throw new Error("dateFrom doit être antérieure ou égale à dateTo.");
  }
  if (!Array.isArray(input.departments) || input.departments.length === 0) {
    throw new Error("departments doit contenir au moins un département.");
  }

  const departments = [...new Set(input.departments.map(normalizeDepartmentCode))];
  buildDepartmentMask(departments);

  const pagePauseMs = input.pagePauseMs === undefined ? 1500 : Number(input.pagePauseMs);
  if (!Number.isInteger(pagePauseMs) || pagePauseMs < 500 || pagePauseMs > 60_000) {
    throw new Error("pagePauseMs doit être un entier compris entre 500 et 60000.");
  }

  const maxPages = input.maxPages === undefined ? 100 : Number(input.maxPages);
  if (!Number.isInteger(maxPages) || maxPages < 1 || maxPages > 1000) {
    throw new Error("maxPages doit être un entier compris entre 1 et 1000.");
  }

  if (input.headless !== undefined && typeof input.headless !== "boolean") {
    throw new Error("headless doit être un booléen.");
  }

  return {
    dateFrom,
    dateTo,
    departments,
    pagePauseMs,
    maxPages,
    headless: input.headless ?? true
  };
}

export async function loadConfig(): Promise<SearchConfig> {
  let raw: string;
  try {
    raw = await readFile(CONFIG_PATH, "utf8");
  } catch (error) {
    throw new Error(`Impossible de lire ${CONFIG_PATH}.`, { cause: error });
  }

  try {
    return validateConfig(JSON.parse(raw));
  } catch (error) {
    if (error instanceof SyntaxError) {
      throw new Error(`Le JSON de ${CONFIG_PATH} est invalide.`, { cause: error });
    }
    throw error;
  }
}
