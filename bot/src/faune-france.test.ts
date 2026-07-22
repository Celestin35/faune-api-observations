import assert from "node:assert/strict";
import test from "node:test";
import type { Page } from "playwright";
import { buildDepartmentMask, validateConfig } from "./config.js";
import {
  buildSearchParameters,
  assertLiveAuthenticatedSession,
  decidePagination,
  fingerprintPageData,
  looksLikeLoginResponse,
  parseDataIsFinished
} from "./faune-france.js";

test("le masque des départements suit le format de l’extension", () => {
  const mask = buildDepartmentMask(["09", "2A", "74"]);
  assert.equal(mask.length, 100);
  assert.equal(mask[8], "1");
  assert.equal(mask[19], "1");
  assert.equal(mask[74], "1");
  assert.equal([...mask].filter((value) => value === "1").length, 3);
});

test("les paramètres utilisent le taxon fourni et le numéro de page", () => {
  const config = validateConfig({
    dateFrom: "2026-06-22",
    dateTo: "2026-07-22",
    departments: ["9"],
    pagePauseMs: 1000,
    maxPages: 100,
    headless: true
  });
  const parameters = buildSearchParameters({
    ...config,
    taxon: { fauneFranceId: "9999" }
  }, 2);
  assert.equal(parameters.get("sp_S"), "9999");
  assert.equal(parameters.get("sp_SChoice"), "species");
  assert.equal(parameters.get("sp_DFrom"), "22.06.2026");
  assert.equal(parameters.get("sp_DTo"), "22.07.2026");
  assert.equal(parameters.get("mp_current_page"), "2");
  assert.equal(parameters.get("sp_cC")?.length, 100);
});

test("une seule page marquée terminée arrête la pagination", () => {
  const decision = decidePagination({ data: [{ id: 1 }], data_is_finished: 1 }, 1, 100, null);
  assert.equal(decision.continue, false);
  assert.equal(decision.stopReason, "finished");
  assert.equal(decision.truncatedBySafetyLimit, false);
});

test("plusieurs pages continuent jusqu’au marqueur de fin", () => {
  const first = decidePagination({ data: [{ id: 1 }], data_is_finished: 0 }, 1, 100, null);
  assert.equal(first.continue, true);
  const second = decidePagination({ data: [{ id: 2 }], data_is_finished: 0 }, 2, 100, first.fingerprint);
  assert.equal(second.continue, true);
  const third = decidePagination({ data: [{ id: 3 }], data_is_finished: 1 }, 3, 100, second.fingerprint);
  assert.equal(third.continue, false);
  assert.equal(third.stopReason, "finished");
});

test("data_is_finished accepte les booléens", () => {
  assert.equal(parseDataIsFinished(false).finished, false);
  assert.equal(parseDataIsFinished(true).finished, true);
  assert.equal(parseDataIsFinished(true).type, "boolean");
});

test("data_is_finished accepte les nombres réels observés et leurs chaînes équivalentes", () => {
  assert.deepEqual(parseDataIsFinished(0), { value: 0, type: "number", finished: false });
  assert.deepEqual(parseDataIsFinished(1), { value: 1, type: "number", finished: true });
  assert.equal(parseDataIsFinished("0").finished, false);
  assert.equal(parseDataIsFinished("1").finished, true);
  assert.equal(parseDataIsFinished("false").finished, false);
  assert.equal(parseDataIsFinished("true").finished, true);
});

test("une page vide arrête la pagination même si le marqueur demande de continuer", () => {
  const decision = decidePagination({ data: [], data_is_finished: 0 }, 2, 100, null);
  assert.equal(decision.continue, false);
  assert.equal(decision.stopReason, "empty-page");
});

test("deux pages successives identiques arrêtent la pagination", () => {
  const data = [{ id: 1, date: "2026-07-22" }];
  const previousFingerprint = fingerprintPageData(data);
  const decision = decidePagination({ data, data_is_finished: 0 }, 2, 100, previousFingerprint);
  assert.equal(decision.continue, false);
  assert.equal(decision.repeated, true);
  assert.equal(decision.stopReason, "repeated-page");
});

test("la limite maximale marque les résultats comme tronqués", () => {
  const decision = decidePagination({ data: [{ id: 100 }], data_is_finished: 0 }, 100, 100, null);
  assert.equal(decision.continue, false);
  assert.equal(decision.stopReason, "safety-limit");
  assert.equal(decision.truncatedBySafetyLimit, true);
});

test("une redirection ou une page de connexion signale une session expirée", () => {
  assert.equal(looksLikeLoginResponse({
    status: 200,
    url: "https://www.faune-france.org/index.php?m_id=30494",
    redirected: true,
    contentType: "text/html",
    body: ""
  }), true);
  assert.equal(looksLikeLoginResponse({
    status: 200,
    url: "https://www.faune-france.org/index.php?m_id=94",
    redirected: false,
    contentType: "text/html",
    body: '<title>Login</title><input type="email"><input type="password">'
  }), true);
  assert.equal(looksLikeLoginResponse({
    status: 200,
    url: "https://www.faune-france.org/index.php?m_id=94",
    redirected: false,
    contentType: "text/html",
    body: '<form name="loginform"><input name="USERNAME"><input type="password" name="PASSWORD"></form>'
  }), true);
});

test("le contrôle distant détecte une session expirée malgré une page locale encore affichée", async () => {
  const simulatedPage = {
    evaluate: async () => ({ status: 200, authenticated: false })
  } as unknown as Page;
  await assert.rejects(assertLiveAuthenticatedSession(simulatedPage, "Résultats page 1"), /Session Faune-France expirée/);
});
