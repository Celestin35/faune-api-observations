import { validateSearchJob, type SearchJob } from "./job.js";

export interface WorkerConfig {
  token: string;
  apiUrl: string;
  pollIntervalMs: number;
}

export interface BatchCounts {
  created: number;
  updated: number;
  unchanged: number;
}

export interface BatchResponse {
  counts: BatchCounts;
  replayed: boolean;
}

export interface WorkerProgress {
  stage: "fetching" | "saving";
  current: number;
  total: number | null;
  message?: string;
}

export class LaravelApiError extends Error {
  constructor(public readonly status: number, message: string) {
    super(message);
    this.name = "LaravelApiError";
  }
}

export function loadWorkerConfig(environment: NodeJS.ProcessEnv = process.env): WorkerConfig {
  const token = String(environment.FAUNE_FRANCE_BOT_TOKEN ?? "").trim();
  if (!token) {
    throw new Error("FAUNE_FRANCE_BOT_TOKEN est absent. Copiez bot/.env.example vers bot/.env et renseignez un token identique à Laravel.");
  }

  const rawUrl = String(environment.LARAVEL_API_URL ?? "http://localhost:8000").trim();
  let url: URL;
  try {
    url = new URL(rawUrl);
  } catch {
    throw new Error("LARAVEL_API_URL n’est pas une URL valide.");
  }
  if (!['http:', 'https:'].includes(url.protocol)) {
    throw new Error("LARAVEL_API_URL doit utiliser HTTP ou HTTPS.");
  }

  const pollIntervalMs = Number(environment.BOT_POLL_INTERVAL_MS ?? 30_000);
  if (!Number.isInteger(pollIntervalMs) || pollIntervalMs < 1_000 || pollIntervalMs > 3_600_000) {
    throw new Error("BOT_POLL_INTERVAL_MS doit être un entier compris entre 1000 et 3600000.");
  }

  return {
    token,
    apiUrl: url.toString().replace(/\/$/, ""),
    pollIntervalMs
  };
}

export interface WorkerApi {
  nextJob(): Promise<SearchJob | null>;
  claim(jobId: string): Promise<SearchJob>;
  sendResults(jobId: string, batchNumber: number, isLastBatch: boolean, observations: unknown[]): Promise<BatchResponse>;
  complete(jobId: string, partial?: boolean): Promise<void>;
  fail(jobId: string, errorMessage: string): Promise<void>;
  heartbeat(jobId?: string, progress?: WorkerProgress): Promise<void>;
}

export class LaravelBotApi implements WorkerApi {
  constructor(
    private readonly config: WorkerConfig,
    private readonly fetchImplementation: typeof fetch = fetch
  ) {}

  async nextJob(): Promise<SearchJob | null> {
    const response = await this.request("GET", "/api/bot/jobs/next") as { job?: unknown };
    if (response.job === null) {
      return null;
    }
    if (response.job === undefined) {
      throw new Error("Laravel n’a pas renvoyé le champ job.");
    }
    return validateSearchJob(response.job);
  }

  async claim(jobId: string): Promise<SearchJob> {
    const response = await this.request("POST", `/api/bot/jobs/${encodeURIComponent(jobId)}/claim`) as { job?: unknown };
    return validateSearchJob(response.job);
  }

  async sendResults(jobId: string, batchNumber: number, isLastBatch: boolean, observations: unknown[]): Promise<BatchResponse> {
    const response = await this.request("POST", `/api/bot/jobs/${encodeURIComponent(jobId)}/results`, {
      batchNumber,
      isLastBatch,
      observations
    }) as Partial<BatchResponse>;
    const counts = response.counts;
    if (!counts || ![counts.created, counts.updated, counts.unchanged].every((value) => Number.isInteger(value) && Number(value) >= 0)) {
      throw new Error("Laravel a renvoyé des compteurs de lot invalides.");
    }
    return { counts, replayed: response.replayed === true };
  }

  async complete(jobId: string, partial = false): Promise<void> {
    await this.request("POST", `/api/bot/jobs/${encodeURIComponent(jobId)}/complete`, { partial });
  }

  async fail(jobId: string, errorMessage: string): Promise<void> {
    await this.request("POST", `/api/bot/jobs/${encodeURIComponent(jobId)}/fail`, {
      errorMessage: errorMessage.slice(0, 10_000)
    });
  }

  async heartbeat(jobId?: string, progress?: WorkerProgress): Promise<void> {
    await this.request("POST", "/api/bot/heartbeat", {
      ...(jobId ? { jobId } : {}),
      ...(progress ? { progress } : {})
    });
  }

  private async request(method: "GET" | "POST", route: string, body?: unknown): Promise<unknown> {
    let response: Response;
    try {
      response = await this.fetchImplementation(`${this.config.apiUrl}${route}`, {
        method,
        headers: {
          Accept: "application/json",
          Authorization: `Bearer ${this.config.token}`,
          ...(body === undefined ? {} : { "Content-Type": "application/json" })
        },
        body: body === undefined ? undefined : JSON.stringify(body),
        signal: AbortSignal.timeout(30_000)
      });
    } catch (error) {
      throw new Error(`Impossible de joindre Laravel : ${error instanceof Error ? error.message : "erreur réseau inconnue"}.`, { cause: error });
    }

    const text = await response.text();
    let payload: unknown = {};
    if (text) {
      try {
        payload = JSON.parse(text);
      } catch {
        throw new LaravelApiError(response.status, `Laravel a renvoyé une réponse non JSON (HTTP ${response.status}).`);
      }
    }
    if (!response.ok) {
      const message = payload && typeof payload === "object" && typeof (payload as { message?: unknown }).message === "string"
        ? (payload as { message: string }).message
        : `Erreur HTTP ${response.status}`;
      throw new LaravelApiError(response.status, message);
    }
    return payload;
  }
}
