/**
 * Centralized API client for all backend communication.
 *
 * Replaces jQuery AJAX with modern fetch API.
 * Provides type-safe wrappers for GET, POST, PUT, DELETE requests.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.0.0
 */

/**
 * Standard response wrapper for all API calls.
 */
export interface ApiResponse<T> {
  data?: T;
  error?: string;
}

/**
 * Configuration for the API client.
 */
export interface ApiClientConfig {
  baseUrl: string;
  defaultHeaders?: Record<string, string>;
}

/**
 * Get the application base path from meta tag.
 */
function getBasePath(): string {
  const meta = document.querySelector('meta[name="lwt-base-path"]');
  return meta ? meta.getAttribute('content') || '' : '';
}

/**
 * In-memory override for the API server root, set via {@link setApiServer}.
 * `null` means "no override — consult localStorage/meta"; a non-empty string is
 * the active override. It is never set to '' (a reset clears it back to `null`).
 */
let overrideApiServer: string | null = null;

/**
 * Safely read the persisted API server. Returns '' when localStorage is
 * unavailable (privacy modes, non-DOM test environments, etc.).
 */
function readStoredApiServer(): string {
  try {
    return localStorage.getItem('lwt.apiServer') || '';
  } catch {
    return '';
  }
}

/**
 * Resolve the configured API server **root** (scheme + host, optionally a
 * sub-path), or '' when the app should talk to its own origin.
 *
 * Precedence:
 *   1. runtime override set via {@link setApiServer}
 *   2. persisted choice in localStorage (`lwt.apiServer`)
 *   3. `<meta name="lwt-api-server">` (optional server-declared default)
 *   4. '' — same-origin (the classic web-app behavior)
 *
 * Returning '' keeps every existing same-origin install byte-for-byte
 * identical; a non-empty value lets a packaged client (e.g. a Capacitor shell
 * for F-Droid) point at a user-chosen LWT server.
 */
function getConfiguredApiServer(): string {
  if (overrideApiServer !== null) {
    return overrideApiServer;
  }
  const stored = readStoredApiServer();
  if (stored) {
    return stored;
  }
  const meta = document.querySelector('meta[name="lwt-api-server"]');
  return meta ? meta.getAttribute('content') || '' : '';
}

/**
 * Compute the full API root (ending in `/api/v1`) for the current request.
 *
 * - Remote server configured -> `https://server[/subpath]/api/v1` (absolute).
 * - Nothing configured        -> `<base-path>/api/v1` (relative, same-origin).
 */
function resolveApiRoot(): string {
  const server = getConfiguredApiServer();
  if (server) {
    return server.replace(/\/+$/, '') + '/api/v1';
  }
  return getBasePath() + '/api/v1';
}

/**
 * Point the API client at a specific LWT server, persisting the choice to
 * localStorage so a packaged client remembers it across launches. Pass
 * `null`/'' to reset — forgetting both the override and the persisted value so
 * resolution falls back to localStorage/meta/same-origin.
 *
 * This function owns URL construction only. Cross-origin use additionally
 * requires the server to send permissive CORS headers and — for cookie
 * sessions — `credentials: 'include'`; token auth avoids the CSRF-meta
 * dependency. See ROADMAP.md (Phase 1) for those follow-ups.
 */
export function setApiServer(server: string | null): void {
  // Changing (or clearing) the server invalidates any cached knowledge of
  // whether that server needed a login — re-probed on the next connect.
  setAuthOptional(false);
  const normalized = (server || '').trim().replace(/\/+$/, '');
  if (!normalized) {
    // Reset: forget the override and any persisted choice.
    overrideApiServer = null;
    try {
      localStorage.removeItem('lwt.apiServer');
    } catch {
      // localStorage unavailable: nothing persisted to clear.
    }
    return;
  }
  overrideApiServer = normalized;
  try {
    localStorage.setItem('lwt.apiServer', normalized);
  } catch {
    // localStorage unavailable: the in-memory override still applies for
    // the rest of this session.
  }
}

/**
 * Persisted flag: the configured server does not require authentication (e.g. a
 * single-user self-host, where the API treats every endpoint as public). When
 * set, a packaged client can enter the app with no bearer token. Determined by
 * {@link probeAuthRequirement} during the connect flow and persisted so a
 * relaunch skips the login step. Cleared by {@link setApiServer}.
 */
