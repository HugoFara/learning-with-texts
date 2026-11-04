/**
 * Tests for core utility functions
 */
import { confirmPrompts, dismissAlerts, settle } from '../helpers/confirm_answers';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import {
  getStatusName,
  getStatusAbbr,
  createWordTooltip,
} from '../../../src/frontend/js/modules/vocabulary/services/word_status';
import {
  getLangFromDict,
  createTheDictUrl,
  createTheDictLink,
  createSentLookupLink,
  openDictionaryPopup,
} from '../../../src/frontend/js/modules/vocabulary/services/dictionary';
import {
  escapeHtml,
  escapeHtmlWithAnnotation,
  escapeApostrophes,
} from '../../../src/frontend/js/shared/utils/html_utils';
import {
  getCookie,
  setCookie,
  deleteCookie,
  areCookiesEnabled,
} from '../../../src/frontend/js/shared/utils/cookies';
import {
  validateTablePrefix,
} from '../../../src/frontend/js/modules/language/stores/language_settings';

// Note: STATUSES is now hardcoded in app_data.ts, no need to mock
describe('pgm.ts', () => {
  beforeEach(() => {
    // No mocking needed - STATUSES is now directly imported from app_data.ts
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  // ===========================================================================
  // Status Functions Tests
  // ===========================================================================

  describe('getStatusName', () => {
    it('returns correct status name for valid status', () => {
      // Statuses 1-4 are "Learning", 5 is "Learned"
      expect(getStatusName(1)).toBe('Learning');
      expect(getStatusName('1')).toBe('Learning');
      expect(getStatusName(5)).toBe('Learned');
      expect(getStatusName(98)).toBe('Ignored');
      expect(getStatusName(99)).toBe('Well Known');
    });

    it('returns Unknown for invalid status', () => {
      expect(getStatusName(100)).toBe('Unknown');
      expect(getStatusName('invalid')).toBe('Unknown');
    });

    it('handles string status numbers', () => {
      expect(getStatusName('2')).toBe('Learning');
      expect(getStatusName('98')).toBe('Ignored');
    });
  });

  describe('getStatusAbbr', () => {
    it('returns correct abbreviation for valid status', () => {
      expect(getStatusAbbr(1)).toBe('1');
      expect(getStatusAbbr(98)).toBe('Ignored');
      expect(getStatusAbbr(99)).toBe('Well Known');
    });

    it('returns ? for invalid status', () => {
      expect(getStatusAbbr(100)).toBe('?');
      expect(getStatusAbbr('invalid')).toBe('?');
    });
  });

  // ===========================================================================
  // URL and Dictionary Functions Tests
  // ===========================================================================

  describe('getLangFromDict', () => {
    it('returns empty string for empty URL', () => {
      expect(getLangFromDict('')).toBe('');
      expect(getLangFromDict('   ')).toBe('');
    });

    it('handles Google Translate URL', () => {
      // Asterisk prefix is no longer used - popup is stored in database
      const url = 'http://translate.google.com?sl=en&tl=fr';
      expect(getLangFromDict(url)).toBe('en');
    });

    it('extracts source language from Google Translate URL', () => {
      const url = 'http://translate.google.com?sl=de&tl=en';
      expect(getLangFromDict(url)).toBe('de');
    });

    it('extracts source language from LibreTranslate URL', () => {
      const url = 'http://localhost:5000?lwt_translator=libretranslate&source=es&target=en';
      expect(getLangFromDict(url)).toBe('es');
    });

    it('returns empty for invalid relative URLs', () => {
      // Old trans.php and ggl.php gateway URLs are no longer supported
      // The function now requires valid absolute URLs
      const url1 = 'trans.php?sl=ja&tl=en';
      expect(getLangFromDict(url1)).toBe('');

      const url2 = 'ggl.php?sl=ko&tl=en';
      expect(getLangFromDict(url2)).toBe('');
    });

    it('returns empty string when no language param found', () => {
      const url = 'http://example.com?foo=bar';
      expect(getLangFromDict(url)).toBe('');
    });
  });

  describe('createTheDictUrl', () => {
    it('appends term when no placeholder in URL', () => {
      const result = createTheDictUrl('http://dict.com/search?q=', 'hello');
      expect(result).toBe('http://dict.com/search?q=hello');
    });

    it('replaces lwt_term placeholder', () => {
      const result = createTheDictUrl('http://dict.com/search?q=lwt_term', 'world');
      expect(result).toBe('http://dict.com/search?q=world');
    });

    it('replaces lwt_term placeholder', () => {
      const result = createTheDictUrl('http://dict.com/search?q=lwt_term', 'test');
      expect(result).toBe('http://dict.com/search?q=test');
    });

    it('encodes special characters', () => {
      const result = createTheDictUrl('http://dict.com/search?q=', 'hello world');
      expect(result).toBe('http://dict.com/search?q=hello%20world');
    });

    it('handles empty term', () => {
      const result = createTheDictUrl('http://dict.com/search?q=lwt_term', '');
      expect(result).toBe('http://dict.com/search?q=+');
    });

    it('trims URL and term', () => {
      const result = createTheDictUrl('  http://dict.com/  ', '  test  ');
      expect(result).toBe('http://dict.com/test');
    });

    it('handles URL with lwt_term in path', () => {
      // Simple replacement of lwt_term placeholder
      const result = createTheDictUrl('http://dict.com/search/lwt_term/details', 'test');
      expect(result).toBe('http://dict.com/search/test/details');
    });
  });

  describe('createTheDictLink', () => {
    it('returns empty string for empty URL', () => {
      expect(createTheDictLink('', 'word', 'Link', '')).toBe('');
    });

    it('returns empty string for empty text', () => {
      expect(createTheDictLink('http://dict.com', 'word', '', '')).toBe('');
    });

    it('creates regular link for normal URL', () => {
      const result = createTheDictLink('http://dict.com?q=lwt_term', 'hello', 'Translate', '');
      expect(result).toContain('<a href=');
      expect(result).toContain('Translate');
      expect(result).toContain('target="ru"');
    });

    it('creates popup span when popup=true', () => {
      // Popup is now determined by boolean parameter, not URL prefix
      const result = createTheDictLink('http://dict.com?q=lwt_term', 'hello', 'Translate', '', true);
      expect(result).toContain('<span class="click"');
      expect(result).toContain('data-action="dict-popup"');
      expect(result).toContain('data-url=');
    });

    it('creates regular link when popup=false', () => {
      const result = createTheDictLink('http://dict.com?q=lwt_term', 'hello', 'Translate', '', false);
      expect(result).toContain('<a href=');
      expect(result).toContain('target="ru"');
    });

    it('includes txtbefore content', () => {
      const result = createTheDictLink('http://dict.com', 'word', 'Link', 'Before:');
      expect(result).toContain('Before:');
    });
  });

  describe('createSentLookupLink', () => {
    it('returns empty string for empty URL', () => {
      expect(createSentLookupLink('', 'Translate')).toBe('');
    });

    it('returns empty string for empty text', () => {
      expect(createSentLookupLink('http://trans.com', '')).toBe('');
    });

    it('creates popup span when popup=true', () => {
      // Popup is now determined by boolean parameter, not URL prefix
      const result = createSentLookupLink('http://trans.com', 'Translate', true);
      expect(result).toContain('<span class="click"');
      expect(result).toContain('http://trans.com');
    });

    it('creates regular link for external URL', () => {
      const result = createSentLookupLink('http://trans.com', 'Translate');
      // Now uses the translator URL directly instead of trans.php
      expect(result).toContain('<a href="http://trans.com"');
      expect(result).toContain('target="ru"');
    });
  });

  // ===========================================================================
  // HTML Escape Functions Tests
  // ===========================================================================

  describe('escapeHtml', () => {
    it('escapes ampersand', () => {
      expect(escapeHtml('a & b')).toBe('a &amp; b');
    });

    it('escapes less than', () => {
      expect(escapeHtml('a < b')).toBe('a &lt; b');
    });

    it('escapes greater than', () => {
      expect(escapeHtml('a > b')).toBe('a &gt; b');
    });

    it('escapes double quotes', () => {
      expect(escapeHtml('say "hello"')).toBe('say &quot;hello&quot;');
    });

    it('escapes single quotes', () => {
      expect(escapeHtml("it's")).toBe('it&#039;s');
    });

    it('converts carriage return to br', () => {
      expect(escapeHtml('line1\x0dline2')).toBe('line1<br />line2');
    });

    it('handles multiple special characters', () => {
      expect(escapeHtml('<a & "b">')).toBe('&lt;a &amp; &quot;b&quot;&gt;');
    });

    it('handles empty string', () => {
      expect(escapeHtml('')).toBe('');
    });
  });

  describe('escapeHtmlWithAnnotation', () => {
    it('escapes HTML and highlights annotation', () => {
      const result = escapeHtmlWithAnnotation('hello world', 'world');
      expect(result).toContain('<span style="color:red">world</span>');
      expect(result).toContain('hello');
    });

    it('handles empty annotation', () => {
      const result = escapeHtmlWithAnnotation('hello world', '');
      expect(result).toBe('hello world');
    });

    it('escapes both title and annotation', () => {
      const result = escapeHtmlWithAnnotation('a & b', '&');
      expect(result).toContain('<span style="color:red">&amp;</span>');
    });
  });

  describe('escapeApostrophes', () => {
    it('escapes single apostrophes', () => {
      expect(escapeApostrophes("it's")).toBe("it\\'s");
    });

    it('escapes multiple apostrophes', () => {
      expect(escapeApostrophes("don't won't")).toBe("don\\'t won\\'t");
    });

    it('handles string without apostrophes', () => {
      expect(escapeApostrophes('hello world')).toBe('hello world');
    });
  });

  // ===========================================================================
  // Tooltip Function Tests
  // ===========================================================================

  describe('createWordTooltip', () => {
    it('creates tooltip with word only', () => {
      const result = createWordTooltip('hello', '', '', 1);
      expect(result).toContain('hello');
      expect(result).toContain('Learning');
    });

    it('includes romanization when provided', () => {
      const result = createWordTooltip('日本語', '', 'nihongo', 1);
      expect(result).toContain('▶ nihongo');
    });

    it('includes translation when provided', () => {
      const result = createWordTooltip('bonjour', 'hello', '', 1);
      expect(result).toContain('▶ hello');
    });

    it('excludes translation when it is *', () => {
      const result = createWordTooltip('word', '*', '', 1);
      expect(result).not.toContain('▶ *');
    });

    it('includes status name and abbreviation', () => {
      const result = createWordTooltip('word', 'trans', '', 98);
      expect(result).toContain('Ignored');
    });

    it('handles all fields populated', () => {
      const result = createWordTooltip('日本', 'Japan', 'nihon', 5);
      expect(result).toContain('日本');
      expect(result).toContain('▶ nihon');
      expect(result).toContain('▶ Japan');
      expect(result).toContain('Learned');
    });
  });

  // ===========================================================================
  // Window Functions Tests
  // ===========================================================================

  describe('openDictionaryPopup', () => {
    it('opens a window with correct parameters', () => {
      const mockOpen = vi.spyOn(window, 'open').mockReturnValue(null);

      openDictionaryPopup('http://example.com');

      expect(mockOpen).toHaveBeenCalledWith(
        'http://example.com',
        'dictwin',
        expect.stringContaining('width=800')
      );
    });
  });

  // ===========================================================================
  // Cookie Functions Tests
  // ===========================================================================

  describe('Cookie functions', () => {
    beforeEach(() => {
      // Clear all cookies before each test
      document.cookie.split(';').forEach((c) => {
        document.cookie = c
          .replace(/^ +/, '')
          .replace(/=.*/, '=;expires=Thu, 01 Jan 1970 00:00:00 GMT');
      });
    });

    describe('setCookie', () => {
      it('sets a cookie with basic parameters', () => {
        setCookie('testCookie', 'testValue', 1, '/', '', false);
        expect(document.cookie).toContain('testCookie=testValue');
      });

      it('encodes special characters', () => {
        setCookie('test', 'hello world', 1, '/', '', false);
        expect(document.cookie).toContain('test=hello%20world');
      });
    });

    describe('getCookie', () => {
      it('retrieves existing cookie', () => {
        document.cookie = 'myCookie=myValue';
        expect(getCookie('myCookie')).toBe('myValue');
      });

      it('returns null for non-existent cookie', () => {
        expect(getCookie('nonExistent')).toBeNull();
      });

      it('handles URL-encoded values', () => {
        document.cookie = 'encoded=hello%20world';
        expect(getCookie('encoded')).toBe('hello world');
      });

      it('handles cookies with empty values', () => {
        document.cookie = 'empty=';
        expect(getCookie('empty')).toBe('');
      });
    });

    describe('deleteCookie', () => {
      it('deletes an existing cookie', () => {
        document.cookie = 'toDelete=value';
        deleteCookie('toDelete', '/', '');
        expect(getCookie('toDelete')).toBeNull();
      });

      it('handles deleting non-existent cookie', () => {
        // Should not throw
        expect(() => deleteCookie('nonExistent', '/', '')).not.toThrow();
      });
    });

    describe('areCookiesEnabled', () => {
      it('returns true when cookies are enabled', () => {
        expect(areCookiesEnabled()).toBe(true);
      });
    });
  });

  // ===========================================================================
  // Validation Functions Tests
  // ===========================================================================

  describe('validateTablePrefix', () => {
    const alertSpy = confirmPrompts;

    beforeEach(() => {
      dismissAlerts();
    });

    it('returns true for valid alphanumeric prefix', async () => {
      expect(validateTablePrefix('myprefix')).toBe(true);
      await settle();
      expect(alertSpy).not.toHaveBeenCalled();
    });

    it('returns true for prefix with underscores', () => {
      expect(validateTablePrefix('my_prefix_1')).toBe(true);
    });

    it('returns true for prefix with numbers', () => {
      expect(validateTablePrefix('prefix123')).toBe(true);
    });

    it('returns false for empty prefix', async () => {
      expect(validateTablePrefix('')).toBe(false);
      await settle();
      expect(alertSpy).toHaveBeenCalled();
    });

    it('returns false for prefix longer than 20 characters', async () => {
      expect(validateTablePrefix('a'.repeat(21))).toBe(false);
      await settle();
      expect(alertSpy).toHaveBeenCalled();
    });

    it('returns false for prefix with special characters', () => {
      expect(validateTablePrefix('prefix!')).toBe(false);
      expect(validateTablePrefix('prefix-name')).toBe(false);
      expect(validateTablePrefix('prefix.name')).toBe(false);
    });

    it('returns true for single character prefix', () => {
      expect(validateTablePrefix('a')).toBe(true);
    });

    it('returns true for exactly 20 character prefix', () => {
      expect(validateTablePrefix('a'.repeat(20))).toBe(true);
    });
  });
});
