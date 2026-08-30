/**
 * Tests for text_check_display.ts - render the parse report from
 * POST /texts/check.
 *
 * Translations are not loaded here, so t() returns the key it was given;
 * assertions target structure and data rather than copy.
 */
import { describe, it, expect, beforeEach } from 'vitest';

import { renderCheckReport } from '../../../src/frontend/js/modules/text/pages/text_check_display';
import type { TextCheckReport } from '../../../src/frontend/js/modules/text/api/texts_api';

/** A report with everything empty, for tests that vary one part. */
function emptyReport(): TextCheckReport {
  return {
    preview: '',
    sentences: [],
    words: [],
    nonWords: [],
    multiWords: [],
    rtlScript: false,
    warning: 'ok'
  };
}

describe('text_check_display.ts', () => {
  let container: HTMLElement;

  beforeEach(() => {
    document.body.innerHTML = '<div id="check_text"></div>';
    container = document.getElementById('check_text') as HTMLElement;
  });

  describe('preview', () => {
    it('renders the preview text', () => {
      renderCheckReport({ ...emptyReport(), preview: 'Bonjour Manon.' }, container);

      expect(container.textContent).toContain('Bonjour Manon.');
    });

    it('breaks paragraphs on the pilcrow marker', () => {
      renderCheckReport({ ...emptyReport(), preview: 'One ¶ Two' }, container);

      expect(container.querySelectorAll('br')).toHaveLength(2);
      expect(container.textContent).toContain('One');
      expect(container.textContent).toContain('Two');
    });

    it('marks the preview rtl for a right-to-left language', () => {
      renderCheckReport({ ...emptyReport(), preview: 'שלום', rtlScript: true }, container);

      expect(container.querySelector('p[dir="rtl"]')).not.toBeNull();
    });
  });

  describe('sentences', () => {
    it('lists each sentence in order', () => {
      renderCheckReport(
        { ...emptyReport(), sentences: ['First one.', 'Second one.'] },
        container
      );

      const items = Array.from(container.querySelectorAll('ol li')).map(li => li.textContent);
      expect(items).toEqual(['First one.', 'Second one.']);
    });
  });

  describe('warning', () => {
    it('shows a banner when nothing parsed into words', () => {
      renderCheckReport({ ...emptyReport(), warning: 'no_words' }, container);

      expect(container.querySelector('.notification.is-warning')).not.toBeNull();
    });

    it('shows a banner when almost nothing parsed into words', () => {
      renderCheckReport({ ...emptyReport(), warning: 'almost_no_words' }, container);

      expect(container.querySelector('.notification.is-warning')).not.toBeNull();
    });

    it('stays quiet when the parse looks fine', () => {
      renderCheckReport({ ...emptyReport(), warning: 'ok' }, container);

      expect(container.querySelector('.notification.is-warning')).toBeNull();
    });
  });

  describe('tallies', () => {
    it('lists words with their counts', () => {
      renderCheckReport(
        { ...emptyReport(), words: [['bonjour', 2, ''], ['manon', 1, '']] },
        container
      );

      const items = Array.from(container.querySelectorAll('.wordlist li')).map(li => li.textContent);
      expect(items).toEqual(['[bonjour] — 2', '[manon] — 1']);
    });

    it('calls out a word that is already saved', () => {
      renderCheckReport(
        { ...emptyReport(), words: [['bonjour', 1, 'hello'], ['manon', 1, '']] },
        container
      );

      const saved = container.querySelectorAll('.wordlist .has-text-danger');
      expect(saved).toHaveLength(1);
      expect(saved[0].textContent).toBe('[bonjour] — 1 — hello');
    });

    it('lists expressions and non-words with totals', () => {
      renderCheckReport(
        {
          ...emptyReport(),
          multiWords: [['bon jour', 1, 'good day']],
          nonWords: [['.', 3]]
        },
        container
      );

      expect(container.querySelector('.expressionlist li')?.textContent)
        .toBe('[bon jour] — 1 — good day');
      expect(container.querySelector('.nonwordlist li')?.textContent).toBe('[.] — 3');
      expect(container.textContent).toContain('TOTAL: 1');
    });

    it('marks list items rtl for a right-to-left language', () => {
      renderCheckReport(
        { ...emptyReport(), words: [['שלום', 1, '']], rtlScript: true },
        container
      );

      expect(container.querySelector('.wordlist li[dir="rtl"]')).not.toBeNull();
    });
  });

  describe('untrusted term text', () => {
    it('renders a term containing markup as text, not markup', () => {
      renderCheckReport(
        { ...emptyReport(), words: [['<img src=x onerror=alert(1)>', 1, '']] },
        container
      );

      expect(container.querySelector('img')).toBeNull();
      expect(container.textContent).toContain('<img src=x onerror=alert(1)>');
    });

    it('renders a preview containing markup as text', () => {
      renderCheckReport({ ...emptyReport(), preview: '<script>bad()</script>' }, container);

      expect(container.querySelector('script')).toBeNull();
      expect(container.textContent).toContain('<script>bad()</script>');
    });
  });

  it('replaces a previous report rather than appending to it', () => {
    renderCheckReport({ ...emptyReport(), sentences: ['Old.'] }, container);
    renderCheckReport({ ...emptyReport(), sentences: ['New.'] }, container);

    const items = Array.from(container.querySelectorAll('ol li')).map(li => li.textContent);
    expect(items).toEqual(['New.']);
  });
});
