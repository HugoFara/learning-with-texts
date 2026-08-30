/**
 * Tests for text_check_run.ts - the shared check-a-text call.
 */
import { describe, it, expect, beforeEach, vi } from 'vitest';

vi.mock('../../../src/frontend/js/modules/text/api/texts_api', () => ({
  TextsApi: { check: vi.fn() }
}));

vi.mock('../../../src/frontend/js/modules/text/pages/text_check_display', () => ({
  renderCheckReport: vi.fn()
}));

import { TextsApi } from '../../../src/frontend/js/modules/text/api/texts_api';
import { renderCheckReport } from
  '../../../src/frontend/js/modules/text/pages/text_check_display';
import { checkTextForm } from '../../../src/frontend/js/modules/text/pages/text_check_run';

const checkMock = TextsApi.check as ReturnType<typeof vi.fn>;
const renderMock = renderCheckReport as ReturnType<typeof vi.fn>;

const REPORT = {
  preview: 'Bonjour.',
  sentences: ['Bonjour.'],
  words: [],
  nonWords: [],
  multiWords: [],
  rtlScript: false,
  warning: 'ok'
};

/** Build the form both check entry points share. */
function buildForm(text: string, langId: string): HTMLFormElement {
  document.body.innerHTML =
    '<form>'
    + `<select name="TxLgID"><option value="${langId}" selected>L</option></select>`
    + `<textarea name="TxText">${text}</textarea>`
    + '</form>'
    + '<div id="check_text"></div>';
  return document.querySelector('form') as HTMLFormElement;
}

describe('text_check_run.ts', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    Element.prototype.scrollIntoView = vi.fn();
  });

  it('sends the language and text to the API', async () => {
    checkMock.mockResolvedValue({ data: REPORT });

    const error = await checkTextForm(buildForm('Bonjour.', '3'), 'check_text');

    expect(checkMock).toHaveBeenCalledWith(3, 'Bonjour.');
    expect(error).toBe('');
  });

  it('renders the report into the named container', async () => {
    checkMock.mockResolvedValue({ data: REPORT });

    await checkTextForm(buildForm('Bonjour.', '3'), 'check_text');

    expect(renderMock).toHaveBeenCalledWith(REPORT, document.getElementById('check_text'));
  });

  it('does not call the API without a text', async () => {
    const error = await checkTextForm(buildForm('   ', '3'), 'check_text');

    expect(checkMock).not.toHaveBeenCalled();
    expect(error).not.toBe('');
  });

  it('does not call the API without a language', async () => {
    const error = await checkTextForm(buildForm('Bonjour.', '0'), 'check_text');

    expect(checkMock).not.toHaveBeenCalled();
    expect(error).not.toBe('');
  });

  it('reports the API error and renders nothing', async () => {
    checkMock.mockResolvedValue({ error: 'Language not found' });

    const error = await checkTextForm(buildForm('Bonjour.', '3'), 'check_text');

    expect(error).toBe('Language not found');
    expect(renderMock).not.toHaveBeenCalled();
  });

  it('reports a failure when the container is missing', async () => {
    checkMock.mockResolvedValue({ data: REPORT });
    const form = buildForm('Bonjour.', '3');
    document.getElementById('check_text')?.remove();

    const error = await checkTextForm(form, 'check_text');

    expect(error).not.toBe('');
    expect(renderMock).not.toHaveBeenCalled();
  });
});
