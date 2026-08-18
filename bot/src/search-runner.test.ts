import assert from "node:assert/strict";
import test from "node:test";
import { SessionExpiredError } from "./faune-france.js";
import { applyImportLimit, runWithAuthenticationRetry, splitDateRange } from "./search-runner.js";

test("une longue recherche est découpée en périodes de 31 jours sans trou ni chevauchement", () => {
  assert.deepEqual(splitDateRange("2026-01-01", "2026-03-05"), [
    { dateFrom: "2026-01-01", dateTo: "2026-01-31" },
    { dateFrom: "2026-02-01", dateTo: "2026-03-03" },
    { dateFrom: "2026-03-04", dateTo: "2026-03-05" }
  ]);
  assert.deepEqual(splitDateRange("2026-08-18", "2026-08-18"), [
    { dateFrom: "2026-08-18", dateTo: "2026-08-18" }
  ]);
});

test("le plafond est global et coupe exactement la dernière page utile", () => {
  assert.deepEqual(applyImportLimit([1, 2, 3, 4], 8, 10, true), {
    accepted: [1, 2],
    shouldStop: true,
    truncated: true
  });
  assert.deepEqual(applyImportLimit([1, 2], 8, 10, false), {
    accepted: [1, 2],
    shouldStop: true,
    truncated: false
  });
});

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
