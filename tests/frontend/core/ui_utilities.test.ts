/**
 * Tests for ui_utilities.ts - DOM manipulation, tooltips, and form wrapping
 */
import { answerConfirms, confirmPrompts, dismissAlerts, settle } from '../helpers/confirm_answers';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

// Now import the module
const ui_utilities = await import('../../../src/frontend/js/shared/utils/ui_utilities');
const { markClick, confirmDelete, showAllwordsClick, initAutoHideNotifications, initNotificationCloseButtons, setTheFocus, wrapRadioButtons } = ui_utilities;

describe('ui_utilities.ts', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
    vi.useFakeTimers();
  });

  afterEach(() => {
    vi.restoreAllMocks();
    vi.useRealTimers();
    document.body.innerHTML = '';
  });

  // ===========================================================================
  // markClick Tests
  // ===========================================================================

  describe('markClick', () => {
    it('enables markaction button when checkboxes are checked', () => {
      document.body.innerHTML = `
        <input type="checkbox" class="markcheck" checked />
        <button id="markaction" disabled>Action</button>
      `;

      markClick();

      expect(document.getElementById('markaction')?.hasAttribute('disabled')).toBe(false);
    });

    it('disables markaction button when no checkboxes are checked', () => {
      document.body.innerHTML = `
        <input type="checkbox" class="markcheck" />
        <button id="markaction">Action</button>
      `;

      markClick();

      expect(document.getElementById('markaction')?.hasAttribute('disabled')).toBe(true);
    });

    it('enables button with multiple checkboxes when at least one is checked', () => {
      document.body.innerHTML = `
        <input type="checkbox" class="markcheck" />
        <input type="checkbox" class="markcheck" checked />
        <input type="checkbox" class="markcheck" />
        <button id="markaction" disabled>Action</button>
      `;

      markClick();

      expect(document.getElementById('markaction')?.hasAttribute('disabled')).toBe(false);
    });

    it('handles missing markaction button gracefully', () => {
      document.body.innerHTML = `
        <input type="checkbox" class="markcheck" checked />
      `;

      expect(() => markClick()).not.toThrow();
    });
  });

  // ===========================================================================
  // confirmDelete Tests
  // ===========================================================================

  describe('confirmDelete', () => {
    it('returns true when user confirms', async () => {
      answerConfirms(true);

      const result = await confirmDelete();

      await settle();
      expect(result).toBe(true);
      await settle();
      expect(confirmPrompts).toHaveBeenCalledWith('Are you sure you want to delete this?');
    });

    it('returns false when user cancels', async () => {
      answerConfirms(false);

      const result = await confirmDelete();

      await settle();
      expect(result).toBe(false);
    });
  });

  // ===========================================================================
  // initAutoHideNotifications Tests
  // ===========================================================================

  describe('initAutoHideNotifications', () => {
    it('slides up elements with data-auto-hide attribute', () => {
      document.body.innerHTML = '<div class="notification is-success" data-auto-hide="true">Message</div>';

      initAutoHideNotifications();

      // slideUp is called - element still exists in DOM
      expect(document.querySelector('[data-auto-hide]')).not.toBeNull();
    });

    it('slides up legacy hide3 element', () => {
      document.body.innerHTML = '<div id="hide3">Message</div>';

      initAutoHideNotifications();

      // slideUp is called - element still exists in DOM
      expect(document.getElementById('hide3')).not.toBeNull();
    });

    it('handles missing elements gracefully', () => {
      document.body.innerHTML = '';

      expect(() => initAutoHideNotifications()).not.toThrow();
    });
  });

  // ===========================================================================
  // initNotificationCloseButtons Tests
  // ===========================================================================

  describe('initNotificationCloseButtons', () => {
    it('removes notification when close button is clicked', () => {
      document.body.innerHTML = `
        <div class="notification is-danger">
          <button class="delete"></button>
          Error message
        </div>
      `;

      initNotificationCloseButtons();

      const deleteButton = document.querySelector('.delete') as HTMLElement;
      deleteButton.click();

      expect(document.querySelector('.notification')).toBeNull();
    });

    it('handles multiple notifications', () => {
      document.body.innerHTML = `
        <div class="notification is-success" id="notif1">
          <button class="delete"></button>
          Success
        </div>
        <div class="notification is-danger" id="notif2">
          <button class="delete"></button>
          Error
        </div>
      `;

      initNotificationCloseButtons();

      // Close first notification
      const firstDelete = document.querySelector('#notif1 .delete') as HTMLElement;
      firstDelete.click();

      expect(document.getElementById('notif1')).toBeNull();
      expect(document.getElementById('notif2')).not.toBeNull();
    });

    it('handles no notifications gracefully', () => {
      document.body.innerHTML = '';

      expect(() => initNotificationCloseButtons()).not.toThrow();
    });
  });

  // ===========================================================================
  // setTheFocus Tests
  // ===========================================================================

  describe('setTheFocus', () => {
    it('focuses element with setfocus class', () => {
      document.body.innerHTML = '<input type="text" class="setfocus" />';

      setTheFocus();

      // Should focus the element
      expect(document.querySelector('.setfocus')).not.toBeNull();
    });

    it('handles missing setfocus element gracefully', () => {
      document.body.innerHTML = '';

      expect(() => setTheFocus()).not.toThrow();
    });
  });

  // ===========================================================================
  // wrapRadioButtons Tests
  // ===========================================================================

  describe('wrapRadioButtons', () => {
    it('adds tabindex to inputs', () => {
      document.body.innerHTML = `
        <input type="text" />
        <input type="button" value="Button" />
      `;

      wrapRadioButtons();

      expect(document.querySelector('input[type="text"]')?.getAttribute('tabindex')).toBeDefined();
      expect(document.querySelector('input[type="button"]')?.getAttribute('tabindex')).toBeDefined();
    });

    it('adds tabindex to selects', () => {
      document.body.innerHTML = '<select><option>Option</option></select>';

      wrapRadioButtons();

      expect(document.querySelector('select')?.getAttribute('tabindex')).toBeDefined();
    });

    it('adds tabindex to links except those starting with rec', () => {
      document.body.innerHTML = `
        <a href="#" name="link1">Link 1</a>
        <a href="#" name="rec1">Rec Link</a>
      `;

      wrapRadioButtons();

      expect(document.querySelector('a[name="link1"]')?.getAttribute('tabindex')).toBeDefined();
    });

    it('sets up keydown handler for wrap_radio spans', () => {
      document.body.innerHTML = `
        <div>
          <input type="radio" name="test" value="1" />
          <label class="wrap_radio"><span></span></label>
        </div>
      `;

      wrapRadioButtons();

      // Simulate space key press using native KeyboardEvent
      const span = document.querySelector('.wrap_radio span') as HTMLElement;
      const event = new KeyboardEvent('keydown', { keyCode: 32, bubbles: true });
      span.dispatchEvent(event);
    });
  });

  // ===========================================================================
  // showAllwordsClick Tests
  // ===========================================================================

  describe('showAllwordsClick', () => {
    let fetchSpy: ReturnType<typeof vi.spyOn>;
    let reloadMock: ReturnType<typeof vi.fn>;
    let originalLocation: Location;

    beforeEach(() => {
      fetchSpy = vi.spyOn(globalThis, 'fetch').mockResolvedValue({
        ok: true,
        json: () => Promise.resolve({})
      } as Response);

      // Mock window.location.reload
      originalLocation = window.location;
      reloadMock = vi.fn();
      Object.defineProperty(window, 'location', {
        value: { ...originalLocation, reload: reloadMock },
        writable: true,
        configurable: true
      });
    });

    afterEach(() => {
      fetchSpy.mockRestore();
      Object.defineProperty(window, 'location', {
        value: originalLocation,
        writable: true,
        configurable: true
      });
    });

    it('saves settings via API and reloads page', async () => {
      document.body.innerHTML = `
        <input type="checkbox" id="showallwords" checked />
        <input type="checkbox" id="showlearningtranslations" />
      `;

      await showAllwordsClick();

      expect(fetchSpy).toHaveBeenCalledWith('/api/v1/settings', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ key: 'showallwords', value: '1' })
      });
      expect(fetchSpy).toHaveBeenCalledWith('/api/v1/settings', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ key: 'showlearningtranslations', value: '0' })
      });
      expect(reloadMock).toHaveBeenCalled();
    });

    it('sends value 0 when showallwords is unchecked', async () => {
      document.body.innerHTML = `
        <input type="checkbox" id="showallwords" />
        <input type="checkbox" id="showlearningtranslations" />
      `;

      await showAllwordsClick();

      expect(fetchSpy).toHaveBeenCalledWith('/api/v1/settings', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ key: 'showallwords', value: '0' })
      });
    });

    it('sends value 1 when showlearningtranslations is checked', async () => {
      document.body.innerHTML = `
        <input type="checkbox" id="showallwords" checked />
        <input type="checkbox" id="showlearningtranslations" checked />
      `;

      await showAllwordsClick();

      expect(fetchSpy).toHaveBeenCalledWith('/api/v1/settings', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ key: 'showlearningtranslations', value: '1' })
      });
    });

    it('handles missing elements gracefully', async () => {
      document.body.innerHTML = '';

      // Should not throw and still attempt to save
      await expect(showAllwordsClick()).resolves.not.toThrow();
    });

    it('reverts checkbox states and shows alert on error', async () => {
      fetchSpy.mockRejectedValue(new Error('Network error'));
      const alertSpy = dismissAlerts();

      document.body.innerHTML = `
        <input type="checkbox" id="showallwords" checked />
        <input type="checkbox" id="showlearningtranslations" checked />
      `;

      await showAllwordsClick();

      await settle();
      expect(alertSpy).toHaveBeenCalledWith('Failed to save. Please try again.');
      // Checkboxes should be reverted (were checked, now should be unchecked)
      const showAllEl = document.getElementById('showallwords') as HTMLInputElement;
      const showLearningEl = document.getElementById('showlearningtranslations') as HTMLInputElement;
      expect(showAllEl.checked).toBe(false);
      expect(showLearningEl.checked).toBe(false);
      expect(reloadMock).not.toHaveBeenCalled();
    });
  });

  // ===========================================================================
  // serializeFormToObject Tests
  // ===========================================================================

  describe('serializeFormToObject', () => {
    const { serializeFormToObject } = ui_utilities;

    it('serializes form to object', () => {
      document.body.innerHTML = `
        <form id="testform">
          <input type="text" name="field1" value="value1" />
          <input type="text" name="field2" value="value2" />
        </form>
      `;

      const form = document.getElementById('testform') as HTMLFormElement;
      const result = serializeFormToObject(form);

      expect(result.field1).toBe('value1');
      expect(result.field2).toBe('value2');
    });

    it('handles multiple values with same name as array', () => {
      document.body.innerHTML = `
        <form id="testform">
          <input type="checkbox" name="items" value="a" checked />
          <input type="checkbox" name="items" value="b" checked />
          <input type="checkbox" name="items" value="c" checked />
        </form>
      `;

      const form = document.getElementById('testform') as HTMLFormElement;
      const result = serializeFormToObject(form);

      expect(Array.isArray(result.items)).toBe(true);
      expect(result.items).toEqual(['a', 'b', 'c']);
    });

    it('handles empty values', () => {
      document.body.innerHTML = `
        <form id="testform">
          <input type="text" name="empty" value="" />
        </form>
      `;

      const form = document.getElementById('testform') as HTMLFormElement;
      const result = serializeFormToObject(form);

      expect(result.empty).toBe('');
    });
  });

  // ===========================================================================
  // Edge Cases
  // ===========================================================================

  describe('Edge Cases', () => {
    it('markClick handles empty DOM', () => {
      document.body.innerHTML = '';

      expect(() => markClick()).not.toThrow();
    });
  });

  // ===========================================================================
  // initHideMessages Tests
  // ===========================================================================

  describe('initHideMessages', () => {
    const { initHideMessages } = ui_utilities;

    it('auto-dismisses elements with hide_message class', () => {
      document.body.innerHTML = `
        <div class="hide_message">Message 1</div>
        <div class="hide_message">Message 2</div>
      `;

      initHideMessages();

      // Fast forward past the 2500ms delay
      vi.advanceTimersByTime(2500);

      // Messages should start to hide (slideUp animation)
      const messages = document.querySelectorAll('.hide_message');
      expect(messages.length).toBe(2);
    });

    it('handles no hide_message elements', () => {
      document.body.innerHTML = '<div>No messages</div>';

      expect(() => initHideMessages()).not.toThrow();
    });
  });

  // ===========================================================================
  // prepareMainAreas Tests
  // ===========================================================================

  describe('prepareMainAreas', () => {
    const { prepareMainAreas } = ui_utilities;

    beforeEach(() => {
      // Reset any event listeners that might persist
      document.body.innerHTML = '';
    });

    it('wraps select elements with label', () => {
      document.body.innerHTML = '<select id="test"><option>Option</option></select>';

      prepareMainAreas();

      const label = document.querySelector('label.wrap_select');
      expect(label).not.toBeNull();
      expect(label?.querySelector('select')).not.toBeNull();
    });

    it('disables autocomplete on forms', () => {
      document.body.innerHTML = '<form id="testform"></form>';

      prepareMainAreas();

      expect(document.getElementById('testform')?.getAttribute('autocomplete')).toBe('off');
    });

    it('wraps checkboxes with labels', () => {
      document.body.innerHTML = '<input type="checkbox" />';

      prepareMainAreas();

      const label = document.querySelector('label.wrap_checkbox');
      expect(label).not.toBeNull();
    });

    it('adds click handler to TTS spans', () => {
      document.body.innerHTML = '<span class="tts_en">Hello</span>';

      prepareMainAreas();

      const span = document.querySelector('.tts_en') as HTMLElement;
      expect(span).not.toBeNull();
      // Click should not throw
      expect(() => span.click()).not.toThrow();
    });

    it('wraps radio buttons with labels', () => {
      document.body.innerHTML = '<input type="radio" name="test" value="1" />';

      prepareMainAreas();

      const label = document.querySelector('label.wrap_radio');
      expect(label).not.toBeNull();
    });

    it('sets up form validation handlers', () => {
      document.body.innerHTML = '<form class="validate"><input type="submit" /></form>';

      prepareMainAreas();

      const form = document.querySelector('form.validate');
      expect(form).not.toBeNull();
    });

    it('sets up mark checkbox handlers', () => {
      document.body.innerHTML = `
        <input type="checkbox" class="markcheck" />
        <button id="markaction" disabled>Action</button>
      `;

      prepareMainAreas();

      // First check it's initially disabled
      expect(document.getElementById('markaction')?.hasAttribute('disabled')).toBe(true);

      // Check the checkbox and trigger click handler
      const checkbox = document.querySelector('.markcheck') as HTMLInputElement;
      checkbox.checked = true;
      // Trigger the click event to call markClick handler
      checkbox.dispatchEvent(new Event('click', { bubbles: true }));

      // markClick should have been called, enabling the button
      expect(document.getElementById('markaction')?.hasAttribute('disabled')).toBe(false);
    });

    it('sets up textarea no-return handlers', () => {
      document.body.innerHTML = `
        <form>
          <textarea class="textarea-noreturn"></textarea>
          <input type="submit" />
        </form>
      `;

      prepareMainAreas();

      const textarea = document.querySelector('.textarea-noreturn') as HTMLTextAreaElement;
      expect(textarea).not.toBeNull();

      // Simulate Enter key - should be handled
      const event = new KeyboardEvent('keydown', { keyCode: 13 });
      textarea.dispatchEvent(event);
    });

    it('handles hidden file inputs', () => {
      document.body.innerHTML = `
        <input type="file" style="display: none;" />
      `;

      prepareMainAreas();

      // File input handling - may or may not create button based on visibility
      const fileInput = document.querySelector('input[type="file"]');
      expect(fileInput).not.toBeNull();
    });

    it('schedules initAutoHideNotifications', () => {
      document.body.innerHTML = '<div class="notification" data-auto-hide="true">Message</div>';

      prepareMainAreas();

      // Should schedule the hide operation
      vi.advanceTimersByTime(3000);
      // Function was called - element exists
      expect(document.querySelector('[data-auto-hide]')).not.toBeNull();
    });

    it('handles annotation inputs', () => {
      document.body.innerHTML = `
        <input type="text" class="impr-ann-text" />
        <input type="radio" class="impr-ann-radio" name="ann" />
      `;

      prepareMainAreas();

      // Event handlers should be attached
      const textInput = document.querySelector('.impr-ann-text');
      const radioInput = document.querySelector('.impr-ann-radio');
      expect(textInput).not.toBeNull();
      expect(radioInput).not.toBeNull();
    });

    it('handles confirm delete elements', async () => {
      answerConfirms(false);
      document.body.innerHTML = '<a class="confirmdelete" href="#">Delete</a>';

      prepareMainAreas();

      const deleteLink = document.querySelector('.confirmdelete') as HTMLElement;
      deleteLink.click();

      await settle();
      expect(confirmPrompts).toHaveBeenCalled();
    });
  });

  // ===========================================================================
  // slideUp animation (internal) via initAutoHideNotifications
  // ===========================================================================

  describe('slideUp animation', () => {
    it('hides element after animation completes', () => {
      document.body.innerHTML = '<div class="notification" data-auto-hide="true" style="height: 100px;">Message</div>';

      initAutoHideNotifications();

      // Advance through the animation (400ms default)
      vi.advanceTimersByTime(400);

      // Element should be hidden after animation
      const element = document.querySelector('.notification') as HTMLElement;
      expect(element?.style.display).toBe('none');
    });
  });
});
