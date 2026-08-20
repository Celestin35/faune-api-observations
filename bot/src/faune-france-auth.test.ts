import assert from "node:assert/strict";
import test from "node:test";
import type { Page } from "playwright";
import {
  EMAIL_SELECTORS,
  FauneFranceAuthError,
  FauneFranceAuthenticator,
  LOGIN_FORM_SELECTORS,
  PASSWORD_SELECTORS,
  SUBMIT_SELECTORS
} from "./faune-france-auth.js";

type PageState = "valid" | "login" | "form-missing" | "challenge";

class FakeLocator {
  constructor(
    private readonly page: FakePage,
    private readonly selector: string,
    private readonly exists: boolean
  ) {}

  first(): FakeLocator { return this; }
  async count(): Promise<number> { return this.exists ? 1 : 0; }
  async isVisible(): Promise<boolean> { return this.exists; }
  async fill(value: string): Promise<void> {
    if (EMAIL_SELECTORS.includes(this.selector as typeof EMAIL_SELECTORS[number])) this.page.emailValue = value;
    if (PASSWORD_SELECTORS.includes(this.selector as typeof PASSWORD_SELECTORS[number])) this.page.passwordValue = value;
  }
  async check(): Promise<void> { this.page.rememberChecked = true; }
  async click(): Promise<void> {
    if (SUBMIT_SELECTORS.includes(this.selector as typeof SUBMIT_SELECTORS[number]) && this.page.acceptCredentials) {
      this.page.state = "valid";
    }
  }
  async evaluate(): Promise<void> {
    if (SUBMIT_SELECTORS.includes(this.selector as typeof SUBMIT_SELECTORS[number]) && this.page.acceptCredentials) {
      this.page.state = "valid";
    }
  }
}

class FakePage {
  emailValue = "";
  passwordValue = "";
  rememberChecked = false;

  constructor(public state: PageState, public readonly acceptCredentials = true) {}

  async goto(): Promise<null> { return null; }
  async evaluate(): Promise<boolean> { return this.state === "valid"; }
  async waitForNavigation(): Promise<null> { return null; }

  locator(selector: string): FakeLocator {
    const isForm = LOGIN_FORM_SELECTORS.includes(selector as typeof LOGIN_FORM_SELECTORS[number]);
    const isEmail = EMAIL_SELECTORS.includes(selector as typeof EMAIL_SELECTORS[number]);
    const isPassword = PASSWORD_SELECTORS.includes(selector as typeof PASSWORD_SELECTORS[number]);
    const isSubmit = SUBMIT_SELECTORS.includes(selector as typeof SUBMIT_SELECTORS[number]);
    const isRemember = selector === "#remember" || selector.includes('name="REMEMBER"');
    const isChallenge = /recaptcha|hcaptcha|sitekey|one-time-code|otp|twofactor|verification/i.test(selector);
    const loginControl = isForm || isEmail || isPassword || isSubmit || isRemember;
    const exists = (this.state === "login" && loginControl) || (this.state === "challenge" && isChallenge);
    return new FakeLocator(this, selector, exists);
  }
}

function page(fake: FakePage): Page {
  return fake as unknown as Page;
}

function logger(): { messages: string[]; log(message: string): void; error(message: string): void } {
  const messages: string[] = [];
  return {
    messages,
    log: (message) => { messages.push(message); },
    error: (message) => { messages.push(message); }
  };
}

const credentials = {
  FAUNE_FRANCE_EMAIL: "ornithologue@example.test",
  FAUNE_FRANCE_PASSWORD: "mot-de-passe-tres-secret"
};

test("une session déjà valide ne déclenche aucune reconnexion", async () => {
  const fake = new FakePage("valid");
  const logs = logger();
  const auth = new FauneFranceAuthenticator({ environment: {}, logger: logs });

  await auth.ensureAuthenticated(page(fake));

  assert.equal(auth.attempts, 0);
  assert.deepEqual(logs.messages, ["Session Faune-France valide"]);
});

test("une session expirée est reconnectée et conservée", async () => {
  const fake = new FakePage("login");
  const logs = logger();
  const auth = new FauneFranceAuthenticator({ environment: credentials, logger: logs });

  await auth.ensureAuthenticated(page(fake));

  assert.equal(fake.state, "valid");
  assert.equal(fake.rememberChecked, true);
  assert.equal(auth.attempts, 1);
  assert.deepEqual(logs.messages, ["Session expirée, tentative de reconnexion", "Reconnexion réussie"]);
});

test("des identifiants absents produisent AUTH_CREDENTIALS_MISSING", async () => {
  const auth = new FauneFranceAuthenticator({ environment: {}, logger: logger() });
  await assert.rejects(auth.ensureAuthenticated(page(new FakePage("login"))), (error: unknown) =>
    error instanceof FauneFranceAuthError && error.code === "AUTH_CREDENTIALS_MISSING");
});

test("un formulaire introuvable produit AUTH_FORM_NOT_FOUND", async () => {
  const auth = new FauneFranceAuthenticator({ environment: credentials, logger: logger() });
  await assert.rejects(auth.ensureAuthenticated(page(new FakePage("form-missing"))), (error: unknown) =>
    error instanceof FauneFranceAuthError && error.code === "AUTH_FORM_NOT_FOUND");
});

test("des identifiants refusés produisent AUTH_LOGIN_FAILED", async () => {
  const auth = new FauneFranceAuthenticator({ environment: credentials, logger: logger() });
  await assert.rejects(auth.ensureAuthenticated(page(new FakePage("login", false))), (error: unknown) =>
    error instanceof FauneFranceAuthError && error.code === "AUTH_LOGIN_FAILED");
});

test("une tâche ne peut pas effectuer une seconde tentative automatique", async () => {
  const auth = new FauneFranceAuthenticator({ environment: credentials, logger: logger() });
  const fake = new FakePage("login", false);
  await assert.rejects(auth.ensureAuthenticated(page(fake)), (error: unknown) =>
    error instanceof FauneFranceAuthError && error.code === "AUTH_LOGIN_FAILED");
  await assert.rejects(auth.ensureAuthenticated(page(fake)), (error: unknown) =>
    error instanceof FauneFranceAuthError && error.code === "AUTH_MANUAL_INTERVENTION_REQUIRED");
  assert.equal(auth.attempts, 1);
});

test("une validation interactive demande une intervention manuelle", async () => {
  const auth = new FauneFranceAuthenticator({ environment: credentials, logger: logger() });
  await assert.rejects(auth.ensureAuthenticated(page(new FakePage("challenge"))), (error: unknown) =>
    error instanceof FauneFranceAuthError && error.code === "AUTH_MANUAL_INTERVENTION_REQUIRED");
});

test("les logs d’authentification ne contiennent aucun secret", async () => {
  const logs = logger();
  const auth = new FauneFranceAuthenticator({ environment: credentials, logger: logs });
  await auth.ensureAuthenticated(page(new FakePage("login")));

  const output = logs.messages.join("\n");
  assert.equal(output.includes(credentials.FAUNE_FRANCE_EMAIL), false);
  assert.equal(output.includes(credentials.FAUNE_FRANCE_PASSWORD), false);
});
