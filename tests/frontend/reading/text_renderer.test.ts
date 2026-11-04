/**
 * Tests for reading/text_renderer.ts - Text rendering functions
 */
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import {
  renderWord,
  renderText,
  updateWordStatusInDOM,
  updateWordTranslationInDOM,
  calculateCharCount,
  type RenderSettings
} from '../../../src/frontend/js/modules/text/pages/reading/text_renderer';
import type { WordData } from '../../../src/frontend/js/modules/text/pages/reading/stores/word_store';

describe('reading/text_renderer.ts', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
  });

  afterEach(() => {
    document.body.innerHTML = '';
  });

  // ===========================================================================
  // Test Data Helpers
  // ===========================================================================

  function createWordData(overrides: Partial<WordData> = {}): WordData {
    return {
      position: 1,
      text: 'hello',
      hex: '68656c6c6f',
      isNotWord: false,
      hidden: false,
      wordCount: 1,
      sentenceId: 1,
      status: 0,
      wordId: null,
      translation: null,
      romanization: null,
      ...overrides
    };
  }

  const defaultSettings: RenderSettings = {
    showAll: false,
    showTranslations: true,
    rightToLeft: false,
    textSize: 100
  };

  // ===========================================================================
  // renderWord Tests
  // ===========================================================================

  describe('renderWord', () => {
    it('renders punctuation/whitespace without click class', () => {
      const word = createWordData({ isNotWord: true, text: '.' });
      const result = renderWord(word, defaultSettings);

      expect(result).not.toContain('click');
      expect(result).toContain('.');
    });

    it('renders paragraph break as br tag', () => {
      const word = createWordData({ isNotWord: true, text: '¶' });
      const result = renderWord(word, defaultSettings);

      expect(result).toContain('<br />');
    });

    it('renders word with click class', () => {
      const word = createWordData();
      const result = renderWord(word, defaultSettings);

      expect(result).toContain('click');
    });

    it('renders word with word class for single words', () => {
      const word = createWordData({ wordCount: 1 });
      const result = renderWord(word, defaultSettings);

      expect(result).toContain('class="');
      expect(result).toContain('word');
      expect(result).toContain('wsty');
    });

    it('renders multiword with mword class', () => {
      const word = createWordData({ wordCount: 2 });
      const result = renderWord(word, defaultSettings);

      expect(result).toContain('mword');
    });

    it('includes status class', () => {
      const word = createWordData({ status: 3 });
      const result = renderWord(word, defaultSettings);

      expect(result).toContain('status3');
    });

    it('includes hex as the data_hex attribute', () => {
      const word = createWordData({ hex: 'abc123' });
      const result = renderWord(word, defaultSettings);

      expect(result).toContain('data_hex="abc123"');
    });

    it('includes word ID class when present', () => {
      const word = createWordData({ wordId: 42 });
      const result = renderWord(word, defaultSettings);

      expect(result).toContain('word42');
    });

    it('includes order class', () => {
      const word = createWordData({ position: 5 });
      const result = renderWord(word, defaultSettings);

      expect(result).toContain('order5');
    });

    it('includes span ID with position and wordCount', () => {
      const word = createWordData({ position: 3, wordCount: 2 });
      const result = renderWord(word, defaultSettings);

      expect(result).toContain('id="ID-3-2"');
    });

    it('includes data_order attribute', () => {
      const word = createWordData({ position: 5 });
      const result = renderWord(word, defaultSettings);

      expect(result).toContain('data_order="5"');
    });

    it('includes data_status attribute', () => {
      const word = createWordData({ status: 5 });
      const result = renderWord(word, defaultSettings);

      expect(result).toContain('data_status="5"');
    });

    it('includes data_wid when wordId present', () => {
      const word = createWordData({ wordId: 123 });
      const result = renderWord(word, defaultSettings);

      expect(result).toContain('data_wid="123"');
    });

    it('includes data_trans when translation present', () => {
      const word = createWordData({ translation: 'hola' });
      const result = renderWord(word, defaultSettings);

      expect(result).toContain('data_trans="hola"');
    });

    it('includes data_rom when romanization present', () => {
      const word = createWordData({ romanization: 'nihao' });
      const result = renderWord(word, defaultSettings);

      expect(result).toContain('data_rom="nihao"');
    });

    it('includes data_code for multiwords', () => {
      const word = createWordData({ wordCount: 3 });
      const result = renderWord(word, defaultSettings);

      expect(result).toContain('data_code="3"');
    });

    it('escapes HTML in text', () => {
      const word = createWordData({ text: '<script>' });
      const result = renderWord(word, defaultSettings);

      expect(result).not.toContain('<script>');
      expect(result).toContain('&lt;script&gt;');
    });

    it('shows wordCount as content in showAll mode for multiwords', () => {
      const word = createWordData({ wordCount: 2, text: 'hello world' });
      const settings = { ...defaultSettings, showAll: true };
      const result = renderWord(word, settings);

      expect(result).toContain('>2</span>');
    });

    it('adds hide class when hidden', () => {
      const word = createWordData({ hidden: true });
      const result = renderWord(word, defaultSettings);

      expect(result).toContain('hide');
    });

    it('adds hide class for hidden punctuation', () => {
      const word = createWordData({ isNotWord: true, hidden: true, text: '.' });
      const result = renderWord(word, defaultSettings);

      expect(result).toContain('hide');
    });
  });

  // ===========================================================================
  // renderText Tests
  // ===========================================================================

  describe('renderText', () => {
    it('returns empty string for empty array', () => {
      const result = renderText([], defaultSettings);

      expect(result).toBe('');
    });

    it('wraps words in sentence spans', () => {
      const words = [
        createWordData({ position: 1, sentenceId: 1 }),
        createWordData({ position: 2, sentenceId: 1 })
      ];
      const result = renderText(words, defaultSettings);

      expect(result).toContain('<span id="sent_1">');
      expect(result).toContain('</span>');
    });

    it('creates new sentence span for different sentence ID', () => {
      const words = [
        createWordData({ position: 1, sentenceId: 1 }),
        createWordData({ position: 2, sentenceId: 2 })
      ];
      const result = renderText(words, defaultSettings);

      expect(result).toContain('id="sent_1"');
      expect(result).toContain('id="sent_2"');
    });

    it('renders all words in order', () => {
      const words = [
        createWordData({ position: 1, text: 'hello', sentenceId: 1 }),
        createWordData({ position: 2, text: ' ', isNotWord: true, sentenceId: 1 }),
        createWordData({ position: 3, text: 'world', sentenceId: 1 })
      ];
      const result = renderText(words, defaultSettings);

      expect(result).toContain('hello');
      expect(result).toContain('world');
    });

    it('closes last sentence span', () => {
      const words = [createWordData({ position: 1, sentenceId: 1 })];
      const result = renderText(words, defaultSettings);

      expect(result.endsWith('</span>')).toBe(true);
    });
  });

  // ===========================================================================
  // updateWordStatusInDOM Tests
  // ===========================================================================

  describe('updateWordStatusInDOM', () => {
    it('updates status class on matching elements', () => {
      document.body.innerHTML = '<span class="status0" data_hex="123">word</span>';
      const element = document.querySelector('[data_hex="123"]') as HTMLElement;

      updateWordStatusInDOM('123', 3);

      expect(element.classList.contains('status3')).toBe(true);
      expect(element.classList.contains('status0')).toBe(false);
    });

    it('updates data_status attribute', () => {
      document.body.innerHTML = '<span class="status0" data_hex="123" data_status="0">word</span>';
      const element = document.querySelector('[data_hex="123"]') as HTMLElement;

      updateWordStatusInDOM('123', 5);

      expect(element.getAttribute('data_status')).toBe('5');
    });

    it('updates multiple matching elements', () => {
      document.body.innerHTML = `
        <span class="status0" data_hex="123">word1</span>
        <span class="status0" data_hex="123">word2</span>
      `;

      updateWordStatusInDOM('123', 2);

      const elements = document.querySelectorAll('[data_hex="123"]');
      elements.forEach(el => {
        expect(el.classList.contains('status2')).toBe(true);
      });
    });

    it('adds word ID class when provided', () => {
      document.body.innerHTML = '<span class="status0" data_hex="123">word</span>';
      const element = document.querySelector('[data_hex="123"]') as HTMLElement;

      updateWordStatusInDOM('123', 1, 456);

      expect(element.classList.contains('word456')).toBe(true);
    });

    it('sets data_wid when word ID provided', () => {
      document.body.innerHTML = '<span class="status0" data_hex="123">word</span>';
      const element = document.querySelector('[data_hex="123"]') as HTMLElement;

      updateWordStatusInDOM('123', 1, 789);

      expect(element.getAttribute('data_wid')).toBe('789');
    });

    it('removes data_wid when word ID is 0', () => {
      document.body.innerHTML = '<span class="status1" data_hex="123" data_wid="123">word</span>';
      const element = document.querySelector('[data_hex="123"]') as HTMLElement;

      updateWordStatusInDOM('123', 0, 0);

      expect(element.hasAttribute('data_wid')).toBe(false);
    });

    it('uses custom container when provided', () => {
      const container = document.createElement('div');
      container.innerHTML = '<span class="status0" data_hex="123">word</span>';
      document.body.appendChild(container);

      document.body.innerHTML += '<span class="status0" data_hex="123">outside</span>';

      updateWordStatusInDOM('123', 4, null, container);

      const insideEl = container.querySelector('[data_hex="123"]') as HTMLElement;
      const outsideEl = document.body.querySelector(':scope > [data_hex="123"]') as HTMLElement;

      expect(insideEl.classList.contains('status4')).toBe(true);
      expect(outsideEl.classList.contains('status0')).toBe(true);
    });
  });

  // ===========================================================================
  // updateWordTranslationInDOM Tests
  // ===========================================================================

  describe('updateWordTranslationInDOM', () => {
    it('sets data_trans attribute', () => {
      document.body.innerHTML = '<span data_hex="123">word</span>';
      const element = document.querySelector('[data_hex="123"]') as HTMLElement;

      updateWordTranslationInDOM('123', 'translation', '');

      expect(element.getAttribute('data_trans')).toBe('translation');
    });

    it('adds the annotation to a word that had none when the text rendered', () => {
      renderText([], {
        showAll: false, showTranslations: true, rightToLeft: false, textSize: 100,
        showLearning: true, displayStatTrans: 1, modeTrans: 1
      } as RenderSettings);
      document.body.innerHTML = '<span data_hex="123" data_status="1">word</span>';

      updateWordTranslationInDOM('123', 'basket', '');

      expect(document.querySelector('[data_hex="123"]')?.innerHTML)
        .toBe('word<span class="word-ann">basket</span>');
    });

    it('leaves a word unannotated when its status is not annotated', () => {
      renderText([], {
        showAll: false, showTranslations: true, rightToLeft: false, textSize: 100,
        showLearning: true, displayStatTrans: 1, modeTrans: 1
      } as RenderSettings);
      document.body.innerHTML = '<span data_hex="123" data_status="99">word</span>';

      updateWordTranslationInDOM('123', 'basket', '');

      expect(document.querySelector('.word-ann')).toBeNull();
    });

    it('sets data_rom attribute', () => {
      document.body.innerHTML = '<span data_hex="123">word</span>';
      const element = document.querySelector('[data_hex="123"]') as HTMLElement;

      updateWordTranslationInDOM('123', '', 'romanization');

      expect(element.getAttribute('data_rom')).toBe('romanization');
    });

    it('removes data_trans when empty', () => {
      document.body.innerHTML = '<span data_hex="123" data_trans="old">word</span>';
      const element = document.querySelector('[data_hex="123"]') as HTMLElement;

      updateWordTranslationInDOM('123', '', '');

      expect(element.hasAttribute('data_trans')).toBe(false);
    });

    it('removes data_rom when empty', () => {
      document.body.innerHTML = '<span data_hex="123" data_rom="old">word</span>';
      const element = document.querySelector('[data_hex="123"]') as HTMLElement;

      updateWordTranslationInDOM('123', '', '');

      expect(element.hasAttribute('data_rom')).toBe(false);
    });

    it('updates multiple elements', () => {
      document.body.innerHTML = `
        <span data_hex="123">word1</span>
        <span data_hex="123">word2</span>
      `;

      updateWordTranslationInDOM('123', 'new trans', 'new rom');

      const elements = document.querySelectorAll('[data_hex="123"]');
      elements.forEach(el => {
        expect(el.getAttribute('data_trans')).toBe('new trans');
        expect(el.getAttribute('data_rom')).toBe('new rom');
      });
    });

    it('uses custom container when provided', () => {
      const container = document.createElement('div');
      container.innerHTML = '<span data_hex="123">word</span>';
      document.body.appendChild(container);

      updateWordTranslationInDOM('123', 'trans', 'rom', container);

      const el = container.querySelector('[data_hex="123"]') as HTMLElement;
      expect(el.getAttribute('data_trans')).toBe('trans');
    });
  });

  // ===========================================================================
  // calculateCharCount Tests
  // ===========================================================================

  describe('calculateCharCount', () => {
    it('returns 0 for empty array', () => {
      expect(calculateCharCount([])).toBe(0);
    });

    it('counts characters in single-word tokens', () => {
      const words = [
        createWordData({ text: 'hello', wordCount: 1 }),
        createWordData({ text: 'world', wordCount: 1 })
      ];

      expect(calculateCharCount(words)).toBe(10);
    });

    it('excludes punctuation', () => {
      const words = [
        createWordData({ text: 'hello', wordCount: 1 }),
        createWordData({ text: '.', isNotWord: true }),
        createWordData({ text: 'world', wordCount: 1 })
      ];

      expect(calculateCharCount(words)).toBe(10);
    });

    it('excludes multiwords', () => {
      const words = [
        createWordData({ text: 'hello', wordCount: 1 }),
        createWordData({ text: 'hello world', wordCount: 2 })
      ];

      expect(calculateCharCount(words)).toBe(5);
    });

    it('handles unicode characters', () => {
      const words = [
        createWordData({ text: '日本語', wordCount: 1 })
      ];

      expect(calculateCharCount(words)).toBe(3);
    });
  });

  // ===========================================================================
  // Edge Cases
  // ===========================================================================

  describe('Edge Cases', () => {
    it('handles empty text in word', () => {
      const word = createWordData({ text: '' });
      const result = renderWord(word, defaultSettings);

      expect(result).toContain('id="ID-');
    });

    it('handles special characters in translation', () => {
      const word = createWordData({ translation: '"quoted" & <tagged>' });
      const result = renderWord(word, defaultSettings);

      // The escaping uses textContent approach, quotes become HTML entities
      expect(result).toContain('&amp;');
      expect(result).toContain('&lt;tagged&gt;');
    });

    it('handles very long text', () => {
      const longText = 'a'.repeat(1000);
      const word = createWordData({ text: longText });
      const result = renderWord(word, defaultSettings);

      expect(result).toContain(longText);
    });

    it('handles status 98 (ignored)', () => {
      const word = createWordData({ status: 98 });
      const result = renderWord(word, defaultSettings);

      expect(result).toContain('status98');
    });

    it('handles status 99 (well-known)', () => {
      const word = createWordData({ status: 99 });
      const result = renderWord(word, defaultSettings);

      expect(result).toContain('status99');
    });
  });
});
