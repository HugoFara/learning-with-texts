/**
 * Tests for the frontend i18n translator's API + cache delivery path
 * (shared/i18n/translator.ts), the seam a shell-free client uses to fetch
 * strings instead of reading the server-injected page blob.
 *
 * Each test re-imports the module so the singleton message map starts empty.
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

const mockFetch = vi.fn();
const originalFetch = global.fetch;

function okJson(body: unknown) {
  return { ok: true, text: () => Promise.resolve(JSON.stringify(body)) };
}

async function freshTranslator() {
  vi.resetModules();
  return import('../../../src/frontend/js/shared/i18n/translator');
}

describe('shared/i18n/translator.ts — API + cache delivery', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    global.fetch = mockFetch;
    mockFetch.mockResolvedValue(okJson({ locale: 'en', messages: {} }));
    localStorage.clear();
  });

  afterEach(() => {
    localStorage.clear();
    vi.restoreAllMocks();
    global.fetch = originalFetch;
  });

  describe('loadI18nFromApi', () => {
    it('fetches a locale bundle and makes its strings resolvable via t()', async () => {
      const { loadI18nFromApi, t } = await freshTranslator();
      mockFetch.mockResolvedValue(
        okJson({ locale: 'es', messages: { 'common.save': 'Guardar' } })
      );

      const ok = await loadI18nFromApi('es');

      expect(ok).toBe(true);
      expect(String(mockFetch.mock.calls[0][0])).toContain('/api/v1/i18n/es');
      expect(t('common.save')).toBe('Guardar');
    });

    it('caches the fetched bundle in localStorage under its locale', async () => {
      const { loadI18nFromApi } = await freshTranslator();
      mockFetch.mockResolvedValue(
        okJson({ locale: 'es', messages: { 'common.save': 'Guardar' } })
      );

      await loadI18nFromApi('es');

      const cached = localStorage.getItem('lwt.i18n.es');
      expect(cached).not.toBeNull();
      expect(JSON.parse(cached as string)).toEqual({ 'common.save': 'Guardar' });
    });

    it('passes a namespace filter through to the request', async () => {
      const { loadI18nFromApi } = await freshTranslator();
      await loadI18nFromApi('en', ['navbar', 'common']);
      expect(String(mockFetch.mock.calls[0][0])).toContain('namespaces=navbar%2Ccommon');
    });

    it('requests the server-default locale when none is given', async () => {
      const { loadI18nFromApi } = await freshTranslator();
      await loadI18nFromApi();
      const url = String(mockFetch.mock.calls[0][0]);
      expect(url).toContain('/api/v1/i18n');
      expect(url).not.toContain('/i18n/');
    });

    it('returns false and caches nothing when the request fails', async () => {
      const { loadI18nFromApi } = await freshTranslator();
      mockFetch.mockRejectedValue(new Error('offline'));

      const ok = await loadI18nFromApi('es');

      expect(ok).toBe(false);
      // A failed fetch must not persist a bogus/empty bundle.
      expect(localStorage.getItem('lwt.i18n.es')).toBeNull();
    });

    it('interpolates parameters into a fetched string', async () => {
      const { loadI18nFromApi, t } = await freshTranslator();
      mockFetch.mockResolvedValue(
        okJson({ locale: 'en', messages: { 'review.due': '{count} due' } })
      );
      await loadI18nFromApi('en');
      expect(t('review.due', { count: 5 })).toBe('5 due');
    });
  });

  describe('hydrateI18nFromCache', () => {
    it('synchronously applies a previously cached bundle', async () => {
      const { hydrateI18nFromCache, t } = await freshTranslator();
      localStorage.setItem('lwt.i18n.es', JSON.stringify({ 'common.save': 'Guardar' }));

      const applied = hydrateI18nFromCache('es');

      expect(applied).toBe(true);
      expect(t('common.save')).toBe('Guardar');
      // No network needed.
      expect(mockFetch).not.toHaveBeenCalled();
    });

    it('returns false when nothing is cached for the locale', async () => {
      const { hydrateI18nFromCache } = await freshTranslator();
      expect(hydrateI18nFromCache('de')).toBe(false);
    });

    it('lets a later API load override cached strings', async () => {
      const { hydrateI18nFromCache, loadI18nFromApi, t } = await freshTranslator();
      localStorage.setItem('lwt.i18n.es', JSON.stringify({ 'common.save': 'old' }));
      hydrateI18nFromCache('es');
      expect(t('common.save')).toBe('old');

      mockFetch.mockResolvedValue(
        okJson({ locale: 'es', messages: { 'common.save': 'Guardar' } })
      );
      await loadI18nFromApi('es');

      expect(t('common.save')).toBe('Guardar');
    });
  });

  describe('initI18n (server-injected blob, unchanged)', () => {
    it('still reads strings from the lwt-i18n script element', async () => {
      const { initI18n, t } = await freshTranslator();
      document.body.innerHTML =
        '<script type="application/json" id="lwt-i18n">'
        + '{"common.save":"Save"}</script>';

      initI18n();

      expect(t('common.save')).toBe('Save');
      document.body.innerHTML = '';
    });
  });

  describe('loadI18nFromApi — locale persistence', () => {
    it('remembers the resolved locale for next-launch hydration', async () => {
      const { loadI18nFromApi, getStoredLocale } = await freshTranslator();
      mockFetch.mockResolvedValue(
        okJson({ locale: 'es', messages: { 'common.save': 'Guardar' } })
      );

      await loadI18nFromApi('es');

      expect(getStoredLocale()).toBe('es');
    });

    it('does not persist a locale when the request fails', async () => {
      const { loadI18nFromApi, getStoredLocale } = await freshTranslator();
      mockFetch.mockRejectedValue(new Error('offline'));

      await loadI18nFromApi('es');

      expect(getStoredLocale()).toBeNull();
    });
  });

  describe('bootI18n (dual delivery path)', () => {
    /**
     * The shared test setup injects a global `lwt-i18n` blob in <head>; a
     * shell-free client has none, so these tests strip every blob node
     * (head and any left over from another case) to exercise the API path.
     */
    function removeAllBlobs() {
      document.querySelectorAll('#lwt-i18n').forEach((e) => e.remove());
    }

    afterEach(removeAllBlobs);

    it('uses the server blob and skips the network when one is present', async () => {
      const { bootI18n, t } = await freshTranslator();
      removeAllBlobs();
      const el = document.createElement('script');
      el.type = 'application/json';
      el.id = 'lwt-i18n';
      el.textContent = '{"common.save":"FromBlob"}';
      document.body.appendChild(el);

      await bootI18n();

      expect(t('common.save')).toBe('FromBlob');
      expect(mockFetch).not.toHaveBeenCalled();
    });

    it('fetches from the API when no blob is present', async () => {
      const { bootI18n, t } = await freshTranslator();
      removeAllBlobs();
      mockFetch.mockResolvedValue(
        okJson({ locale: 'es', messages: { 'common.save': 'Guardar' } })
      );

      await bootI18n();

      expect(mockFetch).toHaveBeenCalledTimes(1);
      expect(t('common.save')).toBe('Guardar');
    });

    it('requests the stored locale and hydrates its cache first when blob is absent', async () => {
      const { bootI18n, t } = await freshTranslator();
      removeAllBlobs();
      localStorage.setItem('lwt.locale', 'es');
      localStorage.setItem('lwt.i18n.es', JSON.stringify({ 'common.save': 'Cached' }));
      // API is slow/unreachable: the cached value must still be available.
      mockFetch.mockRejectedValue(new Error('offline'));

      await bootI18n();

      expect(String(mockFetch.mock.calls[0][0])).toContain('/api/v1/i18n/es');
      expect(t('common.save')).toBe('Cached');
    });
  });

  describe('tn', () => {
    const arabic = {
      'common.terms_zero': 'لا مصطلحات',
      'common.terms_one': 'مصطلح واحد',
      'common.terms_two': 'مصطلحان',
      'common.terms_few': '{count} مصطلحات',
      'common.terms_many': '{count} مصطلحًا',
      'common.terms_other': '{count} مصطلح',
    };

    it('picks the plural form of the API bundle locale', async () => {
      const { loadI18nFromApi, tn } = await freshTranslator();
      mockFetch.mockResolvedValue(okJson({ locale: 'ar', messages: arabic }));
      await loadI18nFromApi('ar');

      expect(tn('common.terms', 0)).toBe('لا مصطلحات');
      expect(tn('common.terms', 2)).toBe('مصطلحان');
      expect(tn('common.terms', 7)).toBe('7 مصطلحات');
      expect(tn('common.terms', 11)).toBe('11 مصطلحًا');
      expect(tn('common.terms', 100)).toBe('100 مصطلح');
    });

    it('falls back to the other form, and lets the caller format the count', async () => {
      const { loadI18nFromApi, tn } = await freshTranslator();
      mockFetch.mockResolvedValue(
        okJson({ locale: 'ru', messages: { 'common.terms_one': '{count} термин', 'common.terms_other': '{count} терминов' } })
      );
      await loadI18nFromApi('ru');

      expect(tn('common.terms', 21)).toBe('21 термин');
      expect(tn('common.terms', 3)).toBe('3 терминов');
      expect(tn('common.terms', 1500, { count: '1 500' })).toBe('1 500 терминов');
      expect(tn('common.missing', 1)).toBe('common.missing');
    });

    it('reads the locale from <html lang> on server-rendered pages', async () => {
      const { tn } = await freshTranslator();
      document.documentElement.lang = 'fr';
      const blob = document.createElement('script');
      blob.id = 'lwt-i18n';
      blob.type = 'application/json';
      blob.textContent = JSON.stringify({ 'common.terms_one': '{count} terme', 'common.terms_other': '{count} termes' });
      document.body.appendChild(blob);

      try {
        expect(tn('common.terms', 0)).toBe('0 terme');
        expect(tn('common.terms', 2)).toBe('2 termes');
      } finally {
        blob.remove();
        document.documentElement.lang = '';
      }
    });
  });
});
