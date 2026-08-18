import { loadConfig } from "./config.js";
import type { SearchJob } from "./job.js";
import { reportSearchError, runSearchJob } from "./search-runner.js";

async function main(): Promise<void> {
  const config = await loadConfig();
  const legacyJob: SearchJob = {
    jobId: "test-search",
    filter: {
      mode: "species",
      taxonomicGroupId: 1,
      fauneFranceId: "383",
      scientificName: "Tichodroma muraria",
      vernacularName: "Tichodrome échelette",
      label: "Tichodrome échelette"
    },
    dateFrom: config.dateFrom,
    dateTo: config.dateTo,
    importLimit: 10_000,
    departments: config.departments,
    maxPages: config.maxPages,
    pagePauseMs: config.pagePauseMs
  };
  await runSearchJob(legacyJob, config.headless);
}

main().catch(reportSearchError);
