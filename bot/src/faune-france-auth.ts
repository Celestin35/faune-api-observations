import type { Locator, Page } from "playwright";
import { BASE_URL, pageShowsAuthenticatedSession } from "./faune-france.js";

export type AuthErrorCode =
  | "AUTH_CREDENTIALS_MISSING"
  | "AUTH_FORM_NOT_FOUND"
  | "AUTH_LOGIN_FAILED"
  | "AUTH_MANUAL_INTERVENTION_REQUIRED";

export class FauneFranceAuthError extends Error {
  constructor(public readonly code: AuthErrorCode, details: string) {
    super(`${code}: ${details}`);
    this.name = "FauneFranceAuthError";
  }
}

export interface AuthCredentials {
  email: string;
  password: string;
}

export interface AuthLogger {
  log(message: string): void;
  error(message: string): void;
}

export const LOGIN_FORM_SELECTORS = [
  "form#confm",
  'form[name="loginform"]',
  'form:has(input[name="USERNAME"]):has(input[name="PASSWORD"])'
] as const;

export const EMAIL_SELECTORS = [
  "#loginemail",
  'form#confm input[name="USERNAME"]',
  'form[name="loginform"] input[name="USERNAME"]',
  'input[name="USERNAME"]',
  'input[type="email"]'
] as const;

export const PASSWORD_SELECTORS = [
  'form#confm input[name="PASSWORD"]',
  'form[name="loginform"] input[name="PASSWORD"]',
  'input[name="PASSWORD"]',
  'input[type="password"]'
] as const;

export const SUBMIT_SELECTORS = [
  'form#confm input[name="login_button"]',
  'form[name="loginform"] input[name="login_button"]',
  'form#confm button[type="submit"]',
  'form#confm input[type="submit"]'
] as const;

const REMEMBER_SELECTORS = [
  "#remember",
  'form#confm input[name="REMEMBER"]',
  'form[name="loginform"] input[name="REMEMBER"]'
] as const;

const CHALLENGE_SELECTORS = [
  'iframe[src*="recaptcha" i]',
  'iframe[src*="hcaptcha" i]',
  ".g-recaptcha",
  ".h-captcha",
  "[data-sitekey]",
  'input[autocomplete="one-time-code"]',
  'input[name*="otp" i]',
  'input[name*="twofactor" i]',
  'input[name*="verification" i]'
] as const;

const LOGIN_REJECTED_SELECTORS = [
  ".errorError",
  '[role="alert"]',
  '.alert-danger',
  '.login-error'
] as const;

export function loadAuthCredentials(environment: NodeJS.ProcessEnv = process.env): AuthCredentials {
  const email = String(environment.FAUNE_FRANCE_EMAIL ?? "").trim();
  const password = String(environment.FAUNE_FRANCE_PASSWORD ?? "");
  if (!email || !password) {
    throw new FauneFranceAuthError(
      "AUTH_CREDENTIALS_MISSING",
      "les identifiants sont absents du fichier bot/.env ; utilisez npm run login en secours."
    );
  }
  return { email, password };
}

async function firstVisible(page: Page, selectors: readonly string[]): Promise<Locator | null> {
  for (const selector of selectors) {
    const locator = page.locator(selector).first();
    if (await locator.count() > 0 && await locator.isVisible().catch(() => false)) {
      return locator;
    }
  }
  return null;
}

async function revealLoginForm(page: Page): Promise<void> {
  const hiddenForm = page.locator(LOGIN_FORM_SELECTORS.join(", ")).first();
  if (await hiddenForm.count() === 0 || await hiddenForm.isVisible().catch(() => false)) {
    return;
  }

  await page.waitForFunction(
    () => typeof (globalThis as typeof globalThis & { menu?: { toggleConnect?: () => void } }).menu?.toggleConnect === "function",
    undefined,
    { timeout: 5_000 }
  ).then(async () => {
    await page.evaluate(() => {
      (globalThis as typeof globalThis & { menu?: { toggleConnect?: () => void } }).menu?.toggleConnect?.();
    });
  }).catch(() => undefined);

  if (!await hiddenForm.isVisible().catch(() => false)) {
    await hiddenForm.evaluate((element) => {
      if (element instanceof HTMLElement) element.style.display = "block";
    }).catch(() => undefined);
  }
}

async function challengeVisible(page: Page): Promise<boolean> {
  return (await firstVisible(page, CHALLENGE_SELECTORS)) !== null;
}

