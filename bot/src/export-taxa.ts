import { mkdir, writeFile } from "node:fs/promises";
import path from "node:path";
import { firefox } from "playwright";
import { OUTPUT_DIR, PROFILE_DIR } from "./config.js";
import { FauneFranceAuthenticator } from "./faune-france-auth.js";
import { readFauneFranceTaxonCatalog } from "./faune-france-taxa.js";

function argumentsConfig(argumentsList: string[]): { output: string; headless: boolean } {
  let output = path.join(OUTPUT_DIR, "faune-france-taxa.json");
  let headless = true;
  for (const argument of argumentsList) {
    if (argument === "--headed") {
      headless = false;
    } else if (argument.startsWith("--output=")) {
      const value = argument.slice("--output=".length).trim();
      if (!value) throw new Error("--output doit contenir un chemin.");
      output = path.resolve(process.cwd(), value);
    } else {
      throw new Error("Usage : npm run export-taxa -- [--headed] [--output=./data/output/faune-france-taxa.json]");
    }
  }
  return { output, headless };
}

async function main(): Promise<void> {
  const config = argumentsConfig(process.argv.slice(2));
  await mkdir(PROFILE_DIR, { recursive: true });
  await mkdir(path.dirname(config.output), { recursive: true });
  const context = await firefox.launchPersistentContext(PROFILE_DIR, { headless: config.headless });
  try {
    const page = context.pages()[0] ?? await context.newPage();
    await new FauneFranceAuthenticator().ensureAuthenticated(page);
    const catalog = await readFauneFranceTaxonCatalog(page);
    await writeFile(config.output, `${JSON.stringify(catalog, null, 2)}\n`, "utf8");
    const selectable = catalog.entries.filter((entry) => entry.selectable).length;
    console.log(`Catalogue Faune-France exporté : ${catalog.entries.length} taxons, dont ${selectable} sélectionnables.`);
    console.log(`Fichier : ${config.output}`);
  } finally {
    await context.close();
  }
}

main().catch((error: unknown) => {
  console.error(error instanceof Error ? `Erreur : ${error.message}` : "Erreur inconnue pendant l’export taxonomique.");
  process.exitCode = 1;
});
