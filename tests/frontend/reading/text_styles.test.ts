/**
 * Tests for reading/text_styles.ts - Dynamic CSS generation for text reading
 */
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import {
  generateTextStyles,
  injectTextStyles,
  generateParagraphStyles,
  removeTextStyles
} from '../../../src/frontend/js/modules/text/pages/reading/text_styles';
import type { TextReadingConfig } from '../../../src/frontend/js/modules/text/api/texts_api';

describe('reading/text_styles.ts', () => {
  beforeEach(() => {
    document.head.innerHTML = '';
    document.body.innerHTML = '';
  });

  afterEach(() => {
    document.head.innerHTML = '';
    document.body.innerHTML = '';
  });

  // ===========================================================================
  // Test Data Helpers
  // ===========================================================================

  function createConfig(overrides: Partial<TextReadingConfig> = {}): TextReadingConfig {
    return {
      textId: 1,
      langId: 1,
      textSize: 100,
      showLearning: true,
      displayStatTrans: 31, // Show status 1-5
      modeTrans: 1, // After text
      annTextSize: 75,
      removeSpaces: false,
      rightToLeft: false,
      ...overrides
    };
  }

  // ===========================================================================
  // generateTextStyles Tests
  // ===========================================================================

  describe('generateTextStyles', () => {
    it('generates CSS string', () => {
      const config = createConfig();
      const css = generateTextStyles(config);

      expect(typeof css).toBe('string');
      expect(css.length).toBeGreaterThan(0);
    });

    it('includes hide class rule', () => {
      const config = createConfig();
      const css = generateTextStyles(config);

      expect(css).toContain('.hide{display:none !important;}');
    });

    it('generates .word-ann styles', () => {
      const config = createConfig();
      const css = generateTextStyles(config);

      expect(css).toContain('.word-ann');
    });

    it('generates ruby layout for modeTrans 2', () => {
      const config = createConfig({ modeTrans: 2 });
      const css = generateTextStyles(config);

      expect(css).toContain('display: inline-block');
      expect(css).toContain('text-align: center');
    });

    it('generates ruby layout for modeTrans 4', () => {
      const config = createConfig({ modeTrans: 4 });
      const css = generateTextStyles(config);

      expect(css).toContain('margin-top: 0.2em');
    });

    it('uses annTextSize for font-size', () => {
      const config = createConfig({ annTextSize: 80 });
      const css = generateTextStyles(config);

      expect(css).toContain('font-size: 80%');
    });

    it('includes max-width constraint', () => {
      const config = createConfig();
      const css = generateTextStyles(config);

      expect(css).toContain('max-width: 15em');
    });

    it('includes annotation styling for bold', () => {
      const config = createConfig();
      const css = generateTextStyles(config);

      expect(css).toContain('.word-ann strong');
      expect(css).toContain('font-weight: bold');
    });

    it('includes annotation styling for italic', () => {
      const config = createConfig();
      const css = generateTextStyles(config);

      expect(css).toContain('.word-ann em');
      expect(css).toContain('font-style: italic');
    });

    it('includes annotation styling for strikethrough', () => {
      const config = createConfig();
      const css = generateTextStyles(config);

      expect(css).toContain('.word-ann del');
      expect(css).toContain('text-decoration: line-through');
    });

    it('includes annotation styling for links', () => {
      const config = createConfig();
      const css = generateTextStyles(config);

      expect(css).toContain('.word-ann a');
      expect(css).toContain('text-decoration: underline');
    });
  });

  // ===========================================================================
  // injectTextStyles Tests
  // ===========================================================================

  describe('injectTextStyles', () => {
    it('creates style element in head', () => {
      const config = createConfig();

      injectTextStyles(config);

      const styleEl = document.getElementById('text-dynamic-styles');
      expect(styleEl).not.toBeNull();
      expect(styleEl?.tagName).toBe('STYLE');
    });

    it('sets correct ID on style element', () => {
      const config = createConfig();

      injectTextStyles(config);

      const styleEl = document.getElementById('text-dynamic-styles');
      expect(styleEl).not.toBeNull();
    });

    it('includes generated CSS content', () => {
      const config = createConfig({ annTextSize: 60 });

      injectTextStyles(config);

      const styleEl = document.getElementById('text-dynamic-styles');
      expect(styleEl?.textContent).toContain('font-size: 60%');
    });

    it('removes existing style element before adding new one', () => {
      const config1 = createConfig({ annTextSize: 70 });
      const config2 = createConfig({ annTextSize: 90 });

      injectTextStyles(config1);
      injectTextStyles(config2);

      const styleElements = document.querySelectorAll('#text-dynamic-styles');
      expect(styleElements.length).toBe(1);
      expect(styleElements[0].textContent).toContain('font-size: 90%');
    });

    it('appends style to document head', () => {
      const config = createConfig();

      injectTextStyles(config);

      const styleEl = document.head.querySelector('#text-dynamic-styles');
      expect(styleEl).not.toBeNull();
    });
  });

  // ===========================================================================
  // generateParagraphStyles Tests
  // ===========================================================================

  describe('generateParagraphStyles', () => {
    it('includes margin-bottom', () => {
      const config = createConfig();
      const style = generateParagraphStyles(config);

      expect(style).toContain('margin-bottom: 10px');
    });

    it('includes font-size based on textSize', () => {
      const config = createConfig({ textSize: 120 });
      const style = generateParagraphStyles(config);

      expect(style).toContain('font-size: 120%');
    });

    it('includes word-break for removeSpaces languages', () => {
      const config = createConfig({ removeSpaces: true });
      const style = generateParagraphStyles(config);

      expect(style).toContain('word-break:break-all');
    });

    it('excludes word-break when removeSpaces is false', () => {
      const config = createConfig({ removeSpaces: false });
      const style = generateParagraphStyles(config);

      expect(style).not.toContain('word-break');
    });

    it('uses line-height 1 for ruby mode', () => {
      const config = createConfig({ modeTrans: 2 }); // Ruby above
      const style = generateParagraphStyles(config);

      expect(style).toContain('line-height: 1');
    });

    it('uses line-height 1.4 for non-ruby mode', () => {
      const config = createConfig({ modeTrans: 1 });
      const style = generateParagraphStyles(config);

      expect(style).toContain('line-height: 1.4');
    });
  });

  // ===========================================================================
  // removeTextStyles Tests
  // ===========================================================================

  describe('removeTextStyles', () => {
    it('removes existing style element', () => {
      const config = createConfig();
      injectTextStyles(config);

      removeTextStyles();

      const styleEl = document.getElementById('text-dynamic-styles');
      expect(styleEl).toBeNull();
    });

    it('does not throw when style element does not exist', () => {
      expect(() => removeTextStyles()).not.toThrow();
    });

    it('can be called multiple times without error', () => {
      const config = createConfig();
      injectTextStyles(config);

      removeTextStyles();
      removeTextStyles();
      removeTextStyles();

      const styleEl = document.getElementById('text-dynamic-styles');
      expect(styleEl).toBeNull();
    });
  });

  // ===========================================================================
  // Edge Cases
  // ===========================================================================

  describe('Edge Cases', () => {
    it('handles zero annTextSize', () => {
      const config = createConfig({ annTextSize: 0 });
      const css = generateTextStyles(config);

      expect(css).toContain('font-size: 0%');
    });

    it('handles very large textSize', () => {
      const config = createConfig({ textSize: 500 });
      const style = generateParagraphStyles(config);

      expect(style).toContain('font-size: 500%');
    });

    it('handles modeTrans value 0 (hidden)', () => {
      const config = createConfig({ modeTrans: 0 });
      const css = generateTextStyles(config);

      // Should still generate valid CSS
      expect(css.length).toBeGreaterThan(0);
    });

    it('generates margin-inline-start for modeTrans 1 (after text)', () => {
      const config = createConfig({ modeTrans: 1 });
      const css = generateTextStyles(config);

      expect(css).toContain('margin-inline-start: 0.2em');
    });

    it('generates margin-inline-end for modeTrans 3 (before text)', () => {
      const config = createConfig({ modeTrans: 3 });
      const css = generateTextStyles(config);

      expect(css).toContain('margin-inline-end: 0.2em');
    });
  });
});
