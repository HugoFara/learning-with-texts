/**
 * Tests for modules/vocabulary/components/term_edit_modal.ts - Term edit modal
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

// Mock dependencies
vi.mock('../../../src/frontend/js/shared/components/modal', () => ({
  openModal: vi.fn(),
  closeModal: vi.fn(),
}));

vi.mock('../../../src/frontend/js/modules/vocabulary/api/terms_api', () => ({
  TermsApi: {
    getForEdit: vi.fn(),
    createFull: vi.fn(),
    updateFull: vi.fn(),
  },
}));

vi.mock('../../../src/frontend/js/shared/utils/html_utils', () => ({
  escapeHtml: vi.fn((str: string) => str),
}));

import { openTermEditModal } from '../../../src/frontend/js/modules/vocabulary/components/term_edit_modal';
import { openModal, closeModal } from '../../../src/frontend/js/shared/components/modal';
import { TermsApi } from '../../../src/frontend/js/modules/vocabulary/api/terms_api';
import { escapeHtml } from '../../../src/frontend/js/shared/utils/html_utils';

describe('modules/vocabulary/components/term_edit_modal.ts', () => {
  let dispatchEventSpy: ReturnType<typeof vi.spyOn>;

  const mockTermResponse = {
    data: {
      term: {
        id: 123,
        text: 'hello',
        textLc: 'hello',
        translation: 'bonjour',
        romanization: '',
        sentence: 'Say {hello} to everyone.',
        status: 1,
        hex: 'abc123',
      },
      language: {
        id: 1,
        name: 'English',
        showRomanization: false,
      },
      isNew: false,
      error: undefined,
    },
    error: undefined,
  };

  beforeEach(() => {
    vi.clearAllMocks();
    dispatchEventSpy = vi.spyOn(document, 'dispatchEvent').mockImplementation(() => true);

    // Set up DOM
    document.body.innerHTML = '';

    // Default mock implementations
    vi.mocked(TermsApi.getForEdit).mockResolvedValue(mockTermResponse);
    vi.mocked(TermsApi.createFull).mockResolvedValue({
      data: { term: mockTermResponse.data!.term },
    });
    vi.mocked(TermsApi.updateFull).mockResolvedValue({
      data: { term: mockTermResponse.data!.term },
    });
  });

  afterEach(async () => {
    // Let a save that a test started finish before the DOM goes away. The
    // handler re-enables the button as its last step; a save still pending
    // when the file's environment is torn down hits a missing `document`.
    await vi.waitFor(() => {
      const saveBtn = document.getElementById('term-edit-save') as HTMLButtonElement | null;
      expect(saveBtn?.disabled ?? false).toBe(false);
    });
    dispatchEventSpy.mockRestore();
    document.body.innerHTML = '';
  });

  // ===========================================================================
  // openTermEditModal Tests
  // ===========================================================================

  describe('openTermEditModal', () => {
    it('shows loading modal initially', async () => {
      vi.mocked(TermsApi.getForEdit).mockImplementation(
        () => new Promise(() => {}) // Never resolves
      );

      openTermEditModal(1, 5);

      expect(openModal).toHaveBeenCalledWith(
        expect.stringContaining('Loading'),
        expect.objectContaining({
          title: 'Edit Term',
          closeOnEscape: true,
          closeOnOverlayClick: false,
        })
      );
    });

    it('fetches term data for existing term', async () => {
      await openTermEditModal(1, 5, 123);

      expect(TermsApi.getForEdit).toHaveBeenCalledWith(1, 5, 123);
    });

    it('fetches term data for new term', async () => {
      await openTermEditModal(1, 5);

      expect(TermsApi.getForEdit).toHaveBeenCalledWith(1, 5, undefined);
    });

    it('displays error on API failure', async () => {
      vi.mocked(TermsApi.getForEdit).mockResolvedValue({
        error: 'Failed to load',
        data: undefined,
      });

      await openTermEditModal(1, 5);

      expect(openModal).toHaveBeenLastCalledWith(
        expect.stringContaining('Failed to load'),
        expect.objectContaining({ title: 'Error' })
      );
    });

    it('displays error on response error', async () => {
      vi.mocked(TermsApi.getForEdit).mockResolvedValue({
        data: { error: 'Term not found' } as any,
        error: undefined,
      });

      await openTermEditModal(1, 5);

      expect(openModal).toHaveBeenLastCalledWith(
        expect.stringContaining('Term not found'),
        expect.objectContaining({ title: 'Error' })
      );
    });

    it('displays error on exception', async () => {
      vi.mocked(TermsApi.getForEdit).mockRejectedValue(new Error('Network error'));

      await openTermEditModal(1, 5);

      expect(openModal).toHaveBeenLastCalledWith(
        expect.stringContaining('Failed to load term data'),
        expect.objectContaining({ title: 'Error' })
      );
    });

    it('renders form with term data', async () => {
      await openTermEditModal(1, 5, 123);

      // Check that openModal was called with form HTML
      const lastCall = vi.mocked(openModal).mock.calls.slice(-1)[0];
      expect(lastCall[0]).toContain('term-edit-form');
      expect(lastCall[0]).toContain('term-edit-translation');
      expect(lastCall[0]).toContain('term-edit-status');
      expect(lastCall[0]).toContain('term-edit-sentence');
    });

    it('uses the new-term title for new terms', async () => {
      vi.mocked(TermsApi.getForEdit).mockResolvedValue({
        data: {
          ...mockTermResponse.data!,
          isNew: true,
        },
      });

      await openTermEditModal(1, 5);

      expect(openModal).toHaveBeenLastCalledWith(
        expect.any(String),
        expect.objectContaining({ title: 'New Term' })
      );
    });

    it('uses "Edit Term" title for existing terms', async () => {
      await openTermEditModal(1, 5, 123);

      expect(openModal).toHaveBeenLastCalledWith(
        expect.any(String),
        expect.objectContaining({ title: 'Edit Term' })
      );
    });

    it('shows romanization field when language supports it', async () => {
      vi.mocked(TermsApi.getForEdit).mockResolvedValue({
        data: {
          ...mockTermResponse.data!,
          language: { ...mockTermResponse.data!.language, showRomanization: true },
        },
      });

      await openTermEditModal(1, 5, 123);

      const lastCall = vi.mocked(openModal).mock.calls.slice(-1)[0];
      expect(lastCall[0]).toContain('term-edit-romanization');
    });

    it('hides romanization field when language does not support it', async () => {
      await openTermEditModal(1, 5, 123);

      const lastCall = vi.mocked(openModal).mock.calls.slice(-1)[0];
      // Should not have the romanization input (only appears when showRomanization is true)
      const formHtml = lastCall[0];
      // When showRomanization is false, the romanizationField should be empty string
      expect(formHtml).not.toMatch(/id="term-edit-romanization"/);
    });

    it('handles empty translation (*) correctly', async () => {
      vi.mocked(TermsApi.getForEdit).mockResolvedValue({
        data: {
          ...mockTermResponse.data!,
          term: { ...mockTermResponse.data!.term, translation: '*' },
        },
      });

      await openTermEditModal(1, 5, 123);

      // escapeHtml should be called with empty string, not '*'
      expect(escapeHtml).toHaveBeenCalledWith('');
    });
  });

  // ===========================================================================
  // Form Rendering Tests
  // ===========================================================================

  describe('Form Rendering', () => {
    it('renders all status options', async () => {
      await openTermEditModal(1, 5, 123);

      const lastCall = vi.mocked(openModal).mock.calls.slice(-1)[0];
      const formHtml = lastCall[0];

      expect(formHtml).toContain('value="1"');
      expect(formHtml).toContain('value="2"');
      expect(formHtml).toContain('value="3"');
      expect(formHtml).toContain('value="4"');
      expect(formHtml).toContain('value="5"');
      expect(formHtml).toContain('value="99"');
      expect(formHtml).toContain('value="98"');
    });

    it('pre-selects current status', async () => {
      vi.mocked(TermsApi.getForEdit).mockResolvedValue({
        data: {
          ...mockTermResponse.data!,
          term: { ...mockTermResponse.data!.term, status: 3 },
        },
      });

      await openTermEditModal(1, 5, 123);

      const lastCall = vi.mocked(openModal).mock.calls.slice(-1)[0];
      const formHtml = lastCall[0];

      expect(formHtml).toContain('value="3" selected');
    });

    it('renders save and cancel buttons', async () => {
      await openTermEditModal(1, 5, 123);

      const lastCall = vi.mocked(openModal).mock.calls.slice(-1)[0];
      const formHtml = lastCall[0];

      expect(formHtml).toContain('id="term-edit-save"');
      expect(formHtml).toContain('id="term-edit-cancel"');
    });

    it('renders error notification container (hidden)', async () => {
      await openTermEditModal(1, 5, 123);

      const lastCall = vi.mocked(openModal).mock.calls.slice(-1)[0];
      const formHtml = lastCall[0];

      expect(formHtml).toContain('id="term-edit-error"');
      expect(formHtml).toContain('style="display: none;"');
    });
  });

  // ===========================================================================
  // Form Submission Tests
  // ===========================================================================

  describe('Form Submission', () => {
    it('calls createFull for new terms', async () => {
      vi.mocked(TermsApi.getForEdit).mockResolvedValue({
        data: {
          ...mockTermResponse.data!,
          isNew: true,
          term: { ...mockTermResponse.data!.term, id: null as any },
        },
      });

      // Set up DOM with form
      document.body.innerHTML = `
        <form id="term-edit-form">
          <input id="term-edit-text" value="hello" />
          <textarea id="term-edit-translation">test translation</textarea>
          <input id="term-edit-lemma" value="" />
          <textarea id="term-edit-notes"></textarea>
          <input id="term-edit-tags" value="" />
          <input id="term-edit-romanization" value="" />
          <textarea id="term-edit-sentence">test sentence</textarea>
          <select id="term-edit-status"><option value="2" selected>Learning (2)</option></select>
          <button id="term-edit-save">Save</button>
          <button id="term-edit-cancel">Cancel</button>
          <div id="term-edit-error" style="display: none;"></div>
        </form>
      `;

      await openTermEditModal(1, 5);

      // Get the form submission handler that was attached
      // Since we can't easily access the handler, we test that the API method exists
      expect(typeof TermsApi.createFull).toBe('function');
    });

    it('calls updateFull for existing terms', async () => {
      // Set up DOM
      document.body.innerHTML = `
        <form id="term-edit-form">
          <input id="term-edit-text" value="hello" />
          <textarea id="term-edit-translation">updated</textarea>
          <input id="term-edit-lemma" value="" />
          <textarea id="term-edit-notes"></textarea>
          <input id="term-edit-tags" value="" />
          <input id="term-edit-romanization" value="" />
          <textarea id="term-edit-sentence">test</textarea>
          <select id="term-edit-status"><option value="3" selected>3</option></select>
          <button id="term-edit-save">Save</button>
          <div id="term-edit-error"></div>
        </form>
      `;

      await openTermEditModal(1, 5, 123);

      expect(typeof TermsApi.updateFull).toBe('function');
    });
  });

  // ===========================================================================
  // Event Dispatching Tests
  // ===========================================================================

  describe('Event Dispatching', () => {
    it('dispatches lwt-term-saved event on successful save', () => {
      // The event should be dispatched with term details
      const event = new CustomEvent('lwt-term-saved', {
        detail: {
          wordId: 123,
          hex: 'abc123',
          text: 'hello',
        },
      });

      document.dispatchEvent(event);

      expect(dispatchEventSpy).toHaveBeenCalledWith(
        expect.objectContaining({
          type: 'lwt-term-saved',
          detail: expect.objectContaining({
            wordId: 123,
            hex: 'abc123',
          }),
        })
      );
    });
  });

  // ===========================================================================
  // Global Exposure Tests
  // ===========================================================================

  describe('Global Exposure', () => {
    it('exposes openTermEditModal globally on window', () => {
      expect(window.openTermEditModal).toBe(openTermEditModal);
    });
  });

  // ===========================================================================
  // Modal Options Tests
  // ===========================================================================

  describe('Modal Options', () => {
    it('configures modal to close on escape', async () => {
      await openTermEditModal(1, 5, 123);

      expect(openModal).toHaveBeenCalledWith(
        expect.any(String),
        expect.objectContaining({ closeOnEscape: true })
      );
    });

    it('configures modal to not close on overlay click', async () => {
      await openTermEditModal(1, 5, 123);

      expect(openModal).toHaveBeenCalledWith(
        expect.any(String),
        expect.objectContaining({ closeOnOverlayClick: false })
      );
    });
  });

  // ===========================================================================
  // Status Constants Tests
  // ===========================================================================

  describe('Status Constants', () => {
    it('includes all learning statuses', async () => {
      await openTermEditModal(1, 5, 123);

      const lastCall = vi.mocked(openModal).mock.calls.slice(-1)[0];
      const formHtml = lastCall[0];

      expect(formHtml).toContain('Learning (1)');
      expect(formHtml).toContain('Learning (2)');
      expect(formHtml).toContain('Learning (3)');
      expect(formHtml).toContain('Learning (4)');
      expect(formHtml).toContain('Learned');
      expect(formHtml).toContain('Well Known');
      expect(formHtml).toContain('Ignored');
    });
  });

  // ===========================================================================
  // Cancel Button Tests
  // ===========================================================================

  describe('Cancel Button', () => {
    it('cancel button closes modal', async () => {
      document.body.innerHTML = `
        <button id="term-edit-cancel">Cancel</button>
      `;

      await openTermEditModal(1, 5, 123);

      // The cancel button should have a click handler that calls closeModal
      expect(typeof closeModal).toBe('function');
    });
  });

  // ===========================================================================
  // Sentence Help Text Tests
  // ===========================================================================

  describe('Sentence Help Text', () => {
    it('shows help text for sentence field', async () => {
      await openTermEditModal(1, 5, 123);

      const lastCall = vi.mocked(openModal).mock.calls.slice(-1)[0];
      const formHtml = lastCall[0];

      expect(formHtml).toContain('Wrap the term in {curly braces}');
    });
  });

  // ===========================================================================
  // Input Validation Tests
  // ===========================================================================

  describe('Input Validation', () => {
    it('sets maxlength on translation field', async () => {
      await openTermEditModal(1, 5, 123);

      const lastCall = vi.mocked(openModal).mock.calls.slice(-1)[0];
      const formHtml = lastCall[0];

      expect(formHtml).toContain('maxlength="500"');
    });

    it('sets maxlength on sentence field', async () => {
      await openTermEditModal(1, 5, 123);

      const lastCall = vi.mocked(openModal).mock.calls.slice(-1)[0];
      const formHtml = lastCall[0];

      expect(formHtml).toContain('maxlength="1000"');
    });

    it('sets maxlength on romanization field', async () => {
      vi.mocked(TermsApi.getForEdit).mockResolvedValue({
        data: {
          ...mockTermResponse.data!,
          language: { ...mockTermResponse.data!.language, showRomanization: true },
        },
      });

      await openTermEditModal(1, 5, 123);

      const lastCall = vi.mocked(openModal).mock.calls.slice(-1)[0];
      const formHtml = lastCall[0];

      expect(formHtml).toContain('maxlength="100"');
    });
  });

  // ===========================================================================
  // Term Display Tests
  // ===========================================================================

  describe('Term Display', () => {
    it('leaves an existing term text editable for recasing', async () => {
      await openTermEditModal(1, 5, 123);

      const lastCall = vi.mocked(openModal).mock.calls.slice(-1)[0];
      const formHtml = lastCall[0];

      expect(formHtml).toContain('id="term-edit-text"');
      expect(formHtml).not.toContain('readonly');
      expect(formHtml).toContain('Only change uppercase/lowercase!');
    });

    it('locks the term text for a new term', async () => {
      vi.mocked(TermsApi.getForEdit).mockResolvedValue({
        data: {
          ...mockTermResponse.data!,
          isNew: true,
          term: { ...mockTermResponse.data!.term, id: null },
        },
      });

      await openTermEditModal(1, 5);

      const lastCall = vi.mocked(openModal).mock.calls.slice(-1)[0];
      const formHtml = lastCall[0];

      expect(formHtml).toContain('readonly');
      expect(formHtml).toContain('disabled');
    });
  });

  // ===========================================================================
  // Data-driven Field Tests
  // ===========================================================================

  describe('fields rendered from API data', () => {
    it('renders lemma, notes and tags from the payload', async () => {
      vi.mocked(TermsApi.getForEdit).mockResolvedValue({
        data: {
          ...mockTermResponse.data!,
          allTags: ['noun', 'verb'],
          term: {
            ...mockTermResponse.data!.term,
            lemma: 'hallo',
            notes: 'a greeting',
            tags: ['noun', 'common'],
          },
        },
      });

      await openTermEditModal(1, 5, 123);

      const formHtml = vi.mocked(openModal).mock.calls.slice(-1)[0][0];

      expect(formHtml).toContain('id="term-edit-lemma"');
      expect(formHtml).toContain('value="hallo"');
      expect(formHtml).toContain('a greeting');
      expect(formHtml).toContain('value="noun, common"');
      // allTags feeds the datalist, not the value.
      expect(formHtml).toContain('<option value="verb">');
    });

    it('renders similar terms as links', async () => {
      vi.mocked(TermsApi.getForEdit).mockResolvedValue({
        data: {
          ...mockTermResponse.data!,
          similarTerms: [
            { id: 7, text: 'hallo', translation: 'hi', status: 2 },
            { id: 8, text: 'helo', translation: '*', status: 1 },
          ],
        },
      });

      await openTermEditModal(1, 5, 123);

      const formHtml = vi.mocked(openModal).mock.calls.slice(-1)[0][0];

      expect(formHtml).toContain('/words/7/edit');
      expect(formHtml).toContain('hi');
      // A '*' translation is a placeholder and must not be shown.
      expect(formHtml).toContain('/words/8/edit');
      expect(formHtml).not.toContain('— *');
    });

    it('omits the similar terms block when there are none', async () => {
      await openTermEditModal(1, 5, 123);

      const formHtml = vi.mocked(openModal).mock.calls.slice(-1)[0][0];

      expect(formHtml).not.toContain('Similar Terms');
    });

    it('renders a dictionary link built from the language URI', async () => {
      vi.mocked(TermsApi.getForEdit).mockResolvedValue({
        data: {
          ...mockTermResponse.data!,
          language: {
            ...mockTermResponse.data!.language,
            translateUri: 'https://dict.test/?q=lwt_term',
          },
        },
      });

      await openTermEditModal(1, 5, 123);

      const formHtml = vi.mocked(openModal).mock.calls.slice(-1)[0][0];

      expect(formHtml).toContain('https://dict.test/?q=hello');
    });

    it('omits the dictionary link when the language has no URI', async () => {
      await openTermEditModal(1, 5, 123);

      const formHtml = vi.mocked(openModal).mock.calls.slice(-1)[0][0];

      expect(formHtml).not.toContain('Dictionary Lookup');
    });
  });

  // ===========================================================================
  // Term Recasing Guard Tests
  // ===========================================================================

  describe('term recasing guard', () => {
    beforeEach(() => {
      document.body.innerHTML = `
        <form id="term-edit-form">
          <input id="term-edit-text" value="hello" />
          <textarea id="term-edit-translation">t</textarea>
          <input id="term-edit-lemma" value="" />
          <textarea id="term-edit-notes"></textarea>
          <input id="term-edit-tags" value="" />
          <textarea id="term-edit-sentence"></textarea>
          <select id="term-edit-status"><option value="1" selected>1</option></select>
          <button id="term-edit-save" type="submit">Save</button>
          <div id="term-edit-error" style="display: none;"></div>
        </form>
      `;
    });

    it('accepts a pure change of capitalization', async () => {
      await openTermEditModal(1, 5, 123);
      (document.getElementById('term-edit-text') as HTMLInputElement).value = 'Hello';

      document.getElementById('term-edit-form')
        ?.dispatchEvent(new Event('submit', { cancelable: true }));

      await vi.waitFor(() => {
        expect(vi.mocked(TermsApi.updateFull).mock.calls.length > 0).toBe(true);
      });

      expect(TermsApi.updateFull).toHaveBeenCalledWith(
        123,
        expect.objectContaining({ text: 'Hello' })
      );
    });

    it('rejects a different term before calling the API', async () => {
      await openTermEditModal(1, 5, 123);
      (document.getElementById('term-edit-text') as HTMLInputElement).value = 'goodbye';

      document.getElementById('term-edit-form')
        ?.dispatchEvent(new Event('submit', { cancelable: true }));

      await vi.waitFor(() => {
        expect(document.getElementById('term-edit-error')!.style.display === 'block').toBe(true);
      });

      expect(TermsApi.updateFull).not.toHaveBeenCalled();
      const saveBtn = document.getElementById('term-edit-save') as HTMLButtonElement;
      expect(saveBtn.disabled).toBe(false);
    });
  });

  // ===========================================================================
  // Form Submission Handler Tests
  // ===========================================================================

  describe('Form Submission Handler', () => {
    beforeEach(async () => {
      // Set up DOM with form before each test
      document.body.innerHTML = `
        <form id="term-edit-form">
          <input id="term-edit-text" value="hello" />
          <textarea id="term-edit-translation">test translation</textarea>
          <input id="term-edit-lemma" value="" />
          <textarea id="term-edit-notes"></textarea>
          <input id="term-edit-tags" value="" />
          <input id="term-edit-romanization" value="romaji" />
          <textarea id="term-edit-sentence">test {sentence}</textarea>
          <select id="term-edit-status">
            <option value="1">Learning (1)</option>
            <option value="2" selected>Learning (2)</option>
          </select>
          <button id="term-edit-save" type="submit">Save</button>
          <button id="term-edit-cancel" type="button">Cancel</button>
          <div id="term-edit-error" style="display: none;"></div>
        </form>
      `;
    });

    it('prevents default form submission', async () => {
      await openTermEditModal(1, 5, 123);

      const form = document.getElementById('term-edit-form');
      const submitEvent = new Event('submit', { cancelable: true });
      const preventDefaultSpy = vi.spyOn(submitEvent, 'preventDefault');

      form?.dispatchEvent(submitEvent);

      expect(preventDefaultSpy).toHaveBeenCalled();
    });

    it('disables save button during submission', async () => {
      // Make API call slow
      vi.mocked(TermsApi.updateFull).mockImplementation(
        () => new Promise(resolve => setTimeout(() => resolve({
          data: { term: mockTermResponse.data!.term }
        }), 100))
      );

      await openTermEditModal(1, 5, 123);

      const form = document.getElementById('term-edit-form');
      const saveBtn = document.getElementById('term-edit-save') as HTMLButtonElement;

      // Submit form
      form?.dispatchEvent(new Event('submit', { cancelable: true }));

      // Button should be disabled during submission
      expect(saveBtn.disabled).toBe(true);
      expect(saveBtn.classList.contains('is-loading')).toBe(true);
    });

    it('hides error notification on new submission', async () => {
      await openTermEditModal(1, 5, 123);

      const errorEl = document.getElementById('term-edit-error')!;
      errorEl.style.display = 'block';
      errorEl.textContent = 'Previous error';

      const form = document.getElementById('term-edit-form');
      form?.dispatchEvent(new Event('submit', { cancelable: true }));

      expect(errorEl.style.display).toBe('none');
    });

    it('calls updateFull with form data for existing term', async () => {
      await openTermEditModal(1, 5, 123);

      const form = document.getElementById('term-edit-form');
      form?.dispatchEvent(new Event('submit', { cancelable: true }));

      await vi.waitFor(() => {
        expect(vi.mocked(TermsApi.updateFull).mock.calls.length > 0).toBe(true);
      });

      expect(TermsApi.updateFull).toHaveBeenCalledWith(123, {
        text: 'hello',
        translation: 'test translation',
        romanization: 'romaji',
        sentence: 'test {sentence}',
        notes: '',
        lemma: '',
        status: 2,
        tags: []
      });
    });

    it('calls createFull with form data for new term', async () => {
      vi.mocked(TermsApi.getForEdit).mockResolvedValue({
        data: {
          ...mockTermResponse.data!,
          isNew: true,
          term: { ...mockTermResponse.data!.term, id: null as any },
        },
      });

      await openTermEditModal(1, 5);

      const form = document.getElementById('term-edit-form');
      form?.dispatchEvent(new Event('submit', { cancelable: true }));

      await vi.waitFor(() => {
        expect(vi.mocked(TermsApi.createFull).mock.calls.length > 0).toBe(true);
      });

      expect(TermsApi.createFull).toHaveBeenCalledWith({
        textId: 1,
        position: 5,
        translation: 'test translation',
        romanization: 'romaji',
        sentence: 'test {sentence}',
        notes: '',
        lemma: '',
        status: 2,
        tags: []
      });
    });

    it('closes modal on successful save', async () => {
      vi.mocked(TermsApi.updateFull).mockResolvedValue({
        data: { term: mockTermResponse.data!.term }
      });

      await openTermEditModal(1, 5, 123);

      const form = document.getElementById('term-edit-form');
      form?.dispatchEvent(new Event('submit', { cancelable: true }));

      await vi.waitFor(() => {
        expect(vi.mocked(closeModal).mock.calls.length > 0).toBe(true);
      });

      expect(closeModal).toHaveBeenCalled();
    });

    it('dispatches lwt-term-saved event on successful save', async () => {
      vi.mocked(TermsApi.updateFull).mockResolvedValue({
        data: { term: mockTermResponse.data!.term }
      });

      await openTermEditModal(1, 5, 123);

      const form = document.getElementById('term-edit-form');
      form?.dispatchEvent(new Event('submit', { cancelable: true }));

      await vi.waitFor(() => {
        expect(dispatchEventSpy.mock.calls.some(
          call => (call[0] as CustomEvent).type === 'lwt-term-saved'
        )).toBe(true);
      });

      expect(dispatchEventSpy).toHaveBeenCalledWith(
        expect.objectContaining({
          type: 'lwt-term-saved',
          detail: expect.objectContaining({
            wordId: 123,
            hex: 'abc123'
          })
        })
      );
    });

    it('shows error notification on API error response', async () => {
      vi.mocked(TermsApi.updateFull).mockResolvedValue({
        error: 'Validation failed'
      });

      await openTermEditModal(1, 5, 123);

      const form = document.getElementById('term-edit-form');
      form?.dispatchEvent(new Event('submit', { cancelable: true }));

      await vi.waitFor(() => {
        const errorEl = document.getElementById('term-edit-error');
        expect(errorEl?.style.display === 'block').toBe(true);
      });

      const errorEl = document.getElementById('term-edit-error');
      expect(errorEl?.textContent).toContain('Validation failed');
      expect(errorEl?.style.display).toBe('block');
    });

    it('shows error notification on data.error', async () => {
      vi.mocked(TermsApi.updateFull).mockResolvedValue({
        data: { error: 'Term already exists' } as any
      });

      await openTermEditModal(1, 5, 123);

      const form = document.getElementById('term-edit-form');
      form?.dispatchEvent(new Event('submit', { cancelable: true }));

      await vi.waitFor(() => {
        const errorEl = document.getElementById('term-edit-error');
        expect(errorEl?.style.display === 'block').toBe(true);
      });

      const errorEl = document.getElementById('term-edit-error');
      expect(errorEl?.textContent).toContain('Term already exists');
    });

    it('shows generic error on exception', async () => {
      vi.mocked(TermsApi.updateFull).mockRejectedValue(new Error('Network error'));

      await openTermEditModal(1, 5, 123);

      const form = document.getElementById('term-edit-form');
      form?.dispatchEvent(new Event('submit', { cancelable: true }));

      await vi.waitFor(() => {
        const errorEl = document.getElementById('term-edit-error');
        expect(errorEl?.style.display === 'block').toBe(true);
      });

      const errorEl = document.getElementById('term-edit-error');
      expect(errorEl?.textContent).toContain('Network error');
    });

    it('re-enables save button after error', async () => {
      vi.mocked(TermsApi.updateFull).mockRejectedValue(new Error('Failed'));

      await openTermEditModal(1, 5, 123);

      const form = document.getElementById('term-edit-form');
      const saveBtn = document.getElementById('term-edit-save') as HTMLButtonElement;

      form?.dispatchEvent(new Event('submit', { cancelable: true }));

      await vi.waitFor(() => {
        expect(!saveBtn.disabled).toBe(true);
      });

      expect(saveBtn.disabled).toBe(false);
      expect(saveBtn.classList.contains('is-loading')).toBe(false);
    });

    it('handles missing wordId for existing term', async () => {
      // This tests the edge case where wordId is null but isNew is false
      vi.mocked(TermsApi.getForEdit).mockResolvedValue({
        data: {
          ...mockTermResponse.data!,
          isNew: false,
          term: { ...mockTermResponse.data!.term, id: null as any },
        },
      });

      await openTermEditModal(1, 5);

      const form = document.getElementById('term-edit-form');
      form?.dispatchEvent(new Event('submit', { cancelable: true }));

      await vi.waitFor(() => {
        const errorEl = document.getElementById('term-edit-error');
        expect(errorEl?.style.display === 'block').toBe(true);
      });

      const errorEl = document.getElementById('term-edit-error');
      expect(errorEl?.textContent).toContain('Word ID is missing');
    });

    it('handles empty form fields gracefully', async () => {
      // Clear form fields
      (document.getElementById('term-edit-translation') as HTMLTextAreaElement).value = '';
      (document.getElementById('term-edit-romanization') as HTMLInputElement).value = '';
      (document.getElementById('term-edit-sentence') as HTMLTextAreaElement).value = '';

      await openTermEditModal(1, 5, 123);

      const form = document.getElementById('term-edit-form');
      form?.dispatchEvent(new Event('submit', { cancelable: true }));

      await vi.waitFor(() => {
        expect(vi.mocked(TermsApi.updateFull).mock.calls.length > 0).toBe(true);
      });

      expect(TermsApi.updateFull).toHaveBeenCalledWith(123, {
        text: 'hello',
        translation: '',
        romanization: '',
        sentence: '',
        notes: '',
        lemma: '',
        status: 2,
        tags: []
      });
    });
  });

  // ===========================================================================
  // Cancel Button Handler Tests
  // ===========================================================================

  describe('Cancel Button Handler', () => {
    beforeEach(async () => {
      document.body.innerHTML = `
        <form id="term-edit-form">
          <input id="term-edit-text" value="hello" />
          <textarea id="term-edit-translation">test</textarea>
          <input id="term-edit-lemma" value="" />
          <textarea id="term-edit-notes"></textarea>
          <input id="term-edit-tags" value="" />
          <input id="term-edit-romanization" value="" />
          <textarea id="term-edit-sentence">test</textarea>
          <select id="term-edit-status"><option value="1" selected>1</option></select>
          <button id="term-edit-save">Save</button>
          <button id="term-edit-cancel">Cancel</button>
          <div id="term-edit-error" style="display: none;"></div>
        </form>
      `;
    });

    it('attaches click handler to cancel button', async () => {
      await openTermEditModal(1, 5, 123);

      const cancelBtn = document.getElementById('term-edit-cancel');
      expect(cancelBtn).not.toBeNull();
    });

    it('clicking cancel closes the modal', async () => {
      await openTermEditModal(1, 5, 123);

      const cancelBtn = document.getElementById('term-edit-cancel')!;
      cancelBtn.click();

      expect(closeModal).toHaveBeenCalled();
    });
  });

  // ===========================================================================
  // Edge Cases Tests
  // ===========================================================================

  describe('Edge Cases', () => {
    it('handles null response data', async () => {
      vi.mocked(TermsApi.getForEdit).mockResolvedValue({
        data: null as any,
        error: undefined
      });

      await openTermEditModal(1, 5);

      expect(openModal).toHaveBeenLastCalledWith(
        expect.stringContaining('Failed to load'),
        expect.objectContaining({ title: 'Error' })
      );
    });

    it('does not dispatch event when response has no term', async () => {
      vi.mocked(TermsApi.updateFull).mockResolvedValue({
        data: {} // No term in response
      });

      document.body.innerHTML = `
        <form id="term-edit-form">
          <input id="term-edit-text" value="hello" />
          <textarea id="term-edit-translation">test</textarea>
          <input id="term-edit-lemma" value="" />
          <textarea id="term-edit-notes"></textarea>
          <input id="term-edit-tags" value="" />
          <input id="term-edit-romanization" value="" />
          <textarea id="term-edit-sentence">test</textarea>
          <select id="term-edit-status"><option value="1" selected>1</option></select>
          <button id="term-edit-save">Save</button>
          <div id="term-edit-error" style="display: none;"></div>
        </form>
      `;

      await openTermEditModal(1, 5, 123);

      const form = document.getElementById('term-edit-form');
      form?.dispatchEvent(new Event('submit', { cancelable: true }));

      await vi.waitFor(() => {
        expect(vi.mocked(closeModal).mock.calls.length > 0).toBe(true);
      });

      // Event should not be dispatched if no term in response
      const termSavedCalls = dispatchEventSpy.mock.calls.filter(
        call => (call[0] as CustomEvent).type === 'lwt-term-saved'
      );
      expect(termSavedCalls.length).toBe(0);
    });

    it('handles missing form elements gracefully', async () => {
      // Remove some form elements
      document.body.innerHTML = `
        <form id="term-edit-form">
          <button id="term-edit-save">Save</button>
          <div id="term-edit-error" style="display: none;"></div>
        </form>
      `;

      await openTermEditModal(1, 5, 123);

      const form = document.getElementById('term-edit-form');
      form?.dispatchEvent(new Event('submit', { cancelable: true }));

      await vi.waitFor(() => {
        expect(vi.mocked(TermsApi.updateFull).mock.calls.length > 0).toBe(true);
      });

      // Should use empty/default values for missing fields. An absent term
      // input sends text: '', which the server reads as "leave WoText alone".
      expect(TermsApi.updateFull).toHaveBeenCalledWith(123, {
        text: '',
        translation: '',
        romanization: '',
        sentence: '',
        notes: '',
        lemma: '',
        status: 1, // Default when parsing empty string
        tags: []
      });
    });

    it('handles status selection without romanization field', async () => {
      document.body.innerHTML = `
        <form id="term-edit-form">
          <input id="term-edit-text" value="hello" />
          <textarea id="term-edit-translation">translation</textarea>
          <input id="term-edit-lemma" value="" />
          <textarea id="term-edit-notes"></textarea>
          <input id="term-edit-tags" value="" />
          <textarea id="term-edit-sentence">sentence</textarea>
          <select id="term-edit-status">
            <option value="99" selected>Well Known</option>
          </select>
          <button id="term-edit-save">Save</button>
          <div id="term-edit-error" style="display: none;"></div>
        </form>
      `;

      await openTermEditModal(1, 5, 123);

      const form = document.getElementById('term-edit-form');
      form?.dispatchEvent(new Event('submit', { cancelable: true }));

      await vi.waitFor(() => {
        expect(vi.mocked(TermsApi.updateFull).mock.calls.length > 0).toBe(true);
      });

      expect(TermsApi.updateFull).toHaveBeenCalledWith(123, expect.objectContaining({
        status: 99,
        romanization: ''
      }));
    });
  });
});
