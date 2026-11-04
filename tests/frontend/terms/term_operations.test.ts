/**
 * Tests for term_operations.ts - Translation updates, term editing, and annotations
 */
import { dismissAlerts, settle } from '../helpers/confirm_answers';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

// Use vi.hoisted to define mocks that need to be available during module loading
const { mockApiPost, mockApiGet, mockTermsApi } = vi.hoisted(() => ({
  mockApiPost: vi.fn(),
  mockApiGet: vi.fn(),
  mockTermsApi: {
    updateTranslation: vi.fn(),
    addWithTranslation: vi.fn(),
    incrementStatus: vi.fn(),
    getSimilar: vi.fn(),
    getSentences: vi.fn()
  }
}));

// Mock the API modules before importing the test subject
vi.mock('../../../src/frontend/js/shared/api/client', () => ({
  apiPost: mockApiPost,
  apiGet: mockApiGet
}));

vi.mock('../../../src/frontend/js/modules/vocabulary/api/terms_api', () => ({
  TermsApi: mockTermsApi
}));

import {
  setTransRoman,
  createTranslationRadio,
  createExampleSentencesHtml,
  saveImprovedTextAnnotation,
  updateTermTranslation,
  addTermTranslation,
  changeTableTestStatus,
  fetchSimilarTerms,
  showSimilarTerms,
  updateExampleSentencesZone,
  showExampleSentences,
  type TransData,
} from '../../../src/frontend/js/modules/vocabulary/services/term_operations';

// Mock lwtFormCheck global
const mockLwtFormCheck = {
  makeDirty: vi.fn(),
};

// Setup global mocks
beforeEach(() => {
  (window as unknown as Record<string, unknown>).lwtFormCheck = mockLwtFormCheck;
  mockApiPost.mockReset();
  mockApiGet.mockReset();
  // Provide default resolved values to prevent unhandled rejections
  // Return structure that won't cause errors in edit_term_ann_translations
  mockApiPost.mockResolvedValue({ data: { translations: [], term_id: 0 }, error: undefined });
  mockApiGet.mockResolvedValue({ data: { translations: [], term_id: 0 }, error: undefined });
  Object.values(mockTermsApi).forEach(mock => (mock as ReturnType<typeof vi.fn>).mockReset());
});

