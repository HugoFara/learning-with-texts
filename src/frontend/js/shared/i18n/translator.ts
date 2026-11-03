/**
 * Frontend i18n translator.
 *
 * Two delivery paths, both producing the same flat "namespace.key" => string
 * map:
 *  1. Server-injected blob — a `<script id="lwt-i18n">` element on
 *     server-rendered pages (the default for the web app).
 *  2. API fetch — `GET /api/v1/i18n/{locale}`, cached in localStorage, for a
 *     configurable/offline client that has no server-rendered shell.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.0.0
 */

import { apiGet } from '@shared/api/client';
import { pluralCategory } from './plural_rules';

type TranslationMessages = Record<string, string>;

interface I18nBundle {
  locale: string;
  messages: TranslationMessages;
}

/** localStorage key prefix for cached per-locale API bundles. */
const CACHE_PREFIX = 'lwt.i18n.';

/**
 * localStorage key for the last resolved locale.
 *
 * A shell-free client has no server-rendered page to tell it which locale the
 * user picked, so we remember the locale the API resolved last time and use it
 * to hydrate synchronously on the next launch (offline-capable first paint).
 */
const LOCALE_KEY = 'lwt.locale';

let messages: TranslationMessages = {};
let initialized = false;

/**
 * Locale of the strings loaded from the API; null on server-rendered pages,
 * where `<html lang>` names it instead.
 */
let apiLocale: string | null = null;

/**
 * Merge additional strings into the active message map (later wins).
 *
 * Lets the blob and one or more API bundles coexist — e.g. a server-rendered
 * page that later pulls an extra namespace from the API.
 */
function mergeMessages(extra: TranslationMessages): void {
  messages = { ...messages, ...extra };
  initialized = true;
}

/**
 * Initialize translations from the server-injected JSON blob.
 *
 * Safe to call multiple times — only parses once.
 */
export function initI18n(): void {
  if (initialized) return;
  const el = document.getElementById('lwt-i18n');
  if (el?.textContent) {
    try {
      messages = JSON.parse(el.textContent) as TranslationMessages;
    } catch (e) {
      console.error('Failed to parse i18n data:', e);
    }
  }
  initialized = true;
}

/**
 * Synchronously hydrate strings from a cached API bundle for `locale`.
 *
 * Used at startup by a shell-free client so the first paint has strings
 * without waiting on the network; follow with {@link loadI18nFromApi} to
 * refresh in the background.
 *
 * @param locale - Locale code (e.g. "es")
 * @returns true if a cached bundle was found and applied
 */
export function hydrateI18nFromCache(locale: string): boolean {
  try {
    const raw = localStorage.getItem(CACHE_PREFIX + locale);
    if (!raw) return false;
    mergeMessages(JSON.parse(raw) as TranslationMessages);
    apiLocale = locale;
    return true;
  } catch {
    return false;
  }
}

/**
 * Fetch the translation bundle for `locale` from the API and cache it.
 *
 * Merges the result into the active strings and persists it in localStorage
 * so a later launch can hydrate synchronously. Never throws — returns false
 * on any transport/parse failure, leaving existing strings intact.
 *
 * @param locale     - Locale code (e.g. "es"); omit to use the server default
 * @param namespaces - Optional namespace allowlist (default: all)
 * @returns true if a bundle was fetched and applied
 */
export async function loadI18nFromApi(
  locale?: string,
  namespaces?: string[]
): Promise<boolean> {
  const path = locale ? `/i18n/${encodeURIComponent(locale)}` : '/i18n';
  const params = namespaces?.length ? { namespaces: namespaces.join(',') } : undefined;

  const res = await apiGet<I18nBundle>(path, params);
  const bundle = res.data;
  if (!bundle?.messages) return false;

  mergeMessages(bundle.messages);
  apiLocale = bundle.locale;

  // Cache under the resolved locale so hydration can find it next launch, and
  // remember which locale that was so the next boot knows what to hydrate.
  try {
    localStorage.setItem(
      CACHE_PREFIX + bundle.locale,
      JSON.stringify(bundle.messages)
    );
    localStorage.setItem(LOCALE_KEY, bundle.locale);
  } catch {
    // Storage full or unavailable — strings still work for this session.
  }

  return true;
}

/**
 * Read the last resolved locale persisted by {@link loadI18nFromApi}.
 *
 * @returns the stored locale code, or null if none has been resolved yet
 */
export function getStoredLocale(): string | null {
  try {
    return localStorage.getItem(LOCALE_KEY);
  } catch {
    return null;
  }
}

/**
 * Boot translations, transparently handling both delivery paths.
 *
 * On a server-rendered page the `<script id="lwt-i18n">` blob is present, so we
 * use it and return immediately — identical to the previous behavior. On a
 * shell-free / bundled client (no blob) we hydrate synchronously from the
 * cached bundle for an instant first paint, then refresh from the API in the
 * foreground so the rendered strings are current before the UI starts. When the
 * device is offline the API refresh fails silently and the cached strings
 * stand; a never-launched-online client simply falls back to raw keys until it
 * reaches the network once.
 *
 * @returns once strings are ready for first render
 */
export async function bootI18n(): Promise<void> {
  const el = document.getElementById('lwt-i18n');
  if (el?.textContent) {
    initI18n();
    return;
  }

  const cached = getStoredLocale();
  if (cached) {
    hydrateI18nFromCache(cached);
  }

  // Omitting the locale lets the server resolve its default on first run; the
  // resolved locale is then persisted for next launch's synchronous hydration.
  await loadI18nFromApi(cached ?? undefined);
}

/**
 * Translate a dot-notated key with optional parameter interpolation.
 *
 * @param key    - Translation key (e.g. "common.save")
 * @param params - Interpolation parameters (e.g. { count: 5 })
 * @returns Translated string, or the raw key if not found
 */
export function t(key: string, params?: Record<string, string | number>): string {
  if (!initialized) initI18n();
  return interpolate(messages[key] ?? key, params);
}

/**
 * Translate a count-dependent key in the plural form the locale needs.
 *
 * Looks up `{key}_{category}`, where the category is the CLDR plural
 * category of `count` in the active locale, then `{key}_other`. The
 * server merges English into the bundle, so a key the locale lacks still
 * resolves. `{count}` is filled in unless `params` sets it.
 *
 * @param key    - Dot-notated base key, without the category suffix
 * @param count  - The number being counted
 * @param params - Extra interpolation parameters
 * @returns Translated string, or the raw key if not found
 */
export function tn(
  key: string,
  count: number,
  params?: Record<string, string | number>
): string {
  if (!initialized) initI18n();
  const category = pluralCategory(activeLocale(), count);
  const text = messages[`${key}_${category}`] ?? messages[`${key}_other`] ?? key;
  return interpolate(text, { count, ...params });
}

/**
 * The interface locale: the one the loaded strings belong to.
 *
 * Use it for Intl formatting (dates, numbers) so they follow the interface
 * language rather than the browser's.
 */
export function activeLocale(): string {
  if (apiLocale) return apiLocale;
  if (typeof document !== 'undefined' && document.documentElement.lang) {
    return document.documentElement.lang;
  }
  return 'en';
}

function interpolate(text: string, params?: Record<string, string | number>): string {
  if (!params) return text;
  for (const [k, v] of Object.entries(params)) {
    text = text.split(`{${k}}`).join(String(v));
  }
  return text;
}