const AUTH_OPTIONAL_KEY = 'lwt.authOptional';

/** Mark whether the current server lets a client in without authentication. */
export function setAuthOptional(optional: boolean): void {
  try {
    if (optional) {
      localStorage.setItem(AUTH_OPTIONAL_KEY, '1');
    } else {
      localStorage.removeItem(AUTH_OPTIONAL_KEY);
    }
  } catch {
    // localStorage unavailable: detection just won't persist across launches.
  }
}

/** True when the configured server was found not to require authentication. */
export function isAuthOptional(): boolean {
  try {
    return localStorage.getItem(AUTH_OPTIONAL_KEY) === '1';
  } catch {
    return false;
  }
}

/** Outcome of probing whether the configured server requires authentication. */
export type AuthRequirement = 'required' | 'optional' | 'unknown';

/**
 * Probe whether the configured server requires authentication, by requesting a
 * normally-protected endpoint (`/languages`) *without* a token:
 *   - HTTP 401  -> 'required'  (multi-user server: show the login step)
 *   - HTTP 2xx  -> 'optional'  (single-user server: every endpoint is public)
 *   - anything else / network error -> 'unknown' (caller should assume login)
 *
 * Must be called after {@link setApiServer} but before a token is obtained.
 */
export async function probeAuthRequirement(): Promise<AuthRequirement> {
  try {
    const response = await fetch(resolveApiRoot() + '/languages', {
      headers: { Accept: 'application/json' }
    });
    if (response.status === 401) {
      return 'required';
    }
    if (response.ok) {
      return 'optional';
    }
    return 'unknown';
  } catch {
    return 'unknown';
  }
}

/**
 * The API server root the client is currently using, or '' for same-origin.
 */
export function getApiServer(): string {
  return getConfiguredApiServer();
}

/**
 * Read the CSRF token from `<meta name="csrf-token">`. Exported so
 * non-API-client callers (handleRestDelete in texts_grouped_app, etc.)
 * can attach the same `X-CSRF-TOKEN` header that CsrfMiddleware checks
 * on POST/PUT/DELETE/PATCH.
 */
export function getCsrfToken(): string {
  const meta = document.querySelector('meta[name="csrf-token"]');
  return meta ? meta.getAttribute('content') || '' : '';
}

/**
 * Build headers that include CSRF for state-changing requests.
 */
function withCsrf(headers: Record<string, string>): Record<string, string> {
  const token = getCsrfToken();
  if (!token) return headers;
  return { ...headers, 'X-CSRF-TOKEN': token };
}

/**
 * In-memory bearer token, set via {@link setAuthToken}. `null` means "consult
 * localStorage"; '' is never stored (a reset clears it back to `null`).
 */
let authTokenOverride: string | null = null;

/**
 * Safely read the persisted bearer token. Returns '' when localStorage is
 * unavailable.
 */
function readStoredAuthToken(): string {
  try {
    return localStorage.getItem('lwt.apiToken') || '';
  } catch {
    return '';
  }
}

/**
 * The bearer token the client currently sends, or '' when unauthenticated.
 * Precedence: runtime value set via {@link setAuthToken} > localStorage
 * (`lwt.apiToken`).
 */
export function getAuthToken(): string {
  if (authTokenOverride !== null) {
    return authTokenOverride;
  }
  return readStoredAuthToken();
}

/**
 * Store (or clear) the API bearer token obtained from `POST /api/v1/auth/login`
 * (or `/auth/register`, `/auth/refresh`). Persisted to localStorage so a
 * packaged client stays signed in across launches. Pass `null`/'' to clear,
 * e.g. on logout.
 *
 * `expiresAt` (the ISO-8601 `expires_at` the auth endpoints return) is stored
 * alongside so the client can refresh proactively before it lapses
 * ({@link maybeRefreshAuthToken}).
 *
 * When set, every request carries `Authorization: Bearer <token>`. This is how
 * a cross-origin client authenticates, since cookies are not sent to a remote
 * server (see {@link setApiServer}); same-origin callers can ignore it and keep
 * using the session cookie.
 */
