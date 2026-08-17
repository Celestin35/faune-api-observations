import { readFile } from "node:fs/promises";
import path from "node:path";
import { normalizeDepartmentCode, toFrenchDate, buildDepartmentMask } from "./config.js";

export interface SearchTaxon {
  fauneFranceId: string;
  scientificName: string;
  vernacularName: string;
  rank: "species";
}

interface SearchJobBase {
  jobId: string;
  taxon: SearchTaxon;
  dateFrom: string;
  dateTo: string;
  maxPages: number;
  pagePauseMs: number;
}

export interface RadiusSearchZone {
  type: "radius";
  latitude: number;
  longitude: number;
  radiusKm: number;
  address?: string;
}

export type SearchJob = SearchJobBase & (
  | { departments: string[]; zone?: never }
  | { departments?: never; zone: RadiusSearchZone }
);

const ROOT_KEYS = ["jobId", "taxon", "dateFrom", "dateTo", "departments", "zone", "maxPages", "pagePauseMs"] as const;
const REQUIRED_ROOT_KEYS = ["jobId", "taxon", "dateFrom", "dateTo", "maxPages", "pagePauseMs"] as const;
const TAXON_KEYS = ["fauneFranceId", "scientificName", "vernacularName", "rank"] as const;
const RADIUS_ZONE_KEYS = ["type", "latitude", "longitude", "radiusKm", "address"] as const;

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
  const missing = REQUIRED_ROOT_KEYS.filter((key) => !Object.hasOwn(input, key));
  const unknown = Object.keys(input).filter((key) => !ROOT_KEYS.includes(key as typeof ROOT_KEYS[number]));
  if (missing.length > 0) {
    throw new Error(`La tâche : champs obligatoires manquants : ${missing.join(", ")}.`);
  }
  if (unknown.length > 0) {
    throw new Error(`La tâche : champ${unknown.length > 1 ? "s" : ""} inconnu${unknown.length > 1 ? "s" : ""} : ${unknown.join(", ")}.`);
  }

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
  try {
    toFrenchDate(input.dateFrom);
    toFrenchDate(input.dateTo);
  } catch {
    throw new Error("dateFrom et dateTo doivent être des dates valides au format YYYY-MM-DD.");
  }
  if (input.dateFrom > input.dateTo) {
    throw new Error("dateFrom doit être antérieure ou égale à dateTo.");
  }
  if (typeof input.maxPages !== "number" || !Number.isInteger(input.maxPages)) {
    throw new Error("maxPages doit être un entier JSON.");
  }
  if (typeof input.pagePauseMs !== "number" || !Number.isInteger(input.pagePauseMs)) {
    throw new Error("pagePauseMs doit être un entier JSON.");
  }

  if (input.maxPages < 1 || input.maxPages > 1000) {
    throw new Error("maxPages doit être un entier compris entre 1 et 1000.");
  }
  if (input.pagePauseMs < 500 || input.pagePauseMs > 60_000) {
    throw new Error("pagePauseMs doit être un entier compris entre 500 et 60000.");
  }

  const hasDepartments = Object.hasOwn(input, "departments");
  const hasZone = Object.hasOwn(input, "zone");
  if (hasDepartments === hasZone) {
    throw new Error("La tâche doit contenir exactement une zone : departments ou zone.");
  }

  const common = {
    jobId,
    taxon: { fauneFranceId, scientificName, vernacularName, rank: "species" as const },
    dateFrom: input.dateFrom,
    dateTo: input.dateTo,
    maxPages: input.maxPages,
    pagePauseMs: input.pagePauseMs
  };

  if (hasDepartments) {
    if (!Array.isArray(input.departments) || input.departments.length === 0 || input.departments.some((code) => typeof code !== "string")) {
      throw new Error("departments doit être un tableau non vide de chaînes.");
    }
    const departments = [...new Set(input.departments.map(normalizeDepartmentCode))];
    buildDepartmentMask(departments);
    return { ...common, departments };
  }

  const zoneInput = asObject(input.zone, "zone");
  const unknownZoneKeys = Object.keys(zoneInput).filter((key) => !RADIUS_ZONE_KEYS.includes(key as typeof RADIUS_ZONE_KEYS[number]));
  if (unknownZoneKeys.length > 0) {
    throw new Error(`zone : champs inconnus : ${unknownZoneKeys.join(", ")}.`);
  }
  if (zoneInput.type !== "radius") {
    throw new Error('zone.type doit valoir exactement "radius".');
  }
  for (const field of ["latitude", "longitude", "radiusKm"] as const) {
    if (typeof zoneInput[field] !== "number" || !Number.isFinite(zoneInput[field])) {
      throw new Error(`zone.${field} doit être un nombre JSON fini.`);
    }
  }
  const latitude = zoneInput.latitude as number;
  const longitude = zoneInput.longitude as number;
  const radiusKm = zoneInput.radiusKm as number;
  if (latitude < -90 || latitude > 90 || longitude < -180 || longitude > 180) {
    throw new Error("Les coordonnées de zone sont hors limites.");
  }
  if (radiusKm <= 0 || radiusKm > 200) {
    throw new Error("zone.radiusKm doit être supérieur à 0 et inférieur ou égal à 200.");
  }
  if (latitude < 41 || latitude > 51.5 || longitude < -5.5 || longitude > 10) {
    throw new Error("La zone point/rayon Faune-France doit être située en France métropolitaine.");
  }
  if (zoneInput.address !== undefined && (typeof zoneInput.address !== "string" || zoneInput.address.trim().length > 255)) {
    throw new Error("zone.address doit être une chaîne de 255 caractères maximum.");
  }

  return {
    ...common,
    zone: {
      type: "radius",
      latitude,
      longitude,
      radiusKm,
      ...(typeof zoneInput.address === "string" && zoneInput.address.trim() !== "" ? { address: zoneInput.address.trim() } : {})
    }
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
