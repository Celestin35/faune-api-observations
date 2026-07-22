import assert from "node:assert/strict";
import test from "node:test";
import { buildDepartmentMask, validateConfig } from "./config.js";
import { buildSearchParameters, hasNextPage, looksLikeLoginResponse } from "./faune-france.js";

test("le masque des départements suit le format de l’extension", () => {
  const mask = buildDepartmentMask(["09", "2A", "74"]);
  assert.equal(mask.length, 100);
  assert.equal(mask[8], "1");
  assert.equal(mask[19], "1");
  assert.equal(mask[74], "1");
  assert.equal([...mask].filter((value) => value === "1").length, 3);
});

test("les paramètres imposent le Tichodrome et le numéro de page", () => {
  const config = validateConfig({
    dateFrom: "2026-06-22",
    dateTo: "2026-07-22",
    departments: ["9"],
    pagePauseMs: 1000,
    headless: true
  });
  const parameters = buildSearchParameters(config, 2);
  assert.equal(parameters.get("sp_S"), "383");
  assert.equal(parameters.get("sp_DFrom"), "22.06.2026");
  assert.equal(parameters.get("sp_DTo"), "22.07.2026");
  assert.equal(parameters.get("mp_current_page"), "2");
  assert.equal(parameters.get("sp_cC")?.length, 100);
});

test("la pagination exige un indicateur explicite", () => {
  assert.equal(hasNextPage({ data: [{}] }, 1), false);
  assert.equal(hasNextPage({ data: [{}], has_next: true }, 1), true);
  assert.equal(hasNextPage({ data: [{}], total_pages: 2 }, 1), true);
  assert.equal(hasNextPage({ data: [{}], next_page: 2 }, 1), true);
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
});
