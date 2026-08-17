import { LaravelBotApi, loadWorkerConfig } from "./api-client.js";
import { runSearchJob } from "./search-runner.js";
import { processNextJob, shouldWaitBeforeNextPoll } from "./worker-core.js";

async function main(): Promise<void> {
  const config = loadWorkerConfig();
  const api = new LaravelBotApi(config);
  let stopping = false;
  let wakeFromPause: (() => void) | null = null;
  const stop = (): void => {
    stopping = true;
    wakeFromPause?.();
    console.log("Arrêt du worker demandé ; fin de l’itération courante.");
  };
  process.once("SIGINT", stop);
  process.once("SIGTERM", stop);

  console.log(`Worker Faune-France démarré. Laravel : ${config.apiUrl}. Intervalle : ${config.pollIntervalMs} ms.`);
  while (!stopping) {
    let waitBeforeNextPoll = true;
    try {
      const result = await processNextJob(api, (job) => runSearchJob(job, true, async (progress) => {
        try {
          await api.heartbeat(job.jobId, {
            stage: "fetching",
            current: progress.page,
            total: progress.maxPages,
            message: `${progress.entries} résultat(s) récupéré(s).`
          });
        } catch (error) {
          console.error(`Progression de la tâche ${job.jobId} non transmise : ${error instanceof Error ? error.message : "erreur inconnue"}.`);
        }
      }, false));
      waitBeforeNextPoll = shouldWaitBeforeNextPoll(result);
    } catch (error) {
      console.error(`Erreur pendant le polling Laravel : ${error instanceof Error ? error.message : "erreur inconnue"}.`);
    }
    if (!stopping && waitBeforeNextPoll) {
      await new Promise<void>((resolve) => {
        const timeout = setTimeout(resolve, config.pollIntervalMs);
        wakeFromPause = () => {
          clearTimeout(timeout);
          resolve();
        };
      });
      wakeFromPause = null;
    }
  }
}

main().catch((error: unknown) => {
  console.error(error instanceof Error ? `Erreur fatale du worker : ${error.message}` : "Erreur fatale inconnue du worker.");
  process.exitCode = 1;
});