export interface AuthenticatorOptions {
  environment?: NodeJS.ProcessEnv;
  logger?: AuthLogger;
  navigationTimeoutMs?: number;
}

export class FauneFranceAuthenticator {
  private reconnectAttempts = 0;
  private readonly logger: AuthLogger;
  private readonly environment: NodeJS.ProcessEnv;
  private readonly navigationTimeoutMs: number;

  constructor(options: AuthenticatorOptions = {}) {
    this.logger = options.logger ?? console;
    this.environment = options.environment ?? process.env;
    this.navigationTimeoutMs = options.navigationTimeoutMs ?? 45_000;
  }

  get attempts(): number {
    return this.reconnectAttempts;
  }

  async ensureAuthenticated(page: Page): Promise<void> {
    await page.goto(BASE_URL, { waitUntil: "domcontentloaded", timeout: this.navigationTimeoutMs });
    if (await pageShowsAuthenticatedSession(page)) {
      this.logger.log("Session Faune-France valide");
      return;
    }

    if (this.reconnectAttempts >= 1) {
      this.logger.error("Intervention manuelle nécessaire");
      throw new FauneFranceAuthError(
        "AUTH_MANUAL_INTERVENTION_REQUIRED",
        "la seule tentative de reconnexion autorisée pour cette tâche a déjà été utilisée ; lancez npm run login."
      );
    }

    this.logger.log("Session expirée, tentative de reconnexion");
    this.reconnectAttempts += 1;

    if (await challengeVisible(page)) {
      this.logger.error("Intervention manuelle nécessaire");
      throw new FauneFranceAuthError(
        "AUTH_MANUAL_INTERVENTION_REQUIRED",
        "une validation interactive est présente ; lancez npm run login."
      );
    }

    await revealLoginForm(page);
    const form = await firstVisible(page, LOGIN_FORM_SELECTORS);
    if (!form) {
      throw new FauneFranceAuthError(
        "AUTH_FORM_NOT_FOUND",
        "le formulaire de connexion Faune-France est introuvable ; lancez npm run login."
      );
    }

    const credentials = loadAuthCredentials(this.environment);
    const emailField = await firstVisible(page, EMAIL_SELECTORS);
    const passwordField = await firstVisible(page, PASSWORD_SELECTORS);
    const submit = await firstVisible(page, SUBMIT_SELECTORS);
    if (!emailField || !passwordField || !submit) {
      throw new FauneFranceAuthError(
        "AUTH_FORM_NOT_FOUND",
        "un champ requis du formulaire de connexion est introuvable ; lancez npm run login."
      );
    }

    await emailField.fill(credentials.email);
    await passwordField.fill(credentials.password);
    const remember = await firstVisible(page, REMEMBER_SELECTORS);
    if (remember) {
      await remember.check().catch(() => undefined);
    }

    await Promise.all([
      page.waitForNavigation({ waitUntil: "domcontentloaded", timeout: this.navigationTimeoutMs }).catch(() => null),
      submit.click()
    ]);

    if (await pageShowsAuthenticatedSession(page)) {
      this.logger.log("Reconnexion réussie");
      return;
    }
    if (await challengeVisible(page)) {
      this.logger.error("Intervention manuelle nécessaire");
      throw new FauneFranceAuthError(
        "AUTH_MANUAL_INTERVENTION_REQUIRED",
        "Faune-France demande une validation interactive ; lancez npm run login."
      );
    }
    if (await firstVisible(page, LOGIN_REJECTED_SELECTORS)) {
      throw new FauneFranceAuthError(
        "AUTH_LOGIN_FAILED",
        "Faune-France a refusé la connexion ; vérifiez bot/.env ou lancez npm run login."
      );
    }
    if (await firstVisible(page, LOGIN_FORM_SELECTORS)) {
      throw new FauneFranceAuthError(
        "AUTH_LOGIN_FAILED",
        "Faune-France a refusé la connexion ; vérifiez bot/.env ou lancez npm run login."
      );
    }

    this.logger.error("Intervention manuelle nécessaire");
    throw new FauneFranceAuthError(
      "AUTH_MANUAL_INTERVENTION_REQUIRED",
      "la connexion mène à une étape inconnue ; lancez npm run login."
    );
  }
}

export async function ensureAuthenticated(page: Page, options: AuthenticatorOptions = {}): Promise<void> {
  await new FauneFranceAuthenticator(options).ensureAuthenticated(page);
}
