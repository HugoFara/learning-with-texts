/**
 * Tests for core/language_settings.ts - Language settings utilities
 */
import { answerConfirms, settle } from '../helpers/confirm_answers';
import { describe, it, expect, beforeEach, afterEach, vi, type Mock } from 'vitest';

// Mock dependencies
vi.mock('../../../src/frontend/js/shared/api/client', () => ({
  apiPut: vi.fn().mockResolvedValue({ data: { count: 5 } }),
  getCsrfToken: vi.fn().mockReturnValue('test-csrf-token')
}));

import {
  setLang,
  setLangAsync,
  resetAll,
  resetAllAsync,
  iknowall,
  validateTablePrefix
} from '../../../src/frontend/js/modules/language/stores/language_settings';
import { apiPut } from '../../../src/frontend/js/shared/api/client';

describe('core/language_settings.ts', () => {
  const originalLocation = window.location;
  let locationHrefSpy: Mock;
  let fetchMock: Mock;

  beforeEach(() => {
    document.body.innerHTML = '';
    vi.clearAllMocks();

    // Mock fetch for API calls
    fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ message: 'ok' })
    });
    global.fetch = fetchMock;

    // Mock window.location with getter/setter for href
    locationHrefSpy = vi.fn();
    delete (window as unknown as { location: Location }).location;
    // Create a copy of originalLocation and exclude href (we'll define custom getter/setter)
    const locationCopy = { ...originalLocation };
    delete (locationCopy as { href?: string }).href;
    window.location = {
      ...locationCopy,
      get href() {
        return locationHrefSpy.mock.calls[locationHrefSpy.mock.calls.length - 1]?.[0] || '';
      },
      set href(value: string) {
        locationHrefSpy(value);
      },
      assign: vi.fn(),
      replace: vi.fn()
    } as unknown as Location;
  });

  afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
    window.location = originalLocation;
  });

  // ===========================================================================
  // setLang Tests
  // ===========================================================================

  describe('setLang', () => {
    it('calls API and redirects to specified URL', async () => {
      document.body.innerHTML = `
        <select id="lang-select">
          <option value="1">English</option>
          <option value="2" selected>Spanish</option>
          <option value="3">French</option>
        </select>
      `;
      const select = document.getElementById('lang-select') as HTMLSelectElement;

      await setLang(select, '/texts');

      expect(fetchMock).toHaveBeenCalledWith('/api/v1/settings', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': 'test-csrf-token' },
        body: JSON.stringify({ key: 'currentlanguage', value: '2' })
      });
      expect(locationHrefSpy).toHaveBeenCalledWith('/texts');
    });

    it('uses first option when selected', async () => {
      document.body.innerHTML = `
        <select id="lang-select">
          <option value="1" selected>English</option>
          <option value="2">Spanish</option>
        </select>
      `;
      const select = document.getElementById('lang-select') as HTMLSelectElement;

      await setLang(select, '/home');

      expect(fetchMock).toHaveBeenCalledWith('/api/v1/settings', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': 'test-csrf-token' },
        body: JSON.stringify({ key: 'currentlanguage', value: '1' })
      });
      expect(locationHrefSpy).toHaveBeenCalledWith('/home');
    });

    it('handles empty value option', async () => {
      document.body.innerHTML = `
        <select id="lang-select">
          <option value="" selected>All Languages</option>
          <option value="1">English</option>
        </select>
      `;
      const select = document.getElementById('lang-select') as HTMLSelectElement;

      await setLang(select, '/texts');

      expect(fetchMock).toHaveBeenCalledWith('/api/v1/settings', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': 'test-csrf-token' },
        body: JSON.stringify({ key: 'currentlanguage', value: '' })
      });
      expect(locationHrefSpy).toHaveBeenCalledWith('/texts');
    });

    it('handles different redirect URLs', async () => {
      document.body.innerHTML = `
        <select id="lang-select">
          <option value="5" selected>Japanese</option>
        </select>
      `;
      const select = document.getElementById('lang-select') as HTMLSelectElement;

      await setLang(select, '/words/list');

      expect(locationHrefSpy).toHaveBeenCalledWith('/words/list');
    });

    it('handles URL with special characters', async () => {
      document.body.innerHTML = `
        <select id="lang-select">
          <option value="1" selected>English</option>
        </select>
      `;
      const select = document.getElementById('lang-select') as HTMLSelectElement;

      await setLang(select, '/texts?filter=new');

      expect(locationHrefSpy).toHaveBeenCalledWith('/texts?filter=new');
    });
  });

  // ===========================================================================
  // setLangAsync Tests
  // ===========================================================================

  describe('setLangAsync', () => {
    it('calls API with correct parameters', async () => {
      await setLangAsync('5');

      expect(fetchMock).toHaveBeenCalledWith('/api/v1/settings', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': 'test-csrf-token' },
        body: JSON.stringify({ key: 'currentlanguage', value: '5' })
      });
    });

    it('throws error on non-ok response', async () => {
      fetchMock.mockResolvedValueOnce({ ok: false, status: 500 });

      await expect(setLangAsync('1')).rejects.toThrow('HTTP error! status: 500');
    });
  });

  // ===========================================================================
  // resetAll Tests
  // ===========================================================================

  describe('resetAll', () => {
    it('calls API and redirects to specified URL', async () => {
      await resetAll('/texts');

      expect(fetchMock).toHaveBeenCalledWith('/api/v1/settings', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': 'test-csrf-token' },
        body: JSON.stringify({ key: 'currentlanguage', value: '' })
      });
      expect(locationHrefSpy).toHaveBeenCalledWith('/texts');
    });

    it('uses provided redirect URL', async () => {
      await resetAll('/home');

      expect(locationHrefSpy).toHaveBeenCalledWith('/home');
    });

    it('handles root URL', async () => {
      await resetAll('/');

      expect(locationHrefSpy).toHaveBeenCalledWith('/');
    });

    it('handles complex URL', async () => {
      await resetAll('/admin/settings?tab=languages');

      expect(locationHrefSpy).toHaveBeenCalledWith('/admin/settings?tab=languages');
    });
  });

  // ===========================================================================
  // resetAllAsync Tests
  // ===========================================================================

  describe('resetAllAsync', () => {
    it('calls API with empty language value', async () => {
      await resetAllAsync();

      expect(fetchMock).toHaveBeenCalledWith('/api/v1/settings', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': 'test-csrf-token' },
        body: JSON.stringify({ key: 'currentlanguage', value: '' })
      });
    });

    it('throws error on non-ok response', async () => {
      fetchMock.mockResolvedValueOnce({ ok: false, status: 500 });

      await expect(resetAllAsync()).rejects.toThrow('HTTP error! status: 500');
    });
  });

  // ===========================================================================
  // iknowall Tests
  // ===========================================================================

  describe('iknowall', () => {
    let reloadSpy: Mock;

    beforeEach(() => {
      reloadSpy = vi.fn();
      Object.defineProperty(window, 'location', {
        value: {
          ...window.location,
          reload: reloadSpy
        },
        writable: true
      });
    });

    it('shows confirmation dialog', async () => {
      const confirmSpy = answerConfirms(true);

      await iknowall(1);

      await settle();
      expect(confirmSpy).toHaveBeenCalledWith('Mark all unknown words as Well Known?');
    });

    it('calls API when confirmed', async () => {
      answerConfirms(true);

      await iknowall(1);

      await settle();
      expect(apiPut).toHaveBeenCalledWith('/texts/1/mark-all-wellknown', {});
    });

    it('does not call API when cancelled', async () => {
      answerConfirms(false);
      vi.mocked(apiPut).mockClear();

      await iknowall(1);

      await settle();
      expect(apiPut).not.toHaveBeenCalled();
    });

    it('handles string text ID', async () => {
      answerConfirms(true);

      await iknowall('42');

      await settle();
      expect(apiPut).toHaveBeenCalledWith('/texts/42/mark-all-wellknown', {});
    });

    it('handles numeric text ID', async () => {
      answerConfirms(true);

      await iknowall(123);

      await settle();
      expect(apiPut).toHaveBeenCalledWith('/texts/123/mark-all-wellknown', {});
    });

    it('reloads page after successful API call', async () => {
      answerConfirms(true);

      await iknowall(1);

      await settle();
      expect(reloadSpy).toHaveBeenCalled();
    });
  });

  // ===========================================================================
  // validateTablePrefix Tests
  // ===========================================================================

  describe('validateTablePrefix', () => {
    it('returns true for valid alphanumeric prefix', () => {
      const result = validateTablePrefix('myprefix');

      expect(result).toBe(true);
    });

    it('returns true for prefix with underscore', () => {
      const result = validateTablePrefix('my_prefix');

      expect(result).toBe(true);
    });

    it('returns true for prefix with numbers', () => {
      const result = validateTablePrefix('prefix123');

      expect(result).toBe(true);
    });

    it('returns true for single character prefix', () => {
      const result = validateTablePrefix('a');

      expect(result).toBe(true);
    });

    it('returns true for 20 character prefix', () => {
      const result = validateTablePrefix('a'.repeat(20));

      expect(result).toBe(true);
    });

    it('returns false for empty prefix', () => {
      const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});

      const result = validateTablePrefix('');

      expect(result).toBe(false);
      expect(alertSpy).toHaveBeenCalled();
    });

    it('returns false for prefix longer than 20 characters', () => {
      const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});

      const result = validateTablePrefix('a'.repeat(21));

      expect(result).toBe(false);
      expect(alertSpy).toHaveBeenCalled();
    });

    it('returns false for prefix with special characters', () => {
      const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});

      const result = validateTablePrefix('my-prefix');

      expect(result).toBe(false);
      expect(alertSpy).toHaveBeenCalled();
    });

    it('returns false for prefix with spaces', () => {
      const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});

      const result = validateTablePrefix('my prefix');

      expect(result).toBe(false);
      expect(alertSpy).toHaveBeenCalled();
    });

    it('returns false for prefix with dots', () => {
      const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});

      const result = validateTablePrefix('my.prefix');

      expect(result).toBe(false);
      expect(alertSpy).toHaveBeenCalled();
    });

    it('shows error message for invalid prefix', () => {
      const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});

      validateTablePrefix('invalid!');

      expect(alertSpy).toHaveBeenCalledWith(
        expect.stringContaining('Table Set Name')
      );
    });

    it('allows uppercase letters', () => {
      const result = validateTablePrefix('MyPrefix');

      expect(result).toBe(true);
    });

    it('allows mixed case with numbers and underscore', () => {
      const result = validateTablePrefix('My_Prefix_123');

      expect(result).toBe(true);
    });

    it('returns false for Unicode characters', () => {
      const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});

      const result = validateTablePrefix('日本語');

      expect(result).toBe(false);
      expect(alertSpy).toHaveBeenCalled();
    });

    it('returns true for underscore only prefix', () => {
      const result = validateTablePrefix('_');

      expect(result).toBe(true);
    });

    it('returns true for prefix starting with underscore', () => {
      const result = validateTablePrefix('_myprefix');

      expect(result).toBe(true);
    });

    it('returns true for prefix starting with number', () => {
      const result = validateTablePrefix('123prefix');

      expect(result).toBe(true);
    });

    it('does not show alert for valid prefix', () => {
      const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});

      validateTablePrefix('validprefix');

      expect(alertSpy).not.toHaveBeenCalled();
    });
  });

  // ===========================================================================
  // Event Delegation Tests (if applicable)
  // ===========================================================================

  describe('Event Delegation', () => {
    it('handles data-action="set-lang" on change', async () => {
      document.body.innerHTML = `
        <select data-action="set-lang" data-redirect="/home">
          <option value="1">English</option>
          <option value="2">Spanish</option>
        </select>
      `;

      // Trigger DOMContentLoaded to initialize event delegation
      document.dispatchEvent(new Event('DOMContentLoaded'));

      const select = document.querySelector('select') as HTMLSelectElement;
      select.value = '2';

      // Dispatch change event
      select.dispatchEvent(new Event('change', { bubbles: true }));

      // Wait for async operation including redirect to complete
      await vi.waitFor(() => {
        expect(locationHrefSpy).toHaveBeenCalledWith('/home');
      });
      expect(fetchMock).toHaveBeenCalledWith('/api/v1/settings', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': 'test-csrf-token' },
        body: JSON.stringify({ key: 'currentlanguage', value: '2' })
      });
    });

    it('uses default redirect when data-redirect is missing', async () => {
      document.body.innerHTML = `
        <select data-action="set-lang">
          <option value="1" selected>English</option>
        </select>
      `;

      document.dispatchEvent(new Event('DOMContentLoaded'));

      const select = document.querySelector('select') as HTMLSelectElement;
      select.dispatchEvent(new Event('change', { bubbles: true }));

      // Wait for async operation including redirect to complete
      await vi.waitFor(() => {
        expect(locationHrefSpy).toHaveBeenCalledWith('/');
      });
      expect(fetchMock).toHaveBeenCalled();
    });
  });

  // ===========================================================================
  // Edge Cases
  // ===========================================================================

  describe('Edge Cases', () => {
    it('setLang handles select with no options', async () => {
      document.body.innerHTML = '<select id="empty-select"></select>';
      const select = document.getElementById('empty-select') as HTMLSelectElement;

      // Will throw because selectedIndex returns -1 and options[-1] is undefined
      // This is expected behavior - test documents the behavior
      await expect(setLang(select, '/test')).rejects.toThrow();
    });

    it('validateTablePrefix handles boundary length 20', () => {
      const exactLength = 'a'.repeat(20);
      expect(validateTablePrefix(exactLength)).toBe(true);

      const tooLong = 'a'.repeat(21);
      vi.spyOn(window, 'alert').mockImplementation(() => {});
      expect(validateTablePrefix(tooLong)).toBe(false);
    });

    it('iknowall handles zero text ID', async () => {
      answerConfirms(true);
      vi.spyOn(console, 'error').mockImplementation(() => {}); // Suppress expected error

      await iknowall(0);

      await settle();
      expect(apiPut).toHaveBeenCalledWith('/texts/0/mark-all-wellknown', {});
    });

    it('validateTablePrefix handles consecutive underscores', () => {
      const result = validateTablePrefix('my__prefix');

      expect(result).toBe(true);
    });

    it('resetAll handles empty string URL', async () => {
      await resetAll('');

      expect(locationHrefSpy).toHaveBeenCalledWith('');
    });
  });
});
