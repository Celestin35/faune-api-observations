import { jobPathFromArguments, loadSearchJob } from "./job.js";
import { reportSearchError, runSearchJob } from "./search-runner.js";

async function main(): Promise<void> {
  const jobPath = jobPathFromArguments(process.argv.slice(2));
  const job = await loadSearchJob(jobPath);
  await runSearchJob(job, true);
}

main().catch(reportSearchError);
