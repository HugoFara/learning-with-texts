/**
 * Tests for translation_api.ts - Translation API functions
 */
import { answerConfirms, dismissAlerts, settle } from '../helpers/confirm_answers';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import {
  deleteTranslation,
  addTranslation,
  getGlosbeTranslation,
  getTranslationFromGlosbeApi,
  getLibreTranslateTranslationBase,
  getLibreTranslateTranslation,
} from '../../../src/frontend/js/modules/vocabulary/services/translation_api';

describe('translation_api.ts', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
    vi.clearAllMocks();
  });

  afterEach(() => {
    document.body.innerHTML = '';
    vi.restoreAllMocks();
  });

  // ===========================================================================
  // deleteTranslation Tests
  // ===========================================================================

  describe('deleteTranslation', () => {
    it('returns early when no frame is available', () => {
      // Set up window.parent.frames.ro as undefined
      Object.defineProperty(window, 'parent', {
        value: {
          frames: { ro: undefined },
        },
        writable: true,
        configurable: true,
      });
      Object.defineProperty(window, 'opener', {
        value: undefined,
        writable: true,
        configurable: true,
      });

      // Should not throw
      expect(() => deleteTranslation()).not.toThrow();
    });

    it('clears translation field and marks form dirty', () => {
      // Create a mock frame document
      const mockFrameDoc = document.implementation.createHTMLDocument('frame');
      const input = mockFrameDoc.createElement('input');
      input.name = 'WoTranslation';
      input.value = 'existing translation';
      mockFrameDoc.body.appendChild(input);

      const makeDirtySpy = vi.fn();
      const mockFrame = {
        document: mockFrameDoc,
        lwtFormCheck: { makeDirty: makeDirtySpy },
      };

      Object.defineProperty(window, 'parent', {
        value: {
          frames: { ro: mockFrame },
        },
        writable: true,
        configurable: true,
      });

      deleteTranslation();

      expect(input.value).toBe('');
      expect(makeDirtySpy).toHaveBeenCalled();
    });

    it('does nothing when translation field is empty', () => {
      const mockFrameDoc = document.implementation.createHTMLDocument('frame');
      const input = mockFrameDoc.createElement('input');
      input.name = 'WoTranslation';
      input.value = '   '; // whitespace only
      mockFrameDoc.body.appendChild(input);

      const makeDirtySpy = vi.fn();
      const mockFrame = {
        document: mockFrameDoc,
        lwtFormCheck: { makeDirty: makeDirtySpy },
      };

      Object.defineProperty(window, 'parent', {
        value: {
          frames: { ro: mockFrame },
        },
        writable: true,
        configurable: true,
      });

      deleteTranslation();

      // makeDirty should not be called when field was already empty
      expect(makeDirtySpy).not.toHaveBeenCalled();
    });

    it('uses window.opener as fallback', () => {
      const mockFrameDoc = document.implementation.createHTMLDocument('frame');
      const input = mockFrameDoc.createElement('input');
      input.name = 'WoTranslation';
      input.value = 'translation';
      mockFrameDoc.body.appendChild(input);

      const makeDirtySpy = vi.fn();

      Object.defineProperty(window, 'parent', {
        value: {
          frames: { ro: undefined },
        },
        writable: true,
        configurable: true,
      });

      Object.defineProperty(window, 'opener', {
        value: {
          document: mockFrameDoc,
          lwtFormCheck: { makeDirty: makeDirtySpy },
        },
        writable: true,
        configurable: true,
      });

      deleteTranslation();

      expect(input.value).toBe('');
      expect(makeDirtySpy).toHaveBeenCalled();
    });
  });

  // ===========================================================================
  // addTranslation Tests
  // ===========================================================================

  describe('addTranslation', () => {
    it('alerts when no frame is available', async () => {
      Object.defineProperty(window, 'parent', {
        value: { frames: { ro: undefined } },
        writable: true,
        configurable: true,
      });
      Object.defineProperty(window, 'opener', {
        value: undefined,
        writable: true,
        configurable: true,
      });

      const alertSpy = dismissAlerts();

      addTranslation('test');

      await settle();
      expect(alertSpy).toHaveBeenCalledWith('The translation could not be copied.');
    });

    it('sets translation when field is empty', () => {
      // The function accesses frame.document.forms[0].WoTranslation
      // We need to create a proper mock where forms[0].WoTranslation is accessible
      const mockInput = { value: '', name: 'WoTranslation' };
      const mockForm = {
        WoTranslation: mockInput,
      };
      const makeDirtySpy = vi.fn();
      const mockFrame = {
        document: {
          forms: [mockForm],
        },
        lwtFormCheck: { makeDirty: makeDirtySpy },
      };

      Object.defineProperty(window, 'parent', {
        value: { frames: { ro: mockFrame } },
        writable: true,
        configurable: true,
      });

      addTranslation('new translation');

      expect(mockInput.value).toBe('new translation');
      expect(makeDirtySpy).toHaveBeenCalled();
    });

    it('appends translation with separator when field has value', () => {
      const mockInput = { value: 'existing', name: 'WoTranslation' };
      const mockForm = { WoTranslation: mockInput };
      const makeDirtySpy = vi.fn();
      const mockFrame = {
        document: { forms: [mockForm] },
        lwtFormCheck: { makeDirty: makeDirtySpy },
      };

      Object.defineProperty(window, 'parent', {
        value: { frames: { ro: mockFrame } },
        writable: true,
        configurable: true,
      });

      addTranslation('additional');

      expect(mockInput.value).toBe('existing / additional');
    });

    it('prompts for confirmation when translation already exists', async () => {
      const mockInput = { value: 'duplicate', name: 'WoTranslation' };
      const mockForm = { WoTranslation: mockInput };
      const makeDirtySpy = vi.fn();
      const mockFrame = {
        document: { forms: [mockForm] },
        lwtFormCheck: { makeDirty: makeDirtySpy },
      };

      Object.defineProperty(window, 'parent', {
        value: { frames: { ro: mockFrame } },
        writable: true,
        configurable: true,
      });

      // User confirms
      answerConfirms(true);

      addTranslation('duplicate');

      await settle();
      expect(mockInput.value).toBe('duplicate / duplicate');
    });

    it('does not add when user declines duplicate', async () => {
      const mockInput = { value: 'duplicate', name: 'WoTranslation' };
      const mockForm = { WoTranslation: mockInput };
      const makeDirtySpy = vi.fn();
      const mockFrame = {
        document: { forms: [mockForm] },
        lwtFormCheck: { makeDirty: makeDirtySpy },
      };

      Object.defineProperty(window, 'parent', {
        value: { frames: { ro: mockFrame } },
        writable: true,
        configurable: true,
      });

      // User declines
      answerConfirms(false);

      addTranslation('duplicate');

      // Value should remain unchanged
      await settle();
      expect(mockInput.value).toBe('duplicate');
      expect(makeDirtySpy).not.toHaveBeenCalled();
    });

    it('alerts when form WoTranslation is not an object', async () => {
      // WoTranslation is undefined/not an object
      const mockForm = {};
      const mockFrame = {
        document: { forms: [mockForm] },
        lwtFormCheck: { makeDirty: vi.fn() },
      };

      Object.defineProperty(window, 'parent', {
        value: { frames: { ro: mockFrame } },
        writable: true,
        configurable: true,
      });

      const alertSpy = dismissAlerts();

      addTranslation('test');

      await settle();
      expect(alertSpy).toHaveBeenCalledWith('The translation could not be copied.');
    });
  });

  // ===========================================================================
  // getGlosbeTranslation Tests
  // ===========================================================================

  describe('getGlosbeTranslation', () => {
    it('creates script element for JSONP request to Glosbe API', () => {
      const appendChildSpy = vi.spyOn(document.head, 'appendChild');

      getGlosbeTranslation('hello', 'en', 'fr');

      expect(appendChildSpy).toHaveBeenCalled();
      const script = appendChildSpy.mock.calls[0][0] as HTMLScriptElement;
      expect(script.tagName).toBe('SCRIPT');
      expect(script.src).toContain('glosbe.com/gapi/translate');
      expect(script.src).toContain('callback=getTranslationFromGlosbeApi');
    });

    it('includes correct parameters in request URL', () => {
      const appendChildSpy = vi.spyOn(document.head, 'appendChild');

      getGlosbeTranslation('word', 'de', 'en');

      const script = appendChildSpy.mock.calls[0][0] as HTMLScriptElement;
      expect(script.src).toContain('from=de');
      expect(script.src).toContain('dest=en');
      expect(script.src).toContain('phrase=word');
    });
  });

  // ===========================================================================
  // getTranslationFromGlosbeApi Tests
  // ===========================================================================

  describe('getTranslationFromGlosbeApi', () => {
    beforeEach(() => {
      document.body.innerHTML = '<div id="translations"></div>';
    });

    it('displays phrase translations', () => {
      const data = {
        tuc: [
          { phrase: { text: 'bonjour' } },
          { phrase: { text: 'salut' } },
        ],
        from: 'en',
        dest: 'fr',
        phrase: 'hello',
      };

      getTranslationFromGlosbeApi(data);

      const translations = document.getElementById('translations');
      expect(translations?.innerHTML).toContain('bonjour');
      expect(translations?.innerHTML).toContain('salut');
    });

    it('displays meanings when no phrase', () => {
      const data = {
        tuc: [{ meanings: [{ text: 'greeting' }] }],
        from: 'en',
        dest: 'fr',
        phrase: 'hello',
      };

      getTranslationFromGlosbeApi(data);

      const translations = document.getElementById('translations');
      expect(translations?.innerHTML).toContain('(greeting)');
    });

    it('escapes hostile Glosbe text and uses a data attribute, not inline onclick', () => {
      // Payloads that try to break out of both the attribute and the element.
      const data = {
        tuc: [
          { phrase: { text: '"><img src=x onerror=alert(1)>' } },
          { meanings: [{ text: '"><svg onload=alert(2)>' }] },
        ],
        from: 'en',
        dest: 'fr',
        phrase: 'hello',
      };

      getTranslationFromGlosbeApi(data);

      const container = document.getElementById('translations') as HTMLElement;
      // No inline handler for the hostile text to break out of.
      expect(container.innerHTML).not.toContain('onclick');
      // The word is carried via the delegated 'add-translation' handler.
      expect(container.innerHTML).toContain('data-action="add-translation"');
      // The escaping must prevent the payload from becoming a live element with
      // a script handler (attribute breakout via the embedded double quote).
      expect(container.querySelector('img, svg, [onerror], [onload]')).toBeNull();
    });

    it('displays message when no translations found', () => {
      const data = {
        tuc: [],
        from: 'en',
        dest: 'fr',
        phrase: 'unknownword',
      };

      getTranslationFromGlosbeApi(data);

      expect(document.body.innerHTML).toContain('No translations found');
    });

    it('adds suggestion for English lookup when target is not English', () => {
      document.body.innerHTML = '<div id="translations"></div>';

      const data = {
        tuc: [],
        from: 'de',
        dest: 'fr',
        phrase: 'wort',
      };

      // Mock $.ajax for the recursive call
      const ajaxSpy = vi.fn().mockImplementation(() => ({} as JQuery.jqXHR));
      (globalThis as unknown as Record<string, { ajax: typeof ajaxSpy }>).$ = { ajax: ajaxSpy };

      getTranslationFromGlosbeApi(data);

      // Should create a new translations container for de-en lookup
      expect(document.body.innerHTML).toContain('de-en');
    });

    it('displays translation count', () => {
      const data = {
        tuc: [
          { phrase: { text: 'one' } },
          { phrase: { text: 'two' } },
          { phrase: { text: 'three' } },
        ],
        from: 'en',
        dest: 'fr',
        phrase: 'test',
      };

      getTranslationFromGlosbeApi(data);

      expect(document.body.innerHTML).toContain('3 translations');
    });

    it('handles singular translation count', () => {
      const data = {
        tuc: [{ phrase: { text: 'only' } }],
        from: 'en',
        dest: 'fr',
        phrase: 'test',
      };

      getTranslationFromGlosbeApi(data);

      expect(document.body.innerHTML).toContain('1 translation');
      expect(document.body.innerHTML).not.toContain('1 translations');
    });

    it('handles API errors gracefully', () => {
      const data = {} as unknown as {
        tuc: Array<{ phrase?: { text: string }; meanings?: Array<{ text: string }> }>;
        from: string;
        dest: string;
        phrase: string;
      };

      // Should not throw
      expect(() => getTranslationFromGlosbeApi(data)).not.toThrow();

      expect(document.body.innerHTML).toContain('Retrieval error');
    });
  });

  // ===========================================================================
  // LibreTranslate Functions Tests
  // ===========================================================================

  describe('getLibreTranslateTranslationBase', () => {
    it('makes correct fetch request with default parameters', async () => {
      const mockResponse = { translatedText: 'Bonjour' };
      const fetchSpy = vi.spyOn(globalThis, 'fetch').mockResolvedValue({
        json: () => Promise.resolve(mockResponse),
      } as Response);

      const result = await getLibreTranslateTranslationBase(
        'Hello',
        'en',
        'fr'
      );

      expect(fetchSpy).toHaveBeenCalledWith(
        'http://localhost:5000/translate',
        expect.objectContaining({
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
        })
      );

      const callBody = JSON.parse(
        (fetchSpy.mock.calls[0][1] as RequestInit).body as string
      );
      expect(callBody).toEqual({
        q: 'Hello',
        source: 'en',
        target: 'fr',
        format: 'text',
        api_key: '',
      });

      expect(result).toBe('Bonjour');
    });

    it('includes API key when provided', async () => {
      const mockResponse = { translatedText: 'Hola' };
      const fetchSpy = vi.spyOn(globalThis, 'fetch').mockResolvedValue({
        json: () => Promise.resolve(mockResponse),
      } as Response);

      await getLibreTranslateTranslationBase(
        'Hello',
        'en',
        'es',
        'my-api-key'
      );

      const callBody = JSON.parse(
        (fetchSpy.mock.calls[0][1] as RequestInit).body as string
      );
      expect(callBody.api_key).toBe('my-api-key');
    });

    it('uses custom URL when provided', async () => {
      const mockResponse = { translatedText: 'Ciao' };
      const fetchSpy = vi.spyOn(globalThis, 'fetch').mockResolvedValue({
        json: () => Promise.resolve(mockResponse),
      } as Response);

      await getLibreTranslateTranslationBase(
        'Hello',
        'en',
        'it',
        '',
        'http://custom.libretranslate.com/translate'
      );

      expect(fetchSpy).toHaveBeenCalledWith(
        'http://custom.libretranslate.com/translate',
        expect.any(Object)
      );
    });

    it('handles auto language detection', async () => {
      const mockResponse = { translatedText: 'Привет' };
      const fetchSpy = vi.spyOn(globalThis, 'fetch').mockResolvedValue({
        json: () => Promise.resolve(mockResponse),
      } as Response);

      await getLibreTranslateTranslationBase('Hello', 'auto', 'ru');

      const callBody = JSON.parse(
        (fetchSpy.mock.calls[0][1] as RequestInit).body as string
      );
      expect(callBody.source).toBe('auto');
    });

    it('handles empty text', async () => {
      const mockResponse = { translatedText: '' };
      vi.spyOn(globalThis, 'fetch').mockResolvedValue({
        json: () => Promise.resolve(mockResponse),
      } as Response);

      const result = await getLibreTranslateTranslationBase('', 'en', 'fr');

      expect(result).toBe('');
    });

    it('handles special characters in text', async () => {
      const mockResponse = { translatedText: 'Bonjour le monde !' };
      vi.spyOn(globalThis, 'fetch').mockResolvedValue({
        json: () => Promise.resolve(mockResponse),
      } as Response);

      const result = await getLibreTranslateTranslationBase(
        'Hello, world!',
        'en',
        'fr'
      );

      expect(result).toBe('Bonjour le monde !');
    });

    it('handles unicode text', async () => {
      const mockResponse = { translatedText: 'こんにちは' };
      vi.spyOn(globalThis, 'fetch').mockResolvedValue({
        json: () => Promise.resolve(mockResponse),
      } as Response);

      const result = await getLibreTranslateTranslationBase(
        'Hello',
        'en',
        'ja'
      );

      expect(result).toBe('こんにちは');
    });
  });

  describe('getLibreTranslateTranslation', () => {
    it('extracts parameters from URL correctly', async () => {
      const mockResponse = { translatedText: 'Bonjour' };
      const fetchSpy = vi.spyOn(globalThis, 'fetch').mockResolvedValue({
        json: () => Promise.resolve(mockResponse),
      } as Response);

      const url = new URL(
        'http://localhost:5000?lwt_translator=libretranslate&lwt_key=mykey'
      );
      const result = await getLibreTranslateTranslation(url, 'Hello', 'en', 'fr');

      expect(result).toBe('Bonjour');
      const callBody = JSON.parse(
        (fetchSpy.mock.calls[0][1] as RequestInit).body as string
      );
      expect(callBody.api_key).toBe('mykey');
    });

    it('uses custom AJAX URL when lwt_translator_ajax is provided', async () => {
      const mockResponse = { translatedText: 'Hola' };
      const fetchSpy = vi.spyOn(globalThis, 'fetch').mockResolvedValue({
        json: () => Promise.resolve(mockResponse),
      } as Response);

      const url = new URL(
        'http://localhost:5000?lwt_translator=libretranslate&lwt_translator_ajax=' +
          encodeURIComponent('http://custom.server.com/api/translate')
      );
      await getLibreTranslateTranslation(url, 'Hello', 'en', 'es');

      expect(fetchSpy).toHaveBeenCalledWith(
        'http://custom.server.com/api/translate',
        expect.any(Object)
      );
    });

    it('throws error for unsupported translator', async () => {
      const url = new URL('http://localhost:5000?lwt_translator=google');

      await expect(
        getLibreTranslateTranslation(url, 'Hello', 'en', 'fr')
      ).rejects.toThrow('Translation API not supported: google!');
    });

    it('throws error when lwt_translator is missing', async () => {
      const url = new URL('http://localhost:5000');

      await expect(
        getLibreTranslateTranslation(url, 'Hello', 'en', 'fr')
      ).rejects.toThrow('Translation API not supported');
    });

    it('constructs default translate endpoint when lwt_translator_ajax is not provided', async () => {
      const mockResponse = { translatedText: 'Ciao' };
      const fetchSpy = vi.spyOn(globalThis, 'fetch').mockResolvedValue({
        json: () => Promise.resolve(mockResponse),
      } as Response);

      const url = new URL(
        'http://localhost:5000/api?lwt_translator=libretranslate'
      );
      await getLibreTranslateTranslation(url, 'Hello', 'en', 'it');

      // Should use the base URL + '/translate'
      expect(fetchSpy).toHaveBeenCalledWith(
        'http://localhost:5000/apitranslate',
        expect.any(Object)
      );
    });

    it('handles URL without API key', async () => {
      const mockResponse = { translatedText: 'Guten Tag' };
      const fetchSpy = vi.spyOn(globalThis, 'fetch').mockResolvedValue({
        json: () => Promise.resolve(mockResponse),
      } as Response);

      const url = new URL(
        'http://localhost:5000?lwt_translator=libretranslate'
      );
      await getLibreTranslateTranslation(url, 'Hello', 'en', 'de');

      const callBody = JSON.parse(
        (fetchSpy.mock.calls[0][1] as RequestInit).body as string
      );
      expect(callBody.api_key).toBe('');
    });
  });

  // ===========================================================================
  // Error Handling Tests
  // ===========================================================================

  describe('Error Handling', () => {
    it('propagates fetch errors', async () => {
      vi.spyOn(globalThis, 'fetch').mockRejectedValue(
        new Error('Network error')
      );

      await expect(
        getLibreTranslateTranslationBase('Hello', 'en', 'fr')
      ).rejects.toThrow('Network error');
    });

    it('handles JSON parse errors', async () => {
      vi.spyOn(globalThis, 'fetch').mockResolvedValue({
        json: () => Promise.reject(new Error('Invalid JSON')),
      } as unknown as Response);

      await expect(
        getLibreTranslateTranslationBase('Hello', 'en', 'fr')
      ).rejects.toThrow('Invalid JSON');
    });

    it('handles server error responses', async () => {
      vi.spyOn(globalThis, 'fetch').mockResolvedValue({
        json: () => Promise.resolve({ error: 'Rate limit exceeded' }),
      } as Response);

      // The function currently doesn't check for errors in response
      // It would return undefined for translatedText
      const result = await getLibreTranslateTranslationBase('Hello', 'en', 'fr');
      expect(result).toBeUndefined();
    });
  });

  // ===========================================================================
  // Edge Cases Tests
  // ===========================================================================

  describe('Edge Cases', () => {
    it('handles very long text', async () => {
      const longText = 'Hello '.repeat(1000);
      const mockResponse = { translatedText: 'Bonjour '.repeat(1000) };
      vi.spyOn(globalThis, 'fetch').mockResolvedValue({
        json: () => Promise.resolve(mockResponse),
      } as Response);

      const result = await getLibreTranslateTranslationBase(
        longText,
        'en',
        'fr'
      );

      expect(result).toBe('Bonjour '.repeat(1000));
    });

    it('handles newlines in text', async () => {
      const mockResponse = { translatedText: 'Ligne 1\nLigne 2' };
      vi.spyOn(globalThis, 'fetch').mockResolvedValue({
        json: () => Promise.resolve(mockResponse),
      } as Response);

      const result = await getLibreTranslateTranslationBase(
        'Line 1\nLine 2',
        'en',
        'fr'
      );

      expect(result).toBe('Ligne 1\nLigne 2');
    });

    it('handles HTML content in text', async () => {
      const mockResponse = { translatedText: '<p>Bonjour</p>' };
      vi.spyOn(globalThis, 'fetch').mockResolvedValue({
        json: () => Promise.resolve(mockResponse),
      } as Response);

      const result = await getLibreTranslateTranslationBase(
        '<p>Hello</p>',
        'en',
        'fr'
      );

      expect(result).toBe('<p>Bonjour</p>');
    });
  });
});
