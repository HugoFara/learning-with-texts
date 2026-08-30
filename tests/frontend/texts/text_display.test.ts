/**
 * Tests for text_display_app.ts — the annotated ("improved") text view.
 *
 * The markup asserted here is the contract the server-rendered
 * display_text.php used to produce: annotation_toggle.ts finds terms and
 * translations by the .anntermruby / .anntransruby2 classes at click time, so
 * a rename here silently breaks the show/hide buttons.
 */
import { describe, it, expect, beforeEach, vi } from 'vitest';

const getAnnotation = vi.fn();

vi.mock('alpinejs', () => ({
  default: { data: vi.fn() }
}));

vi.mock('../../../src/frontend/js/modules/text/api/texts_api', () => ({
  TextsApi: {
    get getAnnotation() {
      return getAnnotation;
    }
  }
}));

import { textDisplayData } from '../../../src/frontend/js/modules/text/pages/text_display_app';

/** Build an annotation item with sane defaults. */
function item(overrides: Record<string, unknown> = {}) {
  return {
    order: 0,
    text: 'Hallo',
    wordId: 1,
    translation: 'hello',
    romanization: '',
    isWord: true,
    ...overrides
  };
}

/** Respond to getAnnotation with these items and config. */
function respond(items: unknown[], config: Record<string, unknown> = {}) {
  getAnnotation.mockResolvedValue({
    data: {
      items,
      config: {
        textId: 7,
        title: 'T',
        sourceUri: '',
        audioUri: '',
        langId: 1,
        textSize: 150,
        rtlScript: false,
        hasAnnotation: true,
        ttsClass: null,
        ...config
      }
    }
  });
}

/** Install the page shell the view renders, with the config island. */
function setupDom(textId: number = 7): void {
  document.body.innerHTML =
    '<div id="print"></div>' +
    `<script type="application/json" id="text-display-config">{"textId":${textId}}</script>`;
}

describe('textDisplayData', () => {
  beforeEach(() => {
    getAnnotation.mockReset();
    setupDom();
  });

  it('reads the text id from the config island', () => {
    setupDom(42);
    expect(textDisplayData().textId).toBe(42);
  });

  it('renders a word as ruby with the toggle classes intact', async () => {
    respond([item({ text: 'Hallo', translation: 'hello' })]);
    const app = textDisplayData();

    await app.init();

    const html = document.getElementById('print')!.innerHTML;
    expect(html).toContain('anntermruby');
    expect(html).toContain('anntransruby2');
    expect(html).toContain('Hallo');
    expect(html).toContain('hello');
    expect(app.loading).toBe(false);
    expect(app.error).toBe('');
  });

  it('puts the romanization in a title attribute, and omits it when empty', async () => {
    respond([item({ romanization: 'ha-lo' })]);
    await textDisplayData().init();
    expect(document.getElementById('print')!.innerHTML).toContain('title="ha-lo"');

    setupDom();
    respond([item({ romanization: '' })]);
    await textDisplayData().init();
    expect(document.getElementById('print')!.innerHTML).not.toContain('title=');
  });

  it('escapes markup in terms, translations and romanizations', async () => {
    respond([
      item({
        text: '<b>x</b>',
        translation: '"quoted" & <i>y</i>',
        romanization: '<script>'
      })
    ]);

    await textDisplayData().init();

    const container = document.getElementById('print')!;
    expect(container.querySelector('b')).toBeNull();
    expect(container.querySelector('i')).toBeNull();
    expect(container.querySelector('script')).toBeNull();
    expect(container.textContent).toContain('<b>x</b>');
  });

  it('breaks a paragraph marker into a new paragraph at the same size', async () => {
    respond(
      [
        item({ text: 'a' }),
        item({ order: -1, wordId: null, isWord: false, text: '¶', translation: '' }),
        item({ text: 'b' })
      ],
      { textSize: 120 }
    );

    await textDisplayData().init();

    const paragraphs = document.getElementById('print')!.querySelectorAll('p');
    expect(paragraphs.length).toBe(2);
    expect(paragraphs[0].getAttribute('style')).toContain('font-size:120%');
    expect(paragraphs[1].getAttribute('style')).toContain('font-size:120%');
  });

  it('marks the container right-to-left only for RTL languages', async () => {
    respond([item()], { rtlScript: true });
    await textDisplayData().init();
    expect(document.getElementById('print')!.getAttribute('dir')).toBe('rtl');

    setupDom();
    respond([item()], { rtlScript: false });
    await textDisplayData().init();
    expect(document.getElementById('print')!.hasAttribute('dir')).toBe(false);
  });

  it('reports an error instead of rendering when the text has no annotation', async () => {
    getAnnotation.mockResolvedValue({ data: { items: null, config: {} } });
    const app = textDisplayData();

    await app.init();

    expect(app.loading).toBe(false);
    expect(app.error).not.toBe('');
    expect(app.isReady()).toBe(false);
    expect(document.getElementById('print')!.innerHTML).toBe('');
  });

  it('surfaces the API error message', async () => {
    getAnnotation.mockResolvedValue({ error: 'Text not found' });
    const app = textDisplayData();

    await app.init();

    expect(app.error).toBe('Text not found');
  });

  it('does not call the API without a text id', async () => {
    setupDom(0);
    const app = textDisplayData();

    await app.init();

    expect(getAnnotation).not.toHaveBeenCalled();
    expect(app.error).not.toBe('');
  });

  it('is ready only once loading finished without error', async () => {
    respond([item()]);
    const app = textDisplayData();

    expect(app.isReady()).toBe(false);
    await app.init();
    expect(app.isReady()).toBe(true);
  });
});
