/**
 * Tests for settings_form.ts - Settings form interactions
 */
import { answerConfirms, confirmPrompts, settle, trackSubmissions } from '../helpers/confirm_answers';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import {
  settingsFormApp,
  initSettingsForm,
  initConfirmSubmitForms,
  initNavigateButtons,
  initHistoryBackButtons,
} from '../../../src/frontend/js/modules/admin/pages/settings_form';
import * as unloadformcheck from '../../../src/frontend/js/shared/forms/unloadformcheck';

// Mock the lwtFormCheck module
vi.mock('../../../src/frontend/js/shared/forms/unloadformcheck', () => ({
  lwtFormCheck: {
    askBeforeExit: vi.fn(),
    resetDirty: vi.fn(),
    makeDirty: vi.fn(),
  },
}));

describe('settings_form.ts', () => {
  beforeEach(() => {
    // Clear the DOM
    document.body.innerHTML = '';
    vi.clearAllMocks();
  });

  afterEach(() => {
    document.body.innerHTML = '';
    vi.restoreAllMocks();
  });

  // ===========================================================================
  // settingsFormApp Alpine Component Tests
  // ===========================================================================

  describe('settingsFormApp (Alpine component)', () => {
    it('initializes with default state', () => {
      const app = settingsFormApp();

      expect(app.isDirty).toBe(false);
      expect(app.isSubmitting).toBe(false);
    });

    it('init sets up form change tracking', () => {
      const app = settingsFormApp();
      app.init();

      expect(unloadformcheck.lwtFormCheck.askBeforeExit).toHaveBeenCalled();
    });

    it('navigate resets dirty state and redirects', () => {
      Object.defineProperty(window, 'location', {
        value: { href: '' },
        writable: true,
      });

      const app = settingsFormApp();
      app.navigate('/test/url');

      expect(unloadformcheck.lwtFormCheck.resetDirty).toHaveBeenCalled();
      expect(location.href).toBe('/test/url');
    });

    it('historyBack calls history.back', () => {
      const backSpy = vi.spyOn(history, 'back').mockImplementation(() => {});

      const app = settingsFormApp();
      app.historyBack();

      expect(backSpy).toHaveBeenCalled();
    });

    it('confirmSubmit submits and sets isSubmitting once confirmed', async () => {
      answerConfirms(true);
      const submissions = trackSubmissions();
      document.body.innerHTML = '<form></form>';
      const form = document.querySelector('form') as HTMLFormElement;
      const app = settingsFormApp();
      form.addEventListener('submit', (event) => app.confirmSubmit(event, 'Are you sure?'));

      form.requestSubmit();

      expect(submissions).toHaveLength(0);
      await settle();
      expect(submissions).toHaveLength(1);
      expect(app.isSubmitting).toBe(true);
      expect(confirmPrompts).toHaveBeenCalledWith('Are you sure?');
    });

    it('confirmSubmit returns false and prevents default when cancelled', async () => {
      answerConfirms(false);

      const app = settingsFormApp();
      const event = new Event('submit', { cancelable: true });
      const preventDefaultSpy = vi.spyOn(event, 'preventDefault');

      const result = app.confirmSubmit(event, 'Continue?');

      await settle();
      expect(result).toBe(false);
      expect(preventDefaultSpy).toHaveBeenCalled();
      expect(app.isSubmitting).toBe(false);
    });

    it('confirmSubmit uses default message when not provided', async () => {
      answerConfirms(true);

      trackSubmissions();
      document.body.innerHTML = '<form></form>';
      const form = document.querySelector('form') as HTMLFormElement;
      const app = settingsFormApp();
      form.addEventListener('submit', (event) => app.confirmSubmit(event));

      form.requestSubmit();

      await settle();
      expect(confirmPrompts).toHaveBeenCalledWith('Are you sure?');
    });
  });

  // ===========================================================================
  // initSettingsForm Tests (Legacy)
  // ===========================================================================

  describe('initSettingsForm', () => {
    it('does nothing when no settings form exists', () => {
      document.body.innerHTML = '<div>No form here</div>';

      initSettingsForm();

      expect(unloadformcheck.lwtFormCheck.askBeforeExit).not.toHaveBeenCalled();
    });

    it('sets up form change tracking when settings form exists', () => {
      document.body.innerHTML = '<form data-lwt-settings-form></form>';

      initSettingsForm();

      expect(unloadformcheck.lwtFormCheck.askBeforeExit).toHaveBeenCalledTimes(1);
    });

    it('handles settings-navigate button clicks', () => {
      document.body.innerHTML = `
        <form data-lwt-settings-form>
          <button data-action="settings-navigate" data-url="/settings/page">Settings</button>
        </form>
      `;

      // Track location.href changes
      const originalHref = location.href;
      Object.defineProperty(window, 'location', {
        value: { href: originalHref },
        writable: true,
      });

      initSettingsForm();

      // Click the settings-navigate button
      const button = document.querySelector('[data-action="settings-navigate"]') as HTMLElement;
      button.click();

      expect(unloadformcheck.lwtFormCheck.resetDirty).toHaveBeenCalled();
    });

    it('ignores settings-navigate without url', () => {
      document.body.innerHTML = `
        <form data-lwt-settings-form>
          <button data-action="settings-navigate">No URL</button>
        </form>
      `;

      initSettingsForm();

      // Click should not throw
      const button = document.querySelector('[data-action="settings-navigate"]') as HTMLElement;
      expect(() => button.click()).not.toThrow();
    });
  });

  // ===========================================================================
  // initConfirmSubmitForms Tests
  // ===========================================================================

  describe('initConfirmSubmitForms', () => {
    it('shows confirmation dialog on form submit', async () => {
      document.body.innerHTML = `
        <form data-action="confirm-submit" data-confirm-message="Are you sure you want to proceed?">
          <button type="submit">Submit</button>
        </form>
      `;

      const confirmSpy = answerConfirms(true);

      initConfirmSubmitForms();

      const form = document.querySelector('form') as HTMLFormElement;
      form.dispatchEvent(new Event('submit', { bubbles: true }));

      await settle();
      expect(confirmSpy).toHaveBeenCalledWith('Are you sure you want to proceed?');
    });

    it('uses default message when data-confirm-message is not set', async () => {
      document.body.innerHTML = `
        <form data-action="confirm-submit">
          <button type="submit">Submit</button>
        </form>
      `;

      const confirmSpy = answerConfirms(true);

      initConfirmSubmitForms();

      const form = document.querySelector('form') as HTMLFormElement;
      form.dispatchEvent(new Event('submit', { bubbles: true }));

      await settle();
      expect(confirmSpy).toHaveBeenCalledWith('Are you sure?');
    });

    it('prevents form submission when user cancels', async () => {
      document.body.innerHTML = `
        <form data-action="confirm-submit">
          <button type="submit">Submit</button>
        </form>
      `;

      const confirmSpy = answerConfirms(false);

      initConfirmSubmitForms();

      const form = document.querySelector('form') as HTMLFormElement;
      const submitEvent = new Event('submit', { bubbles: true, cancelable: true });
      const preventDefaultSpy = vi.spyOn(submitEvent, 'preventDefault');

      form.dispatchEvent(submitEvent);

      await settle();
      expect(confirmSpy).toHaveBeenCalled();
      expect(preventDefaultSpy).toHaveBeenCalled();
    });

    it('submits once when user confirms', async () => {
      document.body.innerHTML = `
        <form data-action="confirm-submit">
          <button type="submit">Submit</button>
        </form>
      `;

      const confirmSpy = answerConfirms(true);
      const submissions = trackSubmissions();

      initConfirmSubmitForms();

      const form = document.querySelector('form') as HTMLFormElement;
      form.requestSubmit();

      expect(submissions).toHaveLength(0);
      await settle();
      expect(confirmSpy).toHaveBeenCalled();
      expect(submissions).toHaveLength(1);
    });

    it('ignores non-confirm-submit forms', async () => {
      document.body.innerHTML = `
        <form>
          <button type="submit">Submit</button>
        </form>
      `;

      const confirmSpy = answerConfirms(true);

      initConfirmSubmitForms();

      const form = document.querySelector('form') as HTMLFormElement;
      form.dispatchEvent(new Event('submit', { bubbles: true }));

      await settle();
      expect(confirmSpy).not.toHaveBeenCalled();
    });

    it('adds loading state to submit button after confirmation', async () => {
      document.body.innerHTML = `
        <form data-action="confirm-submit">
          <input type="submit" value="Submit" />
        </form>
      `;

      answerConfirms(true);

      initConfirmSubmitForms();

      const form = document.querySelector('form') as HTMLFormElement;
      const submitButton = form.querySelector('input[type="submit"]') as HTMLInputElement;

      await settle();
      expect(submitButton.classList.contains('is-loading')).toBe(false);
      expect(submitButton.disabled).toBe(false);

      trackSubmissions();
      form.requestSubmit();

      expect(submitButton.classList.contains('is-loading')).toBe(false);
      await settle();
      expect(submitButton.classList.contains('is-loading')).toBe(true);
      expect(submitButton.disabled).toBe(true);
    });

    it('does not add loading state when user cancels', async () => {
      document.body.innerHTML = `
        <form data-action="confirm-submit">
          <button type="submit">Submit</button>
        </form>
      `;

      answerConfirms(false);

      initConfirmSubmitForms();

      const form = document.querySelector('form') as HTMLFormElement;
      const submitButton = form.querySelector('button[type="submit"]') as HTMLButtonElement;

      form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));

      await settle();
      expect(submitButton.classList.contains('is-loading')).toBe(false);
      expect(submitButton.disabled).toBe(false);
    });
  });

  // ===========================================================================
  // initNavigateButtons Tests
  // ===========================================================================

  describe('initNavigateButtons', () => {
    it('navigates to URL on button click', () => {
      document.body.innerHTML = `
        <button data-action="navigate" data-url="/destination/page">Go</button>
      `;

      // Track location.href changes
      Object.defineProperty(window, 'location', {
        value: { href: '' },
        writable: true,
      });

      initNavigateButtons();

      const button = document.querySelector('[data-action="navigate"]') as HTMLElement;
      button.click();

      expect(location.href).toBe('/destination/page');
    });

    it('ignores click without url', () => {
      document.body.innerHTML = `
        <button data-action="navigate">No URL</button>
      `;

      Object.defineProperty(window, 'location', {
        value: { href: '' },
        writable: true,
      });

      initNavigateButtons();

      const button = document.querySelector('[data-action="navigate"]') as HTMLElement;
      button.click();

      // Should not navigate (href unchanged)
      expect(location.href).toBe('');
    });

    it('works with nested elements', () => {
      document.body.innerHTML = `
        <button data-action="navigate" data-url="/nested">
          <span>Click Me</span>
        </button>
      `;

      Object.defineProperty(window, 'location', {
        value: { href: '' },
        writable: true,
      });

      initNavigateButtons();

      // Click the nested span - should still trigger navigation
      const span = document.querySelector('span') as HTMLElement;
      span.click();

      expect(location.href).toBe('/nested');
    });
  });

  // ===========================================================================
  // initHistoryBackButtons Tests
  // ===========================================================================

  describe('initHistoryBackButtons', () => {
    it('calls history.back on button click', () => {
      document.body.innerHTML = `
        <button data-action="history-back">Back</button>
      `;

      const backSpy = vi.spyOn(history, 'back').mockImplementation(() => {});

      initHistoryBackButtons();

      const button = document.querySelector('[data-action="history-back"]') as HTMLElement;
      button.click();

      expect(backSpy).toHaveBeenCalled();
    });

    it('prevents default event behavior', () => {
      document.body.innerHTML = `
        <button data-action="history-back">Back</button>
      `;

      vi.spyOn(history, 'back').mockImplementation(() => {});

      initHistoryBackButtons();

      const button = document.querySelector('[data-action="history-back"]') as HTMLElement;
      const clickEvent = new MouseEvent('click', { bubbles: true, cancelable: true });
      const preventDefaultSpy = vi.spyOn(clickEvent, 'preventDefault');

      button.dispatchEvent(clickEvent);

      expect(preventDefaultSpy).toHaveBeenCalled();
    });

    it('works with nested elements', () => {
      document.body.innerHTML = `
        <button data-action="history-back">
          <span>Go Back</span>
        </button>
      `;

      const backSpy = vi.spyOn(history, 'back').mockImplementation(() => {});

      initHistoryBackButtons();

      // Click the nested span
      const span = document.querySelector('span') as HTMLElement;
      span.click();

      expect(backSpy).toHaveBeenCalled();
    });
  });

  // ===========================================================================
  // Integration Tests
  // ===========================================================================

  describe('Integration', () => {
    it('all handlers can be initialized without errors', () => {
      document.body.innerHTML = `
        <form data-lwt-settings-form>
          <button data-action="settings-navigate" data-url="/settings">Settings</button>
        </form>
        <form data-action="confirm-submit">
          <button type="submit">Submit</button>
        </form>
        <button data-action="navigate" data-url="/page">Navigate</button>
        <button data-action="history-back">Back</button>
      `;

      // Initialize all handlers - should not throw
      expect(() => {
        initSettingsForm();
        initConfirmSubmitForms();
        initNavigateButtons();
        initHistoryBackButtons();
      }).not.toThrow();
    });
  });
});
