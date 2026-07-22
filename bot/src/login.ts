import { mkdir } from "node:fs/promises";
import { createInterface } from "node:readline/promises";
import { stdin as input, stdout as output } from "node:process";
import { firefox } from "playwright";
import { PROFILE_DIR } from "./config.js";
import { BASE_URL, pageClearlyShowsLogin, pageShowsAuthenticatedSession } from "./faune-france.js";

async function main(): Promise<void> {
  await mkdir(PROFILE_DIR, { recursive: true });
  console.log(`Profil Playwright dédié : ${PROFILE_DIR}`);
  console.log("Aucun identifiant n’est lu par ce script : saisissez-les uniquement dans la page Faune-France.");

  const context = await firefox.launchPersistentContext(PROFILE_DIR, { headless: false });
  const page = context.pages()[0] ?? await context.newPage();
  await page.goto(BASE_URL, { waitUntil: "domcontentloaded", timeout: 45_000 });

  const readline = createInterface({ input, output });
  try {
    await readline.question("Connectez-vous manuellement dans Firefox, puis appuyez sur Entrée ici pour enregistrer et fermer la session… ");
    if (await pageClearlyShowsLogin(page)) {
      throw new Error("La page affiche toujours le formulaire de connexion. La session n’a pas été enregistrée.");
    }
    if (!await pageShowsAuthenticatedSession(page)) {
      throw new Error("Aucun marqueur de session connectée n’est visible. Vérifiez que la connexion Faune-France est terminée avant d’appuyer sur Entrée.");
    }
    console.log("Le marqueur de session connectée est visible. Fermeture propre du profil pour conserver la session.");
  } finally {
    readline.close();
    await context.close();
  }
}

main().catch((error: unknown) => {
  console.error(error instanceof Error ? `Erreur : ${error.message}` : "Erreur inconnue pendant la connexion.");
  process.exitCode = 1;
});
