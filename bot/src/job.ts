import { readFile } from "node:fs/promises";
import path from "node:path";
import { normalizeDepartmentCode, toFrenchDate, buildDepartmentMask } from "./config.js";

export interface SpeciesSearchFilter {
  mode: "species";
  taxonomicGroupId: number;
  fauneFranceId: string;
  scientificName: string;
  vernacularName: string;
  label: string;
}

export interface GroupSearchFilter {
  mode: "group";
  taxonomicGroupId: number;
  label: string;
}

export type SearchFilter = SpeciesSearchFilter | GroupSearchFilter;

interface SearchJobBase {
  jobId: string;
  filter: SearchFilter;
  dateFrom: string;
  dateTo: string;
  importLimit: number;
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

const ROOT_KEYS = ["jobId", "filter", "taxon", "dateFrom", "dateTo", "departments", "zone", "importLimit", "maxPages", "pagePauseMs"] as const;
const REQUIRED_ROOT_KEYS = ["jobId", "dateFrom", "dateTo", "maxPages", "pagePauseMs"] as const;
const TAXON_KEYS = ["fauneFranceId", "scientificName", "vernacularName", "rank"] as const;
const SPECIES_FILTER_KEYS = ["mode", "taxonomicGroupId", "fauneFranceId", "scientificName", "vernacularName", "label"] as const;
const GROUP_FILTER_KEYS = ["mode", "taxonomicGroupId", "label"] as const;
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

function taxonomicGroupId(value: unknown): number {
  if (typeof value !== "number" || !Number.isInteger(value) || value < 1 || value > 999) {
    throw new Error("filter.taxonomicGroupId doit être un identifiant numérique positif.");
  }
  return value;
}

function validateFilter(input: Record<string, unknown>): SearchFilter {
  if (Object.hasOwn(input, "filter") === Object.hasOwn(input, "taxon")) {
    throw new Error("La tâche doit contenir exactement un filtre taxonomique : filter ou l’ancien champ taxon.");
  }

  if (Object.hasOwn(input, "taxon")) {
    const legacy = asObject(input.taxon, "taxon");
    assertExactKeys(legacy, TAXON_KEYS, "taxon");
    const fauneFranceId = requiredString(legacy.fauneFranceId, "taxon.fauneFranceId");
    if (!/^[1-9]\d*$/.test(fauneFranceId)) {
      throw new Error("taxon.fauneFranceId doit être un identifiant numérique positif fourni explicitement.");
    }
    if (legacy.rank !== "species") {
      throw new Error('taxon.rank doit valoir exactement "species".');
    }
    const scientificName = requiredString(legacy.scientificName, "taxon.scientificName");
    const vernacularName = requiredString(legacy.vernacularName, "taxon.vernacularName");
    return { mode: "species", taxonomicGroupId: 1, fauneFranceId, scientificName, vernacularName, label: vernacularName };
  }

  const filter = asObject(input.filter, "filter");
  if (filter.mode === "species") {
    assertExactKeys(filter, SPECIES_FILTER_KEYS, "filter");
    const fauneFranceId = requiredString(filter.fauneFranceId, "filter.fauneFranceId");
    if (!/^[1-9]\d*$/.test(fauneFranceId)) {
      throw new Error("filter.fauneFranceId doit être un identifiant numérique positif fourni explicitement.");
    }
    return {
      mode: "species",
      taxonomicGroupId: taxonomicGroupId(filter.taxonomicGroupId),
      fauneFranceId,
      scientificName: requiredString(filter.scientificName, "filter.scientificName"),
      vernacularName: requiredString(filter.vernacularName, "filter.vernacularName"),
      label: requiredString(filter.label, "filter.label")
    };
  }
  if (filter.mode === "group") {
    assertExactKeys(filter, GROUP_FILTER_KEYS, "filter");
    return {
      mode: "group",
      taxonomicGroupId: taxonomicGroupId(filter.taxonomicGroupId),
      label: requiredString(filter.label, "filter.label")
    };
  }
  throw new Error('filter.mode doit valoir "species" ou "group".');
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

  const filter = validateFilter(input);

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
  const importLimit = input.importLimit === undefined ? 10_000 : input.importLimit;
  if (typeof importLimit !== "number" || !Number.isInteger(importLimit) || importLimit < 1 || importLimit > 100_000) {
    throw new Error("importLimit doit être un entier compris entre 1 et 100000.");
  }
  if (typeof input.pagePauseMs !== "number" || !Number.isInteger(input.pagePauseMs)) {
    throw new Error("pagePauseMs doit être un entier JSON.");
  }

  if (input.maxPages < 1 || input.maxPages > 1000) {
    throw new Error("maxPages doit être un entier compris entre 1 et 1000.");
  }
  if (input.pagePauseMs < 0 || input.pagePauseMs > 60_000) {
    throw new Error("pagePauseMs doit être un entier compris entre 0 et 60000.");
  }

  const hasDepartments = Object.hasOwn(input, "departments");
  const hasZone = Object.hasOwn(input, "zone");
  if (hasDepartments === hasZone) {
    throw new Error("La tâche doit contenir exactement une zone : departments ou zone.");
  }

  const common = {
    jobId,
    filter,
    dateFrom: input.dateFrom,
    dateTo: input.dateTo,
    importLimit,
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