export function setAuthToken(token: string | null, expiresAt?: string | null): void {
  const normalized = (token || '').trim();
  if (!normalized) {
    authTokenOverride = null;
    try {
      localStorage.removeItem('lwt.apiToken');
      localStorage.removeItem('lwt.apiTokenExpires');
    } catch {
      // localStorage unavailable: nothing persisted to clear.
    }
    return;
  }
  authTokenOverride = normalized;
  try {
    localStorage.setItem('lwt.apiToken', normalized);
    if (expiresAt) {
      localStorage.setItem('lwt.apiTokenExpires', expiresAt);
    } else {
      localStorage.removeItem('lwt.apiTokenExpires');
    }
  } catch {
    // localStorage unavailable: the in-memory token still applies this session.
  }
}

/**
 * The bearer token's expiry, or null when unknown. Used to decide whether to
 * refresh proactively.
 */
export function getAuthTokenExpiry(): Date | null {
  let raw: string;
  try {
    raw = localStorage.getItem('lwt.apiTokenExpires') || '';
  } catch {
    return null;
  }
  if (!raw) {
    return null;
  }
  const date = new Date(raw);
  return Number.isNaN(date.getTime()) ? null : date;
}

/**
 * Add the `Authorization: Bearer` header when a token is set. A no-op
 * otherwise, so same-origin cookie-authenticated requests are unchanged.
 */
function withAuth(headers: Record<string, string>): Record<string, string> {
  const token = getAuthToken();
  if (!token) return headers;
  return { ...headers, Authorization: `Bearer ${token}` };
}

/** How long before expiry a still-valid token gets rolled forward (7 days). */
const TOKEN_REFRESH_WINDOW_MS = 7 * 24 * 60 * 60 * 1000;

/** De-dupes concurrent refreshes so we never fire two `/auth/refresh` at once. */
let refreshInFlight: Promise<boolean> | null = null;

interface RefreshResponse {
  success?: boolean;
  token?: string;
  expires_at?: string | null;
}

/**
 * Exchange the current (still-valid) bearer token for a fresh one via
 * `POST /auth/refresh`, updating storage on success.
 *
 * NB: this backend can only refresh a token that is *still valid* — once a
 * token has expired (and started returning 401) it cannot be refreshed, so
 * this is a *proactive* mechanism, not a 401 recovery path. Uses raw `fetch`
 * (not the 401-aware wrapper) to avoid recursive teardown.
 *
 * @returns true if a new token was stored.
 */
export async function refreshAuthToken(): Promise<boolean> {
  if (!getAuthToken()) {
    return false;
  }
  if (refreshInFlight) {
    return refreshInFlight;
  }
  refreshInFlight = (async (): Promise<boolean> => {
    try {
      const response = await fetch(resolveApiRoot() + '/auth/refresh', {
        method: 'POST',
        headers: withAuth({
          'Content-Type': 'application/json',
          Accept: 'application/json'
        })
      });
      if (!response.ok) {
        return false;
      }
      const text = await response.text();
      const data = (text ? JSON.parse(text) : {}) as RefreshResponse;
      if (data.success === true && typeof data.token === 'string' && data.token) {
        setAuthToken(data.token, data.expires_at ?? null);
        return true;
      }
      return false;
    } catch {
      return false;
    } finally {
      refreshInFlight = null;
    }
  })();
  return refreshInFlight;
}

/**
 * Refresh the token only when it is still valid but within
 * {@link TOKEN_REFRESH_WINDOW_MS} of expiring. Safe to call on every app
 * launch: a no-op when there is no token, the expiry is unknown, it is not yet
 * due, or it has already lapsed (an expired token can't be refreshed).
 */
export async function maybeRefreshAuthToken(): Promise<boolean> {
  if (!getAuthToken()) {
    return false;
  }
  const expiry = getAuthTokenExpiry();
  if (!expiry) {
    return false;
  }
  const msLeft = expiry.getTime() - Date.now();
  if (msLeft <= 0 || msLeft > TOKEN_REFRESH_WINDOW_MS) {
    return false;
  }
  return refreshAuthToken();
}

/**
 * `fetch` wrapper that ends the session on a 401 while a bearer token is set:
 * the token has been rejected (expired or invalidated) and cannot be
 * refreshed, so it is cleared and a `lwt:auth-expired` event is dispatched for
 * the UI to route back to the login screen. A no-op for same-origin cookie
 * callers (no token), so their behavior is unchanged.
 */
