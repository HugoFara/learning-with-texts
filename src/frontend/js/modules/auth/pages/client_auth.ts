/**
 * Client Auth Alpine.js component — "choose server + log in" flow.
 *
 * The entry flow for a packaged client (the planned Capacitor/F-Droid app),
 * which serves its UI locally and talks to a user-chosen LWT server over the
 * REST API. It is a two-step flow in a single component:
 *
 *   1. `server` — enter a server address, validated by probing the public
 *      `/api/v1/version` endpoint; on success the choice is stored via
 *      `setApiServer` (persisted to localStorage).
 *   2. `login`  — username + password posted to `/api/v1/auth/login`; the
 *      returned bearer token is stored via `setAuthToken`, after which every
 *      API call carries `Authorization: Bearer …`.
 *
 * Unlike the server-rendered cookie login (`login.php`), this works
 * cross-origin: cookies are not sent to a remote server, so token auth is the
 * mechanism. The server must allow the client origin via `CORS_ALLOWED_ORIGINS`.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.1.1
 */

import Alpine from 'alpinejs';
import {
  apiGet,
  apiPost,
  setApiServer,
  getApiServer,
  setAuthToken,
  getAuthToken,
  setAuthOptional,
  isAuthOptional,
  probeAuthRequirement,
  type ApiResponse
} from '@shared/api/client';
import { solveAltcha } from '@shared/altcha/solve_altcha';
import { url } from '@shared/utils/url';
import { readPageConfig } from '@shared/utils/page_config';

interface VersionResponse {
  version?: string;
}

interface AuthResponse {
  success?: boolean;
  token?: string;
  expires_at?: string | null;
  error?: string;
  /** One-time recovery code, returned when registering without an email. */
  recovery_code?: string;
}

interface ClientAuthData {
  step: 'server' | 'login' | 'recovery';
  authMode: 'login' | 'register';
  serverUrl: string;
  username: string;
  email: string;
  password: string;
  passwordConfirm: string;
  /** Honeypot field — bound to a hidden input; bots fill it, humans don't. */
  homepage: string;
  /** One-time recovery code to show once after an email-less registration. */
  recoveryCode: string;
  loading: boolean;
  error: string;
  homeUrl: string;
  readonly onServerStep: boolean;
  readonly onLoginStep: boolean;
  readonly onRecoveryStep: boolean;
  readonly onLoginMode: boolean;
  readonly onRegisterMode: boolean;
  init(): void;
  normalizeServerUrl(input: string): string;
  connect(): Promise<void>;
  back(): void;
  showRegister(): void;
  showLogin(): void;
  submitLogin(event: Event): Promise<void>;
  submitRegister(event: Event): Promise<void>;
  continueAfterRecovery(): void;
  finishAuth(res: ApiResponse<AuthResponse>, fallbackError: string): void;
  onAuthenticated(): void;
}

/**
 * Read optional config injected by the host page:
 * `<script type="application/json" id="client-auth-config">`.
 */
function readConfig(): { defaultServer: string; homeUrl: string } {
  return readPageConfig('client-auth-config', {
    defaultServer: '',
    homeUrl: url('/')
  });
}

/**
 * Alpine.js data component for the packaged-client auth flow.
 */
