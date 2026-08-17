import assert from "node:assert/strict";
import test from "node:test";
import { validateSearchJob } from "./job.js";

function validJob(): Record<string, unknown> {
  return {
    jobId: "test-001",
    taxon: {
      fauneFranceId: "383",
      scientificName: "Tichodroma muraria",
      vernacularName: "Tichodrome échelette",
      rank: "species"
    },
    dateFrom: "2026-06-22",
    dateTo: "2026-07-22",
    departments: ["09"],
    maxPages: 100,
    pagePauseMs: 1500
  };
}

test("une tâche valide est acceptée sans modifier son taxon", () => {
  const job = validateSearchJob(validJob());
  assert.equal(job.jobId, "test-001");
  assert.equal(job.filter.mode, "species");
  assert.equal(job.filter.mode === "species" ? job.filter.fauneFranceId : null, "383");
  assert.deepEqual(job.departments, ["09"]);
});

test("une tâche point et rayon métropolitaine est acceptée", () => {
  const input = validJob();
  delete input.departments;
  input.zone = {
    type: "radius",
    latitude: 48.1173,
    longitude: -1.6778,
    radiusKm: 30,
    address: " Rennes, France "
  };
  const job = validateSearchJob(input);
  assert.equal(job.zone?.type, "radius");
  assert.equal(job.zone?.radiusKm, 30);
  assert.equal(job.zone?.address, "Rennes, France");
  assert.equal(job.departments, undefined);
});

test("une tâche ne peut pas mélanger départements et point/rayon", () => {
  const input = validJob();
  input.zone = { type: "radius", latitude: 48.1173, longitude: -1.6778, radiusKm: 30 };
  assert.throws(() => validateSearchJob(input), /exactement une zone/);
});

test("une zone point/rayon ultramarine est refusée par le connecteur métropolitain", () => {
  const input = validJob();
  delete input.departments;
  input.zone = { type: "radius", latitude: 16.241, longitude: -61.533, radiusKm: 10 };
  assert.throws(() => validateSearchJob(input), /France métropolitaine/);
});

test("un champ inconnu est refusé par la validation stricte", () => {
  const input = validJob();
  input.automaticTaxonResolution = true;
  assert.throws(() => validateSearchJob(input), /champ inconnu/);
});

test("un identifiant Faune-France absent est refusé", () => {
  const input = validJob();
  delete (input.taxon as Record<string, unknown>).fauneFranceId;
  assert.throws(() => validateSearchJob(input), /fauneFranceId/);
});

test("une recherche de toutes les espèces d’un groupe est acceptée", () => {
  const input = validJob();
  delete input.taxon;
  input.filter = { mode: "group", taxonomicGroupId: 27, label: "Araignées" };
  const job = validateSearchJob(input);
  assert.deepEqual(job.filter, { mode: "group", taxonomicGroupId: 27, label: "Araignées" });
});

test("des dates invalides sont refusées", () => {
  const input = validJob();
  input.dateFrom = "2026-02-30";
  assert.throws(() => validateSearchJob(input), /dateFrom|dates valides/);
});

test("un département invalide est refusé", () => {
  const input = validJob();
  input.departments = ["99"];
  assert.throws(() => validateSearchJob(input), /Département Faune-France inconnu/);
});

test("un département ultramarin n’entre jamais dans le masque métropolitain", () => {
  const input = validJob();
  input.departments = ["971"];
  assert.throws(() => validateSearchJob(input), /Département Faune-France inconnu/);
});

test("un maxPages invalide est refusé", () => {
  const input = validJob();
  input.maxPages = 0;
  assert.throws(() => validateSearchJob(input), /maxPages/);
});