async function apiFetch(input: string, init: RequestInit): Promise<Response> {
  const response = await fetch(input, init);
  if (response.status === 401 && getAuthToken()) {
    setAuthToken(null);
    try {
      document.dispatchEvent(new CustomEvent('lwt:auth-expired'));
    } catch {
      // Non-DOM environment: nothing to notify.
    }
  }
  return response;
}

/**
 * Get the default API configuration.
 * Lazily reads base path from meta tag.
 */
function getDefaultConfig(): ApiClientConfig {
  return {
    baseUrl: resolveApiRoot(),
    defaultHeaders: {
      'Content-Type': 'application/json',
      Accept: 'application/json'
    }
  };
}

// Use a getter to ensure base path is read after DOM is ready
const defaultConfig: ApiClientConfig = {
  get baseUrl() {
    return getDefaultConfig().baseUrl;
  },
  defaultHeaders: {
    'Content-Type': 'application/json',
    Accept: 'application/json'
  }
};

/**
 * Build URL with query parameters.
 *
 * @param endpoint API endpoint path
 * @param params   Optional query parameters
 * @returns Complete URL string
 */
function buildUrl(
  endpoint: string,
  params?: Record<string, string | number | boolean | undefined>
): string {
  const url = new URL(
    defaultConfig.baseUrl + endpoint,
    window.location.origin
  );

  if (params) {
    Object.entries(params).forEach(([key, value]) => {
      if (value !== undefined) {
        url.searchParams.append(key, String(value));
      }
    });
  }

  return url.toString();
}

/**
 * Parse response body as JSON or return empty object.
 *
 * @param response Fetch response object
 * @returns Parsed JSON data or empty object
 */
async function parseResponse<T>(response: Response): Promise<T> {
  const text = await response.text();
  if (!text) {
    return {} as T;
  }
  try {
    return JSON.parse(text) as T;
  } catch {
    // Return the raw text wrapped in an object if not JSON
    return { raw: text } as T;
  }
}

/**
 * Pull a human-readable message out of a failed response.
 *
 * The API is not consistent about where the text lives. `Response::error()`
 * emits `{error: "..."}`, some handlers return `{error: "...", success: false}`
 * of their own, and the exception handler emits `{error: true, message: "..."}`
 * — so `error` is only usable as the message when it is a string. Falling all
 * the way through to the status line keeps a message on screen either way.
 *
 * Reading `error` at all is new: every wrapper below used to look only at
 * `message`, which meant that even a correctly-formed error response arrived
 * in the UI as a bare "HTTP 400: Bad Request".
 */
async function errorMessageFrom(response: Response): Promise<string> {
  const body = await parseResponse<{ message?: unknown; error?: unknown }>(response);
  const fromError = typeof body.error === 'string' ? body.error : '';
  const fromMessage = typeof body.message === 'string' ? body.message : '';
  return (
    fromError || fromMessage || `HTTP ${response.status}: ${response.statusText}`
  );
}

/**
 * Make a GET request to the API.
 *
 * @param endpoint API endpoint (e.g., '/terms/123')
 * @param params   Optional query parameters
 * @returns Promise resolving to ApiResponse with data or error
 *
 * @example
 * const response = await apiGet<Term>('/terms/123');
 * if (response.data) {
 *   console.log(response.data.text);
 * }
 */
export async function apiGet<T>(
  endpoint: string,
  params?: Record<string, string | number | boolean | undefined>
): Promise<ApiResponse<T>> {
  try {
    const response = await apiFetch(buildUrl(endpoint, params), {
      method: 'GET',
      headers: withAuth(defaultConfig.defaultHeaders ?? {})
    });

    if (!response.ok) {
      return { error: await errorMessageFrom(response) };
    }

    const data = await parseResponse<T>(response);
    return { data };
  } catch (error) {
    return { error: String(error) };
  }
}

/**
 * Make a POST request to the API.
 *
 * @param endpoint API endpoint
 * @param body     Request body (will be JSON-stringified)
 * @returns Promise resolving to ApiResponse with data or error
 *
 * @example
 * const response = await apiPost<Term>('/terms', { text: 'hello', langId: 1 });
 */
