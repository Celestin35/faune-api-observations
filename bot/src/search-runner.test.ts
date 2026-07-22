import assert from "node:assert/strict";
import test from "node:test";
import { SessionExpiredError } from "./faune-france.js";
import { runWithAuthenticationRetry } from "./search-runner.js";

for (const step of ["Initialisation m_id=94", "Résultats page 2"]) {
  test(`une expiration pendant ${step} reconnecte puis reprend depuis le début`, async () => {
    let authenticationChecks = 0;
    let completeSearchAttempts = 0;

    const result = await runWithAuthenticationRetry(
      async () => { authenticationChecks += 1; },
      async () => {
        completeSearchAttempts += 1;
        if (completeSearchAttempts === 1) throw new SessionExpiredError(step);
        return "recherche complète";
      }
    );

    assert.equal(result, "recherche complète");
    assert.equal(authenticationChecks, 2);
    assert.equal(completeSearchAttempts, 2);
  });
}

test("une seule reprise est tentée par tâche", async () => {
  let authenticationChecks = 0;
  let completeSearchAttempts = 0;

  await assert.rejects(runWithAuthenticationRetry(
    async () => { authenticationChecks += 1; },
    async () => {
      completeSearchAttempts += 1;
      throw new SessionExpiredError("expiration persistante");
    }
  ), SessionExpiredError);

  assert.equal(authenticationChecks, 2);
  assert.equal(completeSearchAttempts, 2);
});