describe('term_operations.ts', () => {
  afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
    mockLwtFormCheck.makeDirty.mockClear();
  });

  // ===========================================================================
  // setTransRoman Tests
  // ===========================================================================

  describe('setTransRoman', () => {
    it('sets translation in WoTranslation textarea', () => {
      document.body.innerHTML = '<textarea name="WoTranslation"></textarea>';

      setTransRoman('hello', '');

      expect((document.querySelector('textarea[name="WoTranslation"]') as HTMLTextAreaElement).value).toBe('hello');
    });

    it('sets romanization in WoRomanization input', () => {
      document.body.innerHTML = '<input name="WoRomanization" />';

      setTransRoman('', 'pinyin');

      expect((document.querySelector('input[name="WoRomanization"]') as HTMLInputElement).value).toBe('pinyin');
    });

    it('sets both translation and romanization', () => {
      document.body.innerHTML = `
        <textarea name="WoTranslation"></textarea>
        <input name="WoRomanization" />
      `;

      setTransRoman('hello', 'hola');

      expect((document.querySelector('textarea[name="WoTranslation"]') as HTMLTextAreaElement).value).toBe('hello');
      expect((document.querySelector('input[name="WoRomanization"]') as HTMLInputElement).value).toBe('hola');
    });

    it('calls lwtFormCheck.makeDirty when translation is set', () => {
      document.body.innerHTML = '<textarea name="WoTranslation"></textarea>';

      setTransRoman('test', '');

      expect(mockLwtFormCheck.makeDirty).toHaveBeenCalled();
    });

    it('calls lwtFormCheck.makeDirty when romanization is set', () => {
      document.body.innerHTML = '<input name="WoRomanization" />';

      setTransRoman('', 'test');

      expect(mockLwtFormCheck.makeDirty).toHaveBeenCalled();
    });

    it('does not call makeDirty when no fields exist', () => {
      document.body.innerHTML = '<div>No form fields</div>';

      setTransRoman('test', 'test');

      expect(mockLwtFormCheck.makeDirty).not.toHaveBeenCalled();
    });

    it('handles empty strings', () => {
      document.body.innerHTML = `
        <textarea name="WoTranslation">existing</textarea>
        <input name="WoRomanization" value="existing" />
      `;

      setTransRoman('', '');

      expect((document.querySelector('textarea[name="WoTranslation"]') as HTMLTextAreaElement).value).toBe('');
      expect((document.querySelector('input[name="WoRomanization"]') as HTMLInputElement).value).toBe('');
    });

    it('handles special characters in translation', () => {
      document.body.innerHTML = '<textarea name="WoTranslation"></textarea>';

      setTransRoman('<script>alert("xss")</script>', '');

      expect((document.querySelector('textarea[name="WoTranslation"]') as HTMLTextAreaElement).value).toBe('<script>alert("xss")</script>');
    });

    it('handles unicode characters', () => {
      document.body.innerHTML = `
        <textarea name="WoTranslation"></textarea>
        <input name="WoRomanization" />
      `;

      setTransRoman('日本語', 'にほんご');

      expect((document.querySelector('textarea[name="WoTranslation"]') as HTMLTextAreaElement).value).toBe('日本語');
      expect((document.querySelector('input[name="WoRomanization"]') as HTMLInputElement).value).toBe('にほんご');
    });
  });

  // ===========================================================================
  // createTranslationRadio Tests
  // ===========================================================================

  describe('createTranslationRadio', () => {
    const baseTransData: TransData = {
      wid: 1,
      trans: 'hello',
      ann_index: '0',
      term_ord: '1',
      term_lc: 'test',
      lang_id: 1,
      translations: ['hello', 'hi', 'hey']
    };

    it('returns empty string when wid is null', () => {
      const transData = { ...baseTransData, wid: null };
      const result = createTranslationRadio('hello', transData);
      expect(result).toBe('');
    });

    it('returns empty string for empty translation', () => {
      const result = createTranslationRadio('', baseTransData);
      expect(result).toBe('');
    });

    it('returns empty string for whitespace-only translation', () => {
      const result = createTranslationRadio('   ', baseTransData);
      expect(result).toBe('');
    });

    it('returns empty string for asterisk translation', () => {
      const result = createTranslationRadio('*', baseTransData);
      expect(result).toBe('');
    });

    it('creates radio button HTML for valid translation', () => {
      const result = createTranslationRadio('hello', baseTransData);

      expect(result).toContain('<input');
      expect(result).toContain('type="radio"');
      expect(result).toContain('name="rg0"');
      expect(result).toContain('value="hello"');
      expect(result).toContain('hello');
    });

    it('marks radio as checked when translation matches current', () => {
      const transData = { ...baseTransData, trans: 'hello' };
      const result = createTranslationRadio('hello', transData);

      expect(result).toContain('checked="checked"');
    });

    it('does not mark radio as checked when translation differs', () => {
      const transData = { ...baseTransData, trans: 'goodbye' };
      const result = createTranslationRadio('hello', transData);

      expect(result).not.toContain('checked="checked"');
    });

    it('escapes HTML special characters in translation', () => {
      const result = createTranslationRadio('<script>', baseTransData);

      expect(result).toContain('&lt;script&gt;');
      expect(result).not.toContain('<script>');
    });

    it('uses correct annotation index in name attribute', () => {
      const transData = { ...baseTransData, ann_index: '42' };
      const result = createTranslationRadio('test', transData);

      expect(result).toContain('name="rg42"');
    });

    it('adds impr-ann-radio class to input', () => {
      const result = createTranslationRadio('test', baseTransData);

      expect(result).toContain('class="impr-ann-radio"');
    });

    it('handles translations with quotes', () => {
      const result = createTranslationRadio('say "hello"', baseTransData);

      expect(result).toContain('&quot;');
    });

    it('handles translations with ampersands', () => {
      const result = createTranslationRadio('cats & dogs', baseTransData);

      expect(result).toContain('&amp;');
    });

    it('trims whitespace from translation', () => {
      const result = createTranslationRadio('  hello  ', baseTransData);

      expect(result).toContain('value="hello"');
    });
  });

  // ===========================================================================
  // createExampleSentencesHtml Tests
  // ===========================================================================

  describe('createExampleSentencesHtml', () => {
    beforeEach(() => {
      (window as unknown as Record<string, unknown>).lwtFormCheck = mockLwtFormCheck;
    });

    it('returns a div element', () => {
      const result = createExampleSentencesHtml([], 'document.getElementById("target")');

      expect(result).toBeInstanceOf(HTMLDivElement);
    });

    it('returns empty div for empty sentences array', () => {
      const result = createExampleSentencesHtml([], 'target');

      expect(result.children.length).toBe(0);
    });

    it('creates one child per sentence', () => {
      const sentences: [string, string][] = [
        ['<b>Hello</b> world', 'Hello'],
        ['<b>Goodbye</b> world', 'Goodbye']
      ];

      const result = createExampleSentencesHtml(sentences, 'target');

      expect(result.children.length).toBe(2);
    });

    it('includes tick-button icon for each sentence', () => {
      const sentences: [string, string][] = [
        ['Test sentence', 'Test']
      ];

      const result = createExampleSentencesHtml(sentences, 'target');
      // Now uses Lucide SVG icons instead of PNG images (tick-button maps to circle-check)
      const icon = result.querySelector('i[data-lucide="circle-check"]');

      expect(icon).not.toBeNull();
      expect(icon?.getAttribute('title')).toBe('Choose');
    });

    it('creates clickable span with data attributes', () => {
      const sentences: [string, string][] = [
        ['Test sentence', 'Test']
      ];

      const result = createExampleSentencesHtml(sentences, 'myField');
      const clickable = result.querySelector('span.click');

      expect(clickable).not.toBeNull();
      expect(clickable?.getAttribute('data-action')).toBe('copy-sentence');
      expect(clickable?.getAttribute('data-target')).toBe('myField');
      expect(clickable?.getAttribute('data-sentence')).toBe('Test');
    });

    it('includes sentence display text', () => {
      const sentences: [string, string][] = [
        ['<b>Hello</b> world', 'Hello']
      ];

      const result = createExampleSentencesHtml(sentences, 'target');

      expect(result.innerHTML).toContain('<b>Hello</b> world');
    });

    it('stores sentence with special characters in data attribute', () => {
      const sentences: [string, string][] = [
        ['Test', "it's a test"]
      ];

      const result = createExampleSentencesHtml(sentences, 'target');
      const clickable = result.querySelector('span.click');

      expect(clickable?.getAttribute('data-sentence')).toBe("it's a test");
    });

    it('uses data-action copy-sentence for event delegation', () => {
      const sentences: [string, string][] = [
        ['Test', 'value']
      ];

      const result = createExampleSentencesHtml(sentences, 'target');
      const clickable = result.querySelector('span.click');

      expect(clickable?.getAttribute('data-action')).toBe('copy-sentence');
      expect(clickable?.getAttribute('data-target')).toBe('target');
    });

    it('handles multiple sentences correctly', () => {
      const sentences: [string, string][] = [
        ['First sentence', 'First'],
        ['Second sentence', 'Second'],
        ['Third sentence', 'Third']
      ];

      const result = createExampleSentencesHtml(sentences, 'target');

      expect(result.children.length).toBe(3);
      expect(result.innerHTML).toContain('First sentence');
      expect(result.innerHTML).toContain('Second sentence');
      expect(result.innerHTML).toContain('Third sentence');
    });

    it('creates correct structure: div > span.click > icon', () => {
      const sentences: [string, string][] = [
        ['Test', 'value']
      ];

      const result = createExampleSentencesHtml(sentences, 'target');
      const parentDiv = result.firstChild as HTMLDivElement;
      const clickSpan = parentDiv.firstChild as HTMLSpanElement;
      // Now uses Lucide SVG icons (I element) instead of IMG
      // tick-button maps to circle-check
      const icon = clickSpan.firstChild as HTMLElement;

      expect(parentDiv.tagName).toBe('DIV');
      expect(clickSpan.tagName).toBe('SPAN');
      expect(clickSpan.classList.contains('click')).toBe(true);
      expect(icon.tagName).toBe('I');
      expect(icon.getAttribute('data-lucide')).toBe('circle-check');
    });
  });

  // ===========================================================================
  // TransData Interface Tests
  // ===========================================================================

  describe('TransData interface', () => {
    it('accepts valid TransData object', () => {
      const data: TransData = {
        wid: 1,
        trans: 'translation',
        ann_index: '0',
        term_ord: '1',
        term_lc: 'word',
        lang_id: 1,
        translations: ['trans1', 'trans2']
      };

      expect(data.wid).toBe(1);
      expect(data.translations).toHaveLength(2);
    });

    it('allows null wid', () => {
      const data: TransData = {
        wid: null,
        trans: '',
        ann_index: '0',
        term_ord: '1',
        term_lc: 'word',
        lang_id: 1,
        translations: []
      };

      expect(data.wid).toBeNull();
    });

    it('allows empty translations array', () => {
      const data: TransData = {
        wid: 1,
        trans: '',
        ann_index: '0',
        term_ord: '1',
        term_lc: 'word',
        lang_id: 1,
        translations: []
      };

      expect(data.translations).toHaveLength(0);
    });
  });

  // ===========================================================================
  // saveImprovedTextAnnotation Tests
  // ===========================================================================

  describe('saveImprovedTextAnnotation', () => {
    beforeEach(() => {
      document.body.innerHTML = `
        <span id="wait1"><img src="icn/empty.gif" /></span>
        <div id="editimprtextdata" data_id="123"></div>
      `;
    });

    it('shows waiting indicator and makes POST request', async () => {
      mockApiPost.mockResolvedValue({ data: {} });

      await saveImprovedTextAnnotation(123, 'rg1', '{"rg1":"test"}');

      expect(mockApiPost).toHaveBeenCalledWith(
        '/texts/123/annotation',
        expect.objectContaining({
          elem: 'rg1',
          data: '{"rg1":"test"}',
        })
      );
    });

    it('alerts on error response', async () => {
      const alertSpy = dismissAlerts();
      mockApiPost.mockResolvedValue({ error: 'Test error message' });

      await saveImprovedTextAnnotation(123, 'rg1', '{}');

      await settle();
      expect(alertSpy).toHaveBeenCalledWith(
        expect.stringContaining('Test error message')
      );
    });
  });

  // ===========================================================================
  // updateTermTranslation Tests
  // ===========================================================================

  describe('updateTermTranslation', () => {
    beforeEach(() => {
      document.body.innerHTML = `
        <input id="trans-field" value="new translation" />
        <div id="editimprtextdata" data_id="1"></div>
      `;
    });

    it('alerts when translation is empty', async () => {
      document.body.innerHTML = '<input id="trans-field" value="" />';
      const alertSpy = dismissAlerts();

      await updateTermTranslation(1, '#trans-field');

      await settle();
      expect(alertSpy).toHaveBeenCalledWith(
        expect.stringContaining('empty')
      );
    });

    it('alerts when translation is asterisk', async () => {
      document.body.innerHTML = '<input id="trans-field" value="*" />';
      const alertSpy = dismissAlerts();

      await updateTermTranslation(1, '#trans-field');

      await settle();
      expect(alertSpy).toHaveBeenCalledWith(
        expect.stringContaining('*')
      );
    });

    it('makes POST request with trimmed translation', async () => {
      document.body.innerHTML = '<input id="trans-field" value="  trimmed  " />';
      mockTermsApi.updateTranslation.mockResolvedValue({ data: { update: 'success' } });

      await updateTermTranslation(42, '#trans-field');

      expect(mockTermsApi.updateTranslation).toHaveBeenCalledWith(42, 'trimmed');
    });

    it('does nothing on empty response (no update field)', async () => {
      mockTermsApi.updateTranslation.mockResolvedValue({ data: {} });

      await updateTermTranslation(1, '#trans-field');

      // When there's no error and no update field, the function just returns silently
      expect(mockTermsApi.updateTranslation).toHaveBeenCalled();
    });

    it('alerts on error response', async () => {
      const alertSpy = dismissAlerts();
      mockTermsApi.updateTranslation.mockResolvedValue({ error: 'DB error' });

      await updateTermTranslation(1, '#trans-field');

      await settle();
      expect(alertSpy).toHaveBeenCalledWith(
        expect.stringContaining('DB error')
      );
    });
  });

  // ===========================================================================
  // addTermTranslation Tests
  // ===========================================================================

  describe('addTermTranslation', () => {
    beforeEach(() => {
      document.body.innerHTML = `
        <input id="trans-field" value="new translation" />
        <div id="editimprtextdata" data_id="1"></div>
      `;
    });

    it('alerts when translation is empty', async () => {
      document.body.innerHTML = '<input id="trans-field" value="" />';
      const alertSpy = dismissAlerts();

      await addTermTranslation('#trans-field', 'word', 1);

      await settle();
      expect(alertSpy).toHaveBeenCalledWith(
        expect.stringContaining('empty')
      );
    });

    it('makes POST request with correct parameters', async () => {
      mockTermsApi.addWithTranslation.mockResolvedValue({ data: { add: 'success' } });

      await addTermTranslation('#trans-field', 'testword', 5);

      expect(mockTermsApi.addWithTranslation).toHaveBeenCalledWith('testword', 5, 'new translation');
    });

    it('alerts on error response', async () => {
      const alertSpy = dismissAlerts();
      mockTermsApi.addWithTranslation.mockResolvedValue({ error: 'Creation failed' });

      await addTermTranslation('#trans-field', 'word', 1);

      await settle();
      expect(alertSpy).toHaveBeenCalledWith(
        expect.stringContaining('Creation failed')
      );
    });
  });

  // ===========================================================================
  // changeTableTestStatus Tests
  // ===========================================================================

  describe('changeTableTestStatus', () => {
    beforeEach(() => {
      document.body.innerHTML = '<span id="STAT123">Current Status</span>';
    });

    it('makes POST request for status up', async () => {
      mockTermsApi.incrementStatus.mockResolvedValue({ data: {} });

      await changeTableTestStatus('123', true);

      expect(mockTermsApi.incrementStatus).toHaveBeenCalledWith(123, 'up');
    });

    it('makes POST request for status down', async () => {
      mockTermsApi.incrementStatus.mockResolvedValue({ data: {} });

      await changeTableTestStatus('123', false);

      expect(mockTermsApi.incrementStatus).toHaveBeenCalledWith(123, 'down');
    });

    it('updates DOM on successful response', async () => {
      mockTermsApi.incrementStatus.mockResolvedValue({ data: { increment: '<span class="status5">5</span>' } });

      await changeTableTestStatus('123', true);

      expect(document.getElementById('STAT123')!.innerHTML).toContain('status5');
    });

    it('does nothing on empty response', async () => {
      mockTermsApi.incrementStatus.mockResolvedValue({ data: {} });

      await changeTableTestStatus('123', true);

      expect(document.getElementById('STAT123')!.innerHTML).toBe('Current Status');
    });

    it('does nothing on error response', async () => {
      mockTermsApi.incrementStatus.mockResolvedValue({ error: 'Status change failed' });

      await changeTableTestStatus('123', true);

      expect(document.getElementById('STAT123')!.innerHTML).toBe('Current Status');
    });
  });

  // ===========================================================================
  // fetchSimilarTerms Tests
  // ===========================================================================

  describe('fetchSimilarTerms', () => {
    it('makes GET request with correct parameters', async () => {
      mockApiGet.mockResolvedValue({ data: { similar_terms: '<div>Similar</div>' } });

      await fetchSimilarTerms(5, 'hello');

      expect(mockApiGet).toHaveBeenCalledWith(
        '/similar-terms',
        { language_id: 5, term: 'hello' }
      );
    });

    it('returns data from API response', async () => {
      mockApiGet.mockResolvedValue({ data: { similar_terms: '<div>Test</div>' } });

      const result = await fetchSimilarTerms(1, 'test');

      expect(result).toEqual({ similar_terms: '<div>Test</div>' });
    });
  });

  // ===========================================================================
  // showSimilarTerms Tests
  // ===========================================================================

  describe('showSimilarTerms', () => {
    beforeEach(() => {
      document.body.innerHTML = `
        <div id="simwords"></div>
        <input id="langfield" value="5" />
        <input id="wordfield" value="hello" />
      `;
    });

    it('shows loading indicator', () => {
      mockApiGet.mockReturnValue(new Promise(() => {})); // Never resolves

      showSimilarTerms();

      // Now uses Lucide SVG spinner icon instead of waiting2.gif
      expect(document.getElementById('simwords')!.innerHTML).toContain('data-lucide="loader-2"');
    });

    it('updates simwords on success', async () => {
      mockApiGet.mockResolvedValue({ data: { similar_terms: '<div>Similar words here</div>' } });

      await showSimilarTerms();

      expect(document.getElementById('simwords')!.innerHTML).toBe('<div>Similar words here</div>');
    });
  });

  // ===========================================================================
  // updateExampleSentencesZone Tests
  // ===========================================================================

  describe('updateExampleSentencesZone', () => {
    beforeEach(() => {
      document.body.innerHTML = `
        <div id="exsent-waiting" style="display: block;"></div>
        <div id="exsent-sentences" style="display: none;"></div>
      `;
    });

    it('hides waiting indicator and shows sentences zone', () => {
      updateExampleSentencesZone([], 'target');

      expect((document.getElementById('exsent-waiting') as HTMLElement).style.display).toBe('none');
      expect((document.getElementById('exsent-sentences') as HTMLElement).style.display).not.toBe('none');
    });

    it('appends sentences to the zone', () => {
      const sentences: [string, string][] = [
        ['Test sentence', 'Test'],
      ];

      updateExampleSentencesZone(sentences, 'target');

      expect(document.getElementById('exsent-sentences')!.innerHTML).toContain('Test sentence');
    });
  });

  // ===========================================================================
  // showExampleSentences Tests
  // ===========================================================================

  describe('showExampleSentences', () => {
    beforeEach(() => {
      document.body.innerHTML = `
        <div id="exsent-interactable" style="display: block;"></div>
        <div id="exsent-waiting" style="display: none;"></div>
        <div id="exsent-sentences" style="display: none;"></div>
      `;
    });

    it('shows waiting indicator and hides interactable', () => {
      mockApiGet.mockReturnValue(new Promise(() => {})); // Never resolves

      showExampleSentences(1, 'word', 'target', 5);

      expect((document.getElementById('exsent-interactable') as HTMLElement).style.display).toBe('none');
      expect((document.getElementById('exsent-waiting') as HTMLElement).style.display).not.toBe('none');
    });

    it('calls API with term ID when wid is a valid number', () => {
      mockApiGet.mockReturnValue(new Promise(() => {}));

      showExampleSentences(1, 'word', 'target', 42);

      expect(mockApiGet).toHaveBeenCalledWith(
        '/sentences-with-term/42',
        expect.objectContaining({ language_id: 1, term_lc: 'word' })
      );
    });

    it('calls API without term ID when wid is -1 (advanced search)', () => {
      mockApiGet.mockReturnValue(new Promise(() => {}));

      showExampleSentences(1, 'word', 'target', -1);

      expect(mockApiGet).toHaveBeenCalledWith(
        '/sentences-with-term',
        expect.objectContaining({
          language_id: 1,
          term_lc: 'word',
          advanced_search: true,
        })
      );
    });

    it('calls API without term ID for non-integer wid', () => {
      mockApiGet.mockReturnValue(new Promise(() => {}));

      showExampleSentences(1, 'word', 'target', 'invalid');

      expect(mockApiGet).toHaveBeenCalledWith(
        '/sentences-with-term',
        expect.objectContaining({ language_id: 1, term_lc: 'word' })
      );
    });
  });

  // ===========================================================================
  // changeImprAnnText Tests
  // ===========================================================================

  describe('changeImprAnnText', () => {
    // Note: These tests are skipped because they require the serializeObject function
    it.skip('checks previous radio button and triggers save', () => {
      // This would need serializeObject to be available
    });
  });

  // ===========================================================================
  // changeImprAnnRadio Tests
  // ===========================================================================

  describe('changeImprAnnRadio', () => {
    // Note: These tests are skipped because they require the serializeObject function
    it.skip('triggers save when radio changes', () => {
      // This would need serializeObject to be available
    });
  });


  // ===========================================================================
  // Edge Cases
  // ===========================================================================

  describe('Edge Cases', () => {
    it('setTransRoman handles multiple WoTranslation textareas (only first)', () => {
      document.body.innerHTML = `
        <textarea name="WoTranslation"></textarea>
        <textarea name="WoTranslation"></textarea>
      `;

      // With querySelector, only the first matching element is selected
      setTransRoman('test', '');

      // The function uses querySelector which gets the first element only
      expect((document.querySelectorAll('textarea[name="WoTranslation"]')[0] as HTMLTextAreaElement).value).toBe('test');
    });

    it('createTranslationRadio handles very long translations', () => {
      const longTrans = 'a'.repeat(1000);
      const result = createTranslationRadio(longTrans, {
        wid: 1,
        trans: '',
        ann_index: '0',
        term_ord: '1',
        term_lc: 'word',
        lang_id: 1,
        translations: []
      });

      expect(result).toContain(longTrans);
    });

    it('createExampleSentencesHtml handles sentences with HTML', () => {
      const sentences: [string, string][] = [
        ['<div class="highlight">Test</div>', 'Test']
      ];

      const result = createExampleSentencesHtml(sentences, 'target');

      // The HTML should be preserved in the display
      expect(result.innerHTML).toContain('<div class="highlight">');
    });

    it('createExampleSentencesHtml handles empty string values', () => {
      const sentences: [string, string][] = [
        ['', '']
      ];

      const result = createExampleSentencesHtml(sentences, 'target');

      expect(result.children.length).toBe(1);
    });
  });
});
