import { readFile } from "node:fs/promises";
import path from "node:path";
import { validateConfig } from "./config.js";

export interface SearchTaxon {
  fauneFranceId: string;
  scientificName: string;
  vernacularName: string;
  rank: "species";
}

export interface SearchJob {
  jobId: string;
  taxon: SearchTaxon;
  dateFrom: string;
  dateTo: string;
  departments: string[];
  maxPages: number;
  pagePauseMs: number;
}

const ROOT_KEYS = ["jobId", "taxon", "dateFrom", "dateTo", "departments", "maxPages", "pagePauseMs"] as const;
const TAXON_KEYS = ["fauneFranceId", "scientificName", "vernacularName", "rank"] as const;

function asObject(value: unknown, label: string): Record<string, unknown> {
  if (!value || typeof value !== "object" || Array.isArray(value)) {
    throw new Error(`${label} doit être un objet JSON.`);
  }
  return value as Record<string, unknown>;
}

function assertExactKeys(object: Record<string, unknown>, expected: readonly string[], label: string): void {
  const missing = expected.filter((key) => !Object.hasOwn(object, key));
  const unknown = Object.keys(object).filter((key) => !expected.includes(key));
  if (missing.length > 0) {
    throw new Error(`${label} : champ${missing.length > 1 ? "s" : ""} obligatoire${missing.length > 1 ? "s" : ""} manquant${missing.length > 1 ? "s" : ""} : ${missing.join(", ")}.`);
  }
  if (unknown.length > 0) {
    throw new Error(`${label} : champ${unknown.length > 1 ? "s" : ""} inconnu${unknown.length > 1 ? "s" : ""} : ${unknown.join(", ")}.`);
  }
}

function requiredString(value: unknown, label: string): string {
  if (typeof value !== "string" || value.trim() === "") {
    throw new Error(`${label} doit être une chaîne non vide.`);
  }
  return value.trim();
}

export function validateSearchJob(value: unknown): SearchJob {
  const input = asObject(value, "La tâche");
  assertExactKeys(input, ROOT_KEYS, "La tâche");

  const jobId = requiredString(input.jobId, "jobId");
  if (!/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/.test(jobId)) {
    throw new Error("jobId doit contenir 1 à 100 caractères parmi lettres, chiffres, point, tiret et underscore, sans séparateur de chemin.");
  }

  const taxonInput = asObject(input.taxon, "taxon");
  assertExactKeys(taxonInput, TAXON_KEYS, "taxon");
  const fauneFranceId = requiredString(taxonInput.fauneFranceId, "taxon.fauneFranceId");
  if (!/^[1-9]\d*$/.test(fauneFranceId)) {
    throw new Error("taxon.fauneFranceId doit être un identifiant numérique positif fourni explicitement.");
  }
  const scientificName = requiredString(taxonInput.scientificName, "taxon.scientificName");
  const vernacularName = requiredString(taxonInput.vernacularName, "taxon.vernacularName");
  if (taxonInput.rank !== "species") {
    throw new Error('taxon.rank doit temporairement valoir exactement "species".');
  }

  if (typeof input.dateFrom !== "string" || typeof input.dateTo !== "string") {
    throw new Error("dateFrom et dateTo doivent être des chaînes au format YYYY-MM-DD.");
  }
  if (!Array.isArray(input.departments) || input.departments.length === 0 || input.departments.some((code) => typeof code !== "string")) {
    throw new Error("departments doit être un tableau non vide de chaînes.");
  }
  if (typeof input.maxPages !== "number" || !Number.isInteger(input.maxPages)) {
    throw new Error("maxPages doit être un entier JSON.");
  }
  if (typeof input.pagePauseMs !== "number" || !Number.isInteger(input.pagePauseMs)) {
    throw new Error("pagePauseMs doit être un entier JSON.");
  }

  validateConfig({
    dateFrom: input.dateFrom,
    dateTo: input.dateTo,
    departments: input.departments,
    maxPages: input.maxPages,
    pagePauseMs: input.pagePauseMs,
    headless: true
  });

  return {
    jobId,
    taxon: { fauneFranceId, scientificName, vernacularName, rank: "species" },
    dateFrom: input.dateFrom,
    dateTo: input.dateTo,
    departments: [...input.departments] as string[],
    maxPages: input.maxPages,
    pagePauseMs: input.pagePauseMs
  };
}

export function jobPathFromArguments(argumentsList: string[]): string {
  if (argumentsList.length !== 1 || !argumentsList[0].startsWith("--job=")) {
    throw new Error("Usage : npm run search -- --job=./jobs/test-001.json");
  }
  const fileName = argumentsList[0].slice("--job=".length).trim();
  if (!fileName) {
    throw new Error("Le chemin fourni à --job ne peut pas être vide.");
  }
  return path.resolve(process.cwd(), fileName);
}

export async function loadSearchJob(filePath: string): Promise<SearchJob> {
  let source: string;
  try {
    source = await readFile(filePath, "utf8");
  } catch (error) {
    throw new Error(`Impossible de lire le fichier de tâche ${filePath}.`, { cause: error });
  }

  let parsed: unknown;
  try {
    parsed = JSON.parse(source);
  } catch (error) {
    throw new Error(`Le fichier de tâche ${filePath} ne contient pas un JSON valide.`, { cause: error });
  }
  return validateSearchJob(parsed);
}