export async function apiPost<T>(
  endpoint: string,
  body: Record<string, unknown>
): Promise<ApiResponse<T>> {
  try {
    const response = await apiFetch(defaultConfig.baseUrl + endpoint, {
      method: 'POST',
      headers: withAuth(withCsrf(defaultConfig.defaultHeaders ?? {})),
      body: JSON.stringify(body)
    });

    if (!response.ok) {
      return { error: await errorMessageFrom(response) };
    }

    const data = await parseResponse<T>(response);
    return { data };
  } catch (error) {
    return { error: String(error) };
  }
}

/**
 * Make a PUT request to the API.
 *
 * @param endpoint API endpoint
 * @param body     Request body (will be JSON-stringified)
 * @returns Promise resolving to ApiResponse with data or error
 *
 * @example
 * const response = await apiPut<Term>('/terms/123', { translation: 'bonjour' });
 */
export async function apiPut<T>(
  endpoint: string,
  body: Record<string, unknown>
): Promise<ApiResponse<T>> {
  try {
    const response = await apiFetch(defaultConfig.baseUrl + endpoint, {
      method: 'PUT',
      headers: withAuth(withCsrf(defaultConfig.defaultHeaders ?? {})),
      body: JSON.stringify(body)
    });

    if (!response.ok) {
      return { error: await errorMessageFrom(response) };
    }

    const data = await parseResponse<T>(response);
    return { data };
  } catch (error) {
    return { error: String(error) };
  }
}

/**
 * Make a DELETE request to the API.
 *
 * @param endpoint API endpoint
 * @returns Promise resolving to ApiResponse with data or error
 *
 * @example
 * const response = await apiDelete('/terms/123');
 */
export async function apiDelete<T>(
  endpoint: string,
  body?: Record<string, unknown>
): Promise<ApiResponse<T>> {
  try {
    const options: RequestInit = {
      method: 'DELETE',
      headers: withAuth(withCsrf(defaultConfig.defaultHeaders ?? {}))
    };
    if (body) {
      options.body = JSON.stringify(body);
    }
    const response = await apiFetch(defaultConfig.baseUrl + endpoint, options);

    if (!response.ok) {
      return { error: await errorMessageFrom(response) };
    }

    const data = await parseResponse<T>(response);
    return { data };
  } catch (error) {
    return { error: String(error) };
  }
}

/**
 * Make a multipart/form-data POST request, for endpoints taking a file upload.
 *
 * @param endpoint API endpoint
 * @param formData Form data, including any File entries
 * @returns Promise resolving to ApiResponse with data or error
 */
export async function apiPostMultipart<T>(
  endpoint: string,
  formData: FormData
): Promise<ApiResponse<T>> {
  try {
    // Deliberately no Content-Type: the browser has to set it so it can append
    // the multipart boundary. Supplying one here truncates the upload.
    const response = await apiFetch(defaultConfig.baseUrl + endpoint, {
      method: 'POST',
      headers: withAuth(withCsrf({ Accept: 'application/json' })),
      body: formData
    });

    if (!response.ok) {
      return { error: await errorMessageFrom(response) };
    }

    const data = await parseResponse<T>(response);
    return { data };
  } catch (error) {
    return { error: String(error) };
  }
}

/**
 * Make a form-urlencoded POST request (for legacy compatibility).
 *
 * Some existing endpoints expect form data rather than JSON.
 * Use this for backward compatibility during migration.
 *
 * @param endpoint API endpoint
 * @param data     Form data as key-value pairs
 * @returns Promise resolving to ApiResponse with data or error
 */
export async function apiPostForm<T>(
  endpoint: string,
  data: Record<string, string | number | boolean>
): Promise<ApiResponse<T>> {
  try {
    const formData = new URLSearchParams();
    Object.entries(data).forEach(([key, value]) => {
      formData.append(key, String(value));
    });

    const response = await apiFetch(defaultConfig.baseUrl + endpoint, {
      method: 'POST',
      headers: withAuth(withCsrf({
        'Content-Type': 'application/x-www-form-urlencoded',
        Accept: 'application/json'
      })),
      body: formData.toString()
    });

    if (!response.ok) {
      return { error: await errorMessageFrom(response) };
    }

    const respData = await parseResponse<T>(response);
    return { data: respData };
  } catch (error) {
    return { error: String(error) };
  }
}