export function clientAuthData(): ClientAuthData {
  return {
    step: 'server',
    authMode: 'login',
    serverUrl: '',
    username: '',
    email: '',
    password: '',
    passwordConfirm: '',
    homepage: '',
    recoveryCode: '',
    loading: false,
    error: '',
    homeUrl: '/',

    // CSP-safe step/mode flags for x-show (the @alpinejs/csp evaluator handles
    // property access, not `step === '…'` string comparisons in templates).
    get onServerStep(): boolean {
      return this.step === 'server';
    },

    get onLoginStep(): boolean {
      return this.step === 'login';
    },

    get onRecoveryStep(): boolean {
      return this.step === 'recovery';
    },

    get onLoginMode(): boolean {
      return this.authMode === 'login';
    },

    get onRegisterMode(): boolean {
      return this.authMode === 'register';
    },

    init(): void {
      const config = readConfig();
      this.homeUrl = config.homeUrl;

      // Already signed in (token persisted from a previous launch): skip the
      // whole flow.
      if (getAuthToken()) {
        this.onAuthenticated();
        return;
      }

      // Server already chosen previously.
      const knownServer = getApiServer();
      if (knownServer) {
        // Known to need no login (single-user self-host): go straight in.
        if (isAuthOptional()) {
          this.onAuthenticated();
          return;
        }
        // Otherwise jump straight to the login step.
        this.serverUrl = knownServer;
        this.step = 'login';
        return;
      }

      this.serverUrl = config.defaultServer;
    },

    /**
     * Trim, drop a trailing slash, and default the scheme to https so a user
     * can type just a hostname.
     */
    normalizeServerUrl(input: string): string {
      let value = input.trim().replace(/\/+$/, '');
      if (value !== '' && !/^https?:\/\//i.test(value)) {
        value = 'https://' + value;
      }
      return value;
    },

    async connect(): Promise<void> {
      const server = this.normalizeServerUrl(this.serverUrl);
      if (server === '') {
        this.error = 'Please enter a server address.';
        return;
      }

      this.loading = true;
      this.error = '';
      this.serverUrl = server;

      // Point the client at the candidate server and probe the public version
      // endpoint to confirm it is a reachable LWT server (and that CORS allows
      // this origin).
      setApiServer(server);
      const res = await apiGet<VersionResponse>('/version');

      if (res.error || !res.data || !res.data.version) {
        this.loading = false;
        setApiServer(null); // roll back the bad choice
        this.error =
          'Could not reach an LWT server at that address. Check the URL and '
          + 'that the server allows this app.';
        return;
      }

      // Reachable. Does this server require a login? A single-user self-host
      // treats every endpoint as public, so there is nothing to log in to —
      // enter the app directly. Multi-user servers (401) show the login step.
      const authMode = await probeAuthRequirement();
      this.loading = false;

      if (authMode === 'optional') {
        setAuthOptional(true);
        this.onAuthenticated();
        return;
      }

      this.step = 'login';
    },

    back(): void {
      setApiServer(null);
      this.step = 'server';
      this.error = '';
    },

    async submitLogin(event: Event): Promise<void> {
      event.preventDefault();

      const username = this.username.trim();
      if (username === '' || this.password === '') {
        this.error = 'Enter your username and password.';
        return;
      }

      this.loading = true;
      this.error = '';

      const res = await apiPost<AuthResponse>('/auth/login', {
        username,
        password: this.password
      });
      this.loading = false;
      this.finishAuth(res, 'Login failed.');
    },

    showRegister(): void {
      this.authMode = 'register';
      this.error = '';
    },

    showLogin(): void {
      this.authMode = 'login';
      this.error = '';
    },

    async submitRegister(event: Event): Promise<void> {
      event.preventDefault();

      const username = this.username.trim();
      const email = this.email.trim();
      // Email is optional — the username is the unique identity.
      if (username === '' || this.password === '') {
        this.error = 'Enter a username and password.';
        return;
      }
      if (this.password !== this.passwordConfirm) {
        this.error = 'Passwords do not match.';
        return;
      }

      this.loading = true;
      this.error = '';

      // Solve the proof-of-work captcha before submitting.
      const altcha = await solveAltcha();

      const res = await apiPost<AuthResponse>('/auth/register', {
        username,
        email,
        password: this.password,
        password_confirm: this.passwordConfirm,
        // Honeypot — always empty for a real user; the server rejects if filled.
        homepage: this.homepage,
        altcha
      });
      this.loading = false;

      // Email-less account: the server returns a one-time recovery code. Store
      // the token, then show the code once before entering the app.
      const data = res.data;
      if (!res.error && data && data.success === true && data.token && data.recovery_code) {
        setAuthToken(data.token, data.expires_at ?? null);
        this.password = '';
        this.passwordConfirm = '';
        this.recoveryCode = data.recovery_code;
        this.step = 'recovery';
        return;
      }

      this.finishAuth(res, 'Registration failed.');
    },

    /** Leave the one-time recovery-code screen and enter the app. */
    continueAfterRecovery(): void {
      this.recoveryCode = '';
      this.onAuthenticated();
    },

    /**
     * Apply a login/register API result: on success store the token (with its
     * expiry) and enter the app; otherwise surface the error. The handlers
     * return HTTP 200 with `success: false` for validation/credential errors,
     * so both the transport error and the body are checked.
     */
    finishAuth(res: ApiResponse<AuthResponse>, fallbackError: string): void {
      if (res.error) {
        this.error = res.error;
        return;
      }
      const data = res.data;
      if (!data || data.success !== true || !data.token) {
        this.error = data && data.error ? data.error : fallbackError;
        return;
      }

      setAuthToken(data.token, data.expires_at ?? null);
      this.password = '';
      this.passwordConfirm = '';
      this.onAuthenticated();
    },

    /**
     * Navigate into the app after a successful login. Overridable (e.g. in
     * tests, or by a host that wants in-app routing instead of a reload).
     */
    onAuthenticated(): void {
      window.location.assign(this.homeUrl);
    }
  };
}

/**
 * Register the component. Must run before Alpine.start().
 */
export function initClientAuthAlpine(): void {
  Alpine.data('clientAuth', clientAuthData);
}

declare global {
  interface Window {
    clientAuthData: typeof clientAuthData;
    initClientAuthAlpine: typeof initClientAuthAlpine;
  }
}

window.clientAuthData = clientAuthData;
window.initClientAuthAlpine = initClientAuthAlpine;

initClientAuthAlpine();
