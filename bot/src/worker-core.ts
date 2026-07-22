import { LaravelApiError, type BatchCounts, type WorkerApi } from "./api-client.js";
import type { SearchJob } from "./job.js";
import type { SearchRunResult } from "./search-runner.js";

export type WorkerIterationStatus = "idle" | "claim-conflict" | "completed" | "failed";
export type SearchExecutor = (job: SearchJob) => Promise<SearchRunResult>;

export interface WorkerLogger {
  log(message: string): void;
  error(message: string): void;
}

export interface WorkerIterationResult {
  status: WorkerIterationStatus;
  jobId?: string;
  counts?: BatchCounts;
}

function errorMessage(error: unknown): string {
  return error instanceof Error ? error.message : "Erreur inconnue du worker.";
}

function resultBatches(observations: unknown[]): unknown[][] {
  if (observations.length === 0) {
    return [[]];
  }
  const batches: unknown[][] = [];
  for (let offset = 0; offset < observations.length; offset += 100) {
    batches.push(observations.slice(offset, offset + 100));
  }
  return batches;
}

export async function processNextJob(
  api: WorkerApi,
  executeSearch: SearchExecutor,
  logger: WorkerLogger = console,
  heartbeatIntervalMs = 10_000
): Promise<WorkerIterationResult> {
  const availableJob = await api.nextJob();
  if (!availableJob) {
    logger.log("Aucune tâche Faune-France disponible.");
    return { status: "idle" };
  }

  let job: SearchJob;
  try {
    job = await api.claim(availableJob.jobId);
  } catch (error) {
    if (error instanceof LaravelApiError && error.status === 409) {
      logger.log(`La tâche ${availableJob.jobId} a été réservée par un autre bot.`);
      return { status: "claim-conflict", jobId: availableJob.jobId };
    }
    throw error;
  }

  logger.log(`Tâche ${job.jobId} réservée.`);
  let heartbeatRunning = false;
  const heartbeat = setInterval(() => {
    if (heartbeatRunning) {
      return;
    }
    heartbeatRunning = true;
    void api.heartbeat(job.jobId)
      .catch((error) => logger.error(`Heartbeat de la tâche ${job.jobId} en échec : ${errorMessage(error)}`))
      .finally(() => { heartbeatRunning = false; });
  }, heartbeatIntervalMs);

  try {
    await api.heartbeat(job.jobId);
    const searchResult = await executeSearch(job);
    const batches = resultBatches(searchResult.observations);
    const totals: BatchCounts = { created: 0, updated: 0, unchanged: 0 };

    for (const [index, observations] of batches.entries()) {
      const response = await api.sendResults(job.jobId, index + 1, index === batches.length - 1, observations);
      totals.created += response.counts.created;
      totals.updated += response.counts.updated;
      totals.unchanged += response.counts.unchanged;
      logger.log(`Tâche ${job.jobId}, lot ${index + 1}/${batches.length} envoyé (${observations.length} observation(s)).`);
      await api.heartbeat(job.jobId);
    }

    await api.complete(job.jobId);
    logger.log(`Tâche ${job.jobId} terminée : ${totals.created} créée(s), ${totals.updated} mise(s) à jour, ${totals.unchanged} inchangée(s).`);
    return { status: "completed", jobId: job.jobId, counts: totals };
  } catch (error) {
    const message = errorMessage(error);
    logger.error(`Tâche ${job.jobId} en échec : ${message}`);
    try {
      await api.fail(job.jobId, message);
    } catch (failError) {
      logger.error(`Impossible de signaler l’échec de la tâche ${job.jobId} : ${errorMessage(failError)}`);
    }
    return { status: "failed", jobId: job.jobId };
  } finally {
    clearInterval(heartbeat);
  }
}
