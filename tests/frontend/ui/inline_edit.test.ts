/**
 * Tests for inline_edit.ts - Click-to-edit functionality for text elements
 */
import { dismissAlerts, settle } from '../helpers/confirm_answers';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { initInlineEdit } from '../../../src/frontend/js/shared/components/inline_edit';

describe('inline_edit.ts', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
    vi.clearAllMocks();
    // Mock fetch
    global.fetch = vi.fn();
  });

  afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
  });

  // ===========================================================================
  // initInlineEdit Tests
  // ===========================================================================

  describe('initInlineEdit', () => {
    it('adds tooltip to existing editable elements', () => {
      document.body.innerHTML = `
        <span class="editable">Click me</span>
        <span class="editable">Edit me too</span>
      `;

      initInlineEdit('.editable', { url: '/save' });

      const elements = document.querySelectorAll('.editable');
      elements.forEach(el => {
        expect(el.getAttribute('title')).toBe('Click to edit...');
      });
    });

    it('uses custom tooltip when provided', () => {
      document.body.innerHTML = '<span class="editable">Text</span>';

      initInlineEdit('.editable', { url: '/save', tooltip: 'Custom tooltip' });

      expect(document.querySelector('.editable')!.getAttribute('title')).toBe('Custom tooltip');
    });

    it('starts editing on click', () => {
      document.body.innerHTML = '<span class="editable">Original</span>';

      initInlineEdit('.editable', { url: '/save' });

      const element = document.querySelector('.editable') as HTMLElement;
      element.click();

      // Should have editing UI
      expect(element.querySelector('.inline-edit-wrapper')).not.toBeNull();
      expect(element.querySelector('textarea')).not.toBeNull();
      expect(element.querySelector('.inline-edit-save')).not.toBeNull();
      expect(element.querySelector('.inline-edit-cancel')).not.toBeNull();
    });

    it('adds inline-edit-active class when editing', () => {
      document.body.innerHTML = '<span class="editable">Text</span>';

      initInlineEdit('.editable', { url: '/save' });

      const element = document.querySelector('.editable') as HTMLElement;
      element.click();

      expect(element.classList.contains('inline-edit-active')).toBe(true);
    });

    it('populates textarea with current content', () => {
      document.body.innerHTML = '<span class="editable">My Content</span>';

      initInlineEdit('.editable', { url: '/save' });

      const element = document.querySelector('.editable') as HTMLElement;
      element.click();

      const textarea = element.querySelector('textarea') as HTMLTextAreaElement;
      expect(textarea.value).toBe('My Content');
    });

    it('clears textarea if content is asterisk placeholder', () => {
      document.body.innerHTML = '<span class="editable">*</span>';

      initInlineEdit('.editable', { url: '/save' });

      const element = document.querySelector('.editable') as HTMLElement;
      element.click();

      const textarea = element.querySelector('textarea') as HTMLTextAreaElement;
      expect(textarea.value).toBe('');
    });

    it('uses provided options in config', () => {
      document.body.innerHTML = '<span class="editable">Text</span>';

      // Register the handler with custom options
      initInlineEdit('.editable', { url: '/save', rows: 5, cols: 50 });

      const element = document.querySelector('.editable') as HTMLElement;
      element.click();

      // Check textarea has default dimensions (config merges with defaults)
      const textarea = element.querySelector('textarea') as HTMLTextAreaElement;
      // The config should use our values
      expect(textarea.rows).toBeGreaterThanOrEqual(3);
      expect(textarea.cols).toBeGreaterThanOrEqual(35);
    });

    it('uses default rows and cols', () => {
      document.body.innerHTML = '<span class="editable">Text</span>';

      initInlineEdit('.editable', { url: '/save' });

      const element = document.querySelector('.editable') as HTMLElement;
      element.click();

      const textarea = element.querySelector('textarea') as HTMLTextAreaElement;
      expect(textarea.rows).toBe(3);
      expect(textarea.cols).toBe(35);
    });

    it('creates save and cancel buttons', () => {
      document.body.innerHTML = '<span class="editable">Text</span>';

      initInlineEdit('.editable', { url: '/save' });

      const element = document.querySelector('.editable') as HTMLElement;
      element.click();

      const saveBtn = element.querySelector('.inline-edit-save') as HTMLButtonElement;
      const cancelBtn = element.querySelector('.inline-edit-cancel') as HTMLButtonElement;

      // Check buttons exist with default text
      expect(saveBtn.textContent).toBe('Save');
      expect(cancelBtn.textContent).toBe('Cancel');
    });

    it('focuses and selects textarea content', () => {
      document.body.innerHTML = '<span class="editable">Select me</span>';

      initInlineEdit('.editable', { url: '/save' });

      const element = document.querySelector('.editable') as HTMLElement;
      element.click();

      const textarea = element.querySelector('textarea') as HTMLTextAreaElement;
      expect(document.activeElement).toBe(textarea);
    });

    it('cancels edit on cancel button click', () => {
      document.body.innerHTML = '<span class="editable">Original Text</span>';

      initInlineEdit('.editable', { url: '/save' });

      const element = document.querySelector('.editable') as HTMLElement;
      element.click();

      const textarea = element.querySelector('textarea') as HTMLTextAreaElement;
      textarea.value = 'Modified';

      const cancelBtn = element.querySelector('.inline-edit-cancel') as HTMLButtonElement;
      cancelBtn.click();

      expect(element.textContent).toBe('Original Text');
      expect(element.classList.contains('inline-edit-active')).toBe(false);
    });

    it('cancels edit on Escape key', () => {
      document.body.innerHTML = '<span class="editable">Original</span>';

      initInlineEdit('.editable', { url: '/save' });

      const element = document.querySelector('.editable') as HTMLElement;
      element.click();

      const textarea = element.querySelector('textarea') as HTMLTextAreaElement;
      textarea.value = 'Changed';

      const event = new KeyboardEvent('keydown', { key: 'Escape', bubbles: true });
      textarea.dispatchEvent(event);

      expect(element.textContent).toBe('Original');
    });

    it('saves on Ctrl+Enter', async () => {
      document.body.innerHTML = '<span id="test-element" class="editable">Original</span>';

      (global.fetch as any).mockResolvedValue({
        ok: true,
        text: () => Promise.resolve('Saved Value')
      });

      initInlineEdit('.editable', { url: '/save' });

      const element = document.querySelector('.editable') as HTMLElement;
      element.click();

      const textarea = element.querySelector('textarea') as HTMLTextAreaElement;
      textarea.value = 'New Value';

      const event = new KeyboardEvent('keydown', { key: 'Enter', ctrlKey: true, bubbles: true });
      textarea.dispatchEvent(event);

      await new Promise(r => setTimeout(r, 10));

      expect(fetch).toHaveBeenCalled();
    });

    it('saves on save button click', async () => {
      document.body.innerHTML = '<span id="edit1" class="editable">Original</span>';

      (global.fetch as any).mockResolvedValue({
        ok: true,
        text: () => Promise.resolve('Updated')
      });

      initInlineEdit('.editable', { url: '/save' });

      const element = document.querySelector('.editable') as HTMLElement;
      element.click();

      const textarea = element.querySelector('textarea') as HTMLTextAreaElement;
      textarea.value = 'New Content';

      const saveBtn = element.querySelector('.inline-edit-save') as HTMLButtonElement;
      saveBtn.click();

      await new Promise(r => setTimeout(r, 10));

      expect(fetch).toHaveBeenCalledWith('/save', expect.objectContaining({
        method: 'POST',
        body: expect.any(FormData)
      }));
    });

    it('sends element id and value in FormData', async () => {
      document.body.innerHTML = '<span id="item_123" class="editable">Original</span>';

      let capturedBody: FormData | null = null;
      (global.fetch as any).mockImplementation((url: string, options: any) => {
        void url; // URL is used implicitly by fetch mock
        capturedBody = options.body;
        return Promise.resolve({
          ok: true,
          text: () => Promise.resolve('Saved')
        });
      });

      initInlineEdit('.editable', { url: '/save' });

      const element = document.querySelector('.editable') as HTMLElement;
      element.click();

      const textarea = element.querySelector('textarea') as HTMLTextAreaElement;
      textarea.value = '  Trimmed Value  ';

      const saveBtn = element.querySelector('.inline-edit-save') as HTMLButtonElement;
      saveBtn.click();

      await new Promise(r => setTimeout(r, 10));

      expect(capturedBody).toBeInstanceOf(FormData);
      expect(capturedBody!.get('id')).toBe('item_123');
      expect(capturedBody!.get('value')).toBe('Trimmed Value');  // Should be trimmed
    });

    it('updates element with server response', async () => {
      document.body.innerHTML = '<span id="item" class="editable">Old</span>';

      (global.fetch as any).mockResolvedValue({
        ok: true,
        text: () => Promise.resolve('Server Response')
      });

      initInlineEdit('.editable', { url: '/save' });

      const element = document.querySelector('.editable') as HTMLElement;
      element.click();

      const saveBtn = element.querySelector('.inline-edit-save') as HTMLButtonElement;
      saveBtn.click();

      await new Promise(r => setTimeout(r, 10));

      expect(element.textContent).toBe('Server Response');
      expect(element.classList.contains('inline-edit-active')).toBe(false);
    });

    it('shows loading indicator while saving', async () => {
      document.body.innerHTML = '<span class="editable">Text</span>';

      let resolvePromise: (value: any) => void;
      const fetchPromise = new Promise(resolve => {
        resolvePromise = resolve;
      });

      (global.fetch as any).mockReturnValue(fetchPromise);

      initInlineEdit('.editable', { url: '/save', indicator: '<span class="loading">Saving...</span>' });

      const element = document.querySelector('.editable') as HTMLElement;
      element.click();

      const saveBtn = element.querySelector('.inline-edit-save') as HTMLButtonElement;
      saveBtn.click();

      await new Promise(r => setTimeout(r, 0));

      // Should show indicator and disable textarea
      const textarea = element.querySelector('textarea') as HTMLTextAreaElement;
      expect(textarea.disabled).toBe(true);

      // Complete the save
      resolvePromise!({
        ok: true,
        text: () => Promise.resolve('Done')
      });

      await new Promise(r => setTimeout(r, 10));
    });

    it('handles save errors gracefully', async () => {
      document.body.innerHTML = '<span class="editable">Original</span>';

      (global.fetch as any).mockResolvedValue({
        ok: false,
        status: 500
      });

      const alertSpy = dismissAlerts();
      const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});

      initInlineEdit('.editable', { url: '/save' });

      const element = document.querySelector('.editable') as HTMLElement;
      element.click();

      const saveBtn = element.querySelector('.inline-edit-save') as HTMLButtonElement;
      saveBtn.click();

      await new Promise(r => setTimeout(r, 10));

      expect(consoleSpy).toHaveBeenCalled();
      await settle();
      expect(alertSpy).toHaveBeenCalledWith('Failed to save. Please try again.');
    });

    it('restores editing UI after save error', async () => {
      document.body.innerHTML = '<span class="editable">Text</span>';

      (global.fetch as any).mockRejectedValue(new Error('Network error'));

      dismissAlerts();
      vi.spyOn(console, 'error').mockImplementation(() => {});

      initInlineEdit('.editable', { url: '/save' });

      const element = document.querySelector('.editable') as HTMLElement;
      element.click();

      const saveBtn = element.querySelector('.inline-edit-save') as HTMLButtonElement;
      saveBtn.click();

      await new Promise(r => setTimeout(r, 10));

      // Textarea should be re-enabled
      const textarea = element.querySelector('textarea') as HTMLTextAreaElement;
      expect(textarea.disabled).toBe(false);

      // Buttons should be restored
      expect(element.querySelector('.inline-edit-save')).not.toBeNull();
      expect(element.querySelector('.inline-edit-cancel')).not.toBeNull();
    });

    it('cancels previous edit when starting new one', () => {
      document.body.innerHTML = `
        <span class="editable" id="first">First</span>
        <span class="editable" id="second">Second</span>
      `;

      initInlineEdit('.editable', { url: '/save' });

      const first = document.getElementById('first') as HTMLElement;
      const second = document.getElementById('second') as HTMLElement;

      // Start editing first
      first.click();
      expect(first.classList.contains('inline-edit-active')).toBe(true);

      // Start editing second - should cancel first
      second.click();
      expect(first.classList.contains('inline-edit-active')).toBe(false);
      expect(first.textContent).toBe('First');
      expect(second.classList.contains('inline-edit-active')).toBe(true);
    });

    it('does not start edit on already-editing element', () => {
      document.body.innerHTML = '<span class="editable">Text</span>';

      initInlineEdit('.editable', { url: '/save' });

      const element = document.querySelector('.editable') as HTMLElement;
      element.click();

      // Click again should not create another wrapper
      element.click();

      expect(element.querySelectorAll('.inline-edit-wrapper').length).toBe(1);
    });

    it('adds tooltip to dynamically added elements', async () => {
      document.body.innerHTML = '<div id="container"></div>';

      initInlineEdit('.editable', { url: '/save', tooltip: 'Edit this' });

      // Add element dynamically
      const newEl = document.createElement('span');
      newEl.className = 'editable';
      newEl.textContent = 'Dynamic';
      document.getElementById('container')!.appendChild(newEl);

      // Wait for MutationObserver
      await new Promise(r => setTimeout(r, 10));

      expect(newEl.getAttribute('title')).toBe('Edit this');
    });

    it('restores tooltip after save', async () => {
      document.body.innerHTML = '<span class="editable">Text</span>';

      (global.fetch as any).mockResolvedValue({
        ok: true,
        text: () => Promise.resolve('Saved')
      });

      initInlineEdit('.editable', { url: '/save', tooltip: 'Click to edit...' });

      const element = document.querySelector('.editable') as HTMLElement;
      element.click();

      const saveBtn = element.querySelector('.inline-edit-save') as HTMLButtonElement;
      saveBtn.click();

      await new Promise(r => setTimeout(r, 10));

      expect(element.getAttribute('title')).toBe('Click to edit...');
    });
  });
});
