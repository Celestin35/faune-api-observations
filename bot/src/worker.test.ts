import assert from "node:assert/strict";
import test from "node:test";
import type { BatchResponse, WorkerApi, WorkerProgress } from "./api-client.js";
import type { SearchJob } from "./job.js";
import type { SearchRunResult } from "./search-runner.js";
import { processNextJob } from "./worker-core.js";
import { FauneFranceAuthError } from "./faune-france-auth.js";

function job(): SearchJob {
  return {
    jobId: "42",
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

class SimulatedApi implements WorkerApi {
  availableJob: SearchJob | null = job();
  claimed: string[] = [];
  heartbeats: string[] = [];
  progress: WorkerProgress[] = [];
  batches: Array<{ number: number; last: boolean; observations: unknown[] }> = [];
  completed: string[] = [];
  failed: Array<{ jobId: string; message: string }> = [];

  async nextJob(): Promise<SearchJob | null> {
    return this.availableJob;
  }

  async claim(jobId: string): Promise<SearchJob> {
    this.claimed.push(jobId);
    return job();
  }

  async sendResults(_jobId: string, batchNumber: number, isLastBatch: boolean, observations: unknown[]): Promise<BatchResponse> {
    this.batches.push({ number: batchNumber, last: isLastBatch, observations });
    return { counts: { created: observations.length, updated: 0, unchanged: 0 }, replayed: false };
  }

  async complete(jobId: string): Promise<void> {
    this.completed.push(jobId);
  }

  async fail(jobId: string, errorMessage: string): Promise<void> {
    this.failed.push({ jobId, message: errorMessage });
  }

  async heartbeat(jobId?: string, progress?: WorkerProgress): Promise<void> {
    if (jobId) {
      this.heartbeats.push(jobId);
    }
    if (progress) {
      this.progress.push(progress);
    }
  }
}

const silentLogger = { log: (_message: string): void => {}, error: (_message: string): void => {} };

function searchResult(observations: unknown[]): SearchRunResult {
  return {
    runDirectory: "/tmp/run",
    combinedPath: "/tmp/run/combined-data.json",
    totalPages: 1,
    entries: observations.length,
    truncatedBySafetyLimit: false,
    stopReason: "finished",
    observations
  };
}

test("le worker simulé réserve, découpe en lots de 100 et termine la tâche", async () => {
  const api = new SimulatedApi();
  const observations = Array.from({ length: 205 }, (_, id) => ({ id }));

  const result = await processNextJob(api, async () => searchResult(observations), silentLogger, 60_000);

  assert.equal(result.status, "completed");
  assert.deepEqual(api.claimed, ["42"]);
  assert.deepEqual(api.batches.map((batch) => batch.observations.length), [100, 100, 5]);
  assert.deepEqual(api.batches.map((batch) => batch.last), [false, false, true]);
  assert.deepEqual(api.completed, ["42"]);
  assert.deepEqual(api.failed, []);
  assert.equal(result.counts?.created, 205);
  assert.deepEqual(api.progress.map((progress) => [progress.stage, progress.current, progress.total]), [
    ["saving", 0, 205],
    ["saving", 100, 205],
    ["saving", 200, 205],
    ["saving", 205, 205]
  ]);
});

test("le worker simulé reste inactif lorsque Laravel ne renvoie aucune tâche", async () => {
  const api = new SimulatedApi();
  api.availableJob = null;

  const result = await processNextJob(api, async () => searchResult([]), silentLogger);

  assert.equal(result.status, "idle");
  assert.deepEqual(api.claimed, []);
});

test("le worker signale l’échec sans arrêter définitivement son processus", async () => {
  const api = new SimulatedApi();

  const result = await processNextJob(api, async () => {
    throw new Error("Playwright indisponible");
  }, silentLogger, 60_000);

  assert.equal(result.status, "failed");
  assert.deepEqual(api.completed, []);
  assert.equal(api.failed[0]?.jobId, "42");
  assert.match(api.failed[0]?.message ?? "", /Playwright indisponible/);
});

test("un échec d’authentification est transmis à Laravel puis une tâche suivante peut réussir", async () => {
  const api = new SimulatedApi();
  const first = await processNextJob(api, async () => {
    throw new FauneFranceAuthError("AUTH_LOGIN_FAILED", "connexion refusée");
  }, silentLogger, 60_000);

  const second = await processNextJob(api, async () => searchResult([{ id_sighting: "ok" }]), silentLogger, 60_000);

  assert.equal(first.status, "failed");
  assert.match(api.failed[0]?.message ?? "", /^AUTH_LOGIN_FAILED:/);
  assert.equal(second.status, "completed");
  assert.deepEqual(api.completed, ["42"]);
});
