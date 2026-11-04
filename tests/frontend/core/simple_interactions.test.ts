/**
 * Tests for simple_interactions.ts - Navigation and confirmation utilities.
 */
import { answerConfirms, confirmPrompts, settle, trackSubmissions } from '../helpers/confirm_answers';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

// Mock the dependencies
vi.mock('../../../src/frontend/js/shared/forms/unloadformcheck', () => ({
  lwtFormCheck: {
    resetDirty: vi.fn()
  }
}));

vi.mock('../../../src/frontend/js/shared/utils/ui_utilities', () => ({
  showAllwordsClick: vi.fn()
}));

vi.mock('../../../src/frontend/js/shared/utils/user_interactions', () => ({
  quickMenuRedirection: vi.fn()
}));

vi.mock('../../../src/frontend/js/modules/vocabulary/services/translation_api', () => ({
  deleteTranslation: vi.fn(),
  addTranslation: vi.fn()
}));

vi.mock('../../../src/frontend/js/modules/vocabulary/services/term_operations', () => ({
  changeTableTestStatus: vi.fn()
}));

vi.mock('../../../src/frontend/js/shared/components/modal', () => ({
  showExportTemplateHelp: vi.fn()
}));

vi.mock('../../../src/frontend/js/modules/vocabulary/services/word_dom_updates', () => ({
  markWordWellKnownInDOM: vi.fn(),
  markWordIgnoredInDOM: vi.fn()
}));

import {
  goBack,
  navigateTo,
  cancelAndNavigate,
  cancelAndGoBack,
  confirmSubmit,
  initSimpleInteractions
} from '../../../src/frontend/js/shared/utils/simple_interactions';
import { lwtFormCheck } from '../../../src/frontend/js/shared/forms/unloadformcheck';
import { showAllwordsClick } from '../../../src/frontend/js/shared/utils/ui_utilities';
import { quickMenuRedirection } from '../../../src/frontend/js/shared/utils/user_interactions';
import { deleteTranslation, addTranslation } from '../../../src/frontend/js/modules/vocabulary/services/translation_api';
import { changeTableTestStatus } from '../../../src/frontend/js/modules/vocabulary/services/term_operations';
import { showExportTemplateHelp } from '../../../src/frontend/js/shared/components/modal';
import { markWordWellKnownInDOM, markWordIgnoredInDOM } from '../../../src/frontend/js/modules/vocabulary/services/word_dom_updates';

describe('simple_interactions.ts', () => {
  let originalLocation: Location;
  let originalHistory: History;

  beforeEach(() => {
    vi.clearAllMocks();
    document.body.innerHTML = '';

    // Mock location
    originalLocation = window.location;
    delete (window as any).location;
    window.location = {
      href: 'http://localhost/',
      assign: vi.fn(),
      replace: vi.fn(),
      reload: vi.fn()
    } as unknown as Location;

    // Mock history
    originalHistory = window.history;
    Object.defineProperty(window, 'history', {
      value: {
        back: vi.fn(),
        forward: vi.fn(),
        go: vi.fn(),
        pushState: vi.fn(),
        replaceState: vi.fn(),
        length: 1,
        state: null,
        scrollRestoration: 'auto'
      },
      writable: true
    });
  });

  afterEach(() => {
    window.location = originalLocation;
    Object.defineProperty(window, 'history', {
      value: originalHistory,
      writable: true
    });
  });

  describe('goBack', () => {
    it('calls history.back()', () => {
      goBack();
      expect(window.history.back).toHaveBeenCalled();
    });
  });

  describe('navigateTo', () => {
    it('sets location.href to the given URL', () => {
      navigateTo('http://example.com/page');
      expect(window.location.href).toBe('http://example.com/page');
    });

    it('works with relative URLs', () => {
      navigateTo('/relative/path');
      expect(window.location.href).toBe('/relative/path');
    });
  });

  describe('cancelAndNavigate', () => {
    it('resets form dirty state before navigating', () => {
      cancelAndNavigate('http://example.com/cancel');

      expect(lwtFormCheck.resetDirty).toHaveBeenCalled();
      expect(window.location.href).toBe('http://example.com/cancel');
    });

    it('resets dirty state before setting href', () => {
      const calls: string[] = [];
      (lwtFormCheck.resetDirty as any).mockImplementation(() => calls.push('resetDirty'));

      // Create a getter/setter to track order
      let href = 'http://localhost/';
      Object.defineProperty(window.location, 'href', {
        get: () => href,
        set: (value) => {
          calls.push('setHref');
          href = value;
        },
        configurable: true
      });

      cancelAndNavigate('http://example.com/test');

      expect(calls).toEqual(['resetDirty', 'setHref']);
    });
  });

  describe('cancelAndGoBack', () => {
    it('resets form dirty state before going back', () => {
      cancelAndGoBack();

      expect(lwtFormCheck.resetDirty).toHaveBeenCalled();
      expect(window.history.back).toHaveBeenCalled();
    });
  });

  describe('confirmSubmit', () => {
    it('shows confirmation dialog with default message', async () => {
      answerConfirms(true);

      const result = await confirmSubmit();

      await settle();
      expect(confirmPrompts).toHaveBeenCalledWith('Are you sure?');
      expect(result).toBe(true);
    });

    it('shows confirmation dialog with custom message', async () => {
      answerConfirms(true);

      const result = await confirmSubmit('Delete this item?');

      await settle();
      expect(confirmPrompts).toHaveBeenCalledWith('Delete this item?');
      expect(result).toBe(true);
    });

    it('returns false when user cancels', async () => {
      answerConfirms(false);

      const result = await confirmSubmit('Test message');

      await settle();
      expect(result).toBe(false);
    });
  });

  describe('initSimpleInteractions', () => {
    beforeEach(() => {
      initSimpleInteractions();
    });

    describe('data-action="cancel-navigate"', () => {
      it('cancels and navigates to the specified URL', () => {
        document.body.innerHTML = `
          <button data-action="cancel-navigate" data-url="/cancel/url">Cancel</button>
        `;

        const button = document.querySelector('[data-action="cancel-navigate"]') as HTMLElement;
        button.dispatchEvent(new Event('click', { bubbles: true }));

        expect(lwtFormCheck.resetDirty).toHaveBeenCalled();
        expect(window.location.href).toBe('/cancel/url');
      });

      it('does nothing if no URL is provided', () => {
        document.body.innerHTML = `
          <button data-action="cancel-navigate">Cancel</button>
        `;

        const button = document.querySelector('[data-action="cancel-navigate"]') as HTMLElement;
        button.dispatchEvent(new Event('click', { bubbles: true }));

        expect(lwtFormCheck.resetDirty).not.toHaveBeenCalled();
      });
    });

    describe('data-action="cancel-back"', () => {
      it('cancels and goes back in history', () => {
        document.body.innerHTML = `
          <button data-action="cancel-back">Go Back</button>
        `;

        const button = document.querySelector('[data-action="cancel-back"]') as HTMLElement;
        button.dispatchEvent(new Event('click', { bubbles: true }));

        expect(lwtFormCheck.resetDirty).toHaveBeenCalled();
        expect(window.history.back).toHaveBeenCalled();
      });
    });

    describe('data-action="navigate"', () => {
      it('navigates to the specified URL', () => {
        document.body.innerHTML = `
          <button data-action="navigate" data-url="/new/page">Go</button>
        `;

        const button = document.querySelector('[data-action="navigate"]') as HTMLElement;
        button.dispatchEvent(new Event('click', { bubbles: true }));

        expect(window.location.href).toBe('/new/page');
      });

      it('does nothing if no URL is provided', () => {
        document.body.innerHTML = `
          <button data-action="navigate">Go</button>
        `;
        window.location.href = 'http://localhost/original';

        const button = document.querySelector('[data-action="navigate"]') as HTMLElement;
        button.dispatchEvent(new Event('click', { bubbles: true }));

        expect(window.location.href).toBe('http://localhost/original');
      });
    });

    describe('data-action="back"', () => {
      it('goes back in browser history', () => {
        document.body.innerHTML = `
          <button data-action="back">Back</button>
        `;

        const button = document.querySelector('[data-action="back"]') as HTMLElement;
        button.dispatchEvent(new Event('click', { bubbles: true }));

        expect(window.history.back).toHaveBeenCalled();
      });
    });

    describe('data-action="confirm-delete"', () => {
      it('shows confirmation and navigates if confirmed', async () => {
        answerConfirms(true);
        document.body.innerHTML = `
          <button data-action="confirm-delete" data-url="/delete/item">Delete</button>
        `;

        const button = document.querySelector('[data-action="confirm-delete"]') as HTMLElement;
        button.dispatchEvent(new Event('click', { bubbles: true }));

        await settle();
        expect(confirmPrompts).toHaveBeenCalledWith('Are you sure you want to delete this?');
        expect(window.location.href).toBe('/delete/item');
      });

      it('does nothing if user cancels', async () => {
        answerConfirms(false);
        document.body.innerHTML = `
          <button data-action="confirm-delete" data-url="/delete/item">Delete</button>
        `;
        window.location.href = 'http://localhost/original';

        const button = document.querySelector('[data-action="confirm-delete"]') as HTMLElement;
        button.dispatchEvent(new Event('click', { bubbles: true }));

        await settle();
        expect(confirmPrompts).toHaveBeenCalled();
        expect(window.location.href).toBe('http://localhost/original');
      });
    });

    describe('data-action="cancel-form"', () => {
      it('cancels and navigates to URL', () => {
        document.body.innerHTML = `
          <button data-action="cancel-form" data-url="/form/list">Cancel Form</button>
        `;

        const button = document.querySelector('[data-action="cancel-form"]') as HTMLElement;
        button.dispatchEvent(new Event('click', { bubbles: true }));

        expect(lwtFormCheck.resetDirty).toHaveBeenCalled();
        expect(window.location.href).toBe('/form/list');
      });
    });

    describe('data-action="show-right-frames"', () => {
      it('is a legacy no-op action', () => {
        // This action is kept for backward compatibility but does nothing
        // since iframes were removed
        document.body.innerHTML = `
          <button data-action="show-right-frames">Show Frames</button>
        `;

        const button = document.querySelector('[data-action="show-right-frames"]') as HTMLElement;
        // Should not throw
        expect(() => button.dispatchEvent(new Event('click', { bubbles: true }))).not.toThrow();
      });
    });

    describe('data-action="hide-right-frames"', () => {
      it('is a legacy no-op action', () => {
        // This action is kept for backward compatibility but does nothing
        // since iframes were removed
        document.body.innerHTML = `
          <button data-action="hide-right-frames">Hide Frames</button>
        `;

        const button = document.querySelector('[data-action="hide-right-frames"]') as HTMLElement;
        // Should not throw
        expect(() => button.dispatchEvent(new Event('click', { bubbles: true }))).not.toThrow();
      });
    });

    describe('data-action="toggle-show-all"', () => {
      it('toggles show all words mode', () => {
        document.body.innerHTML = `
          <button data-action="toggle-show-all">Toggle</button>
        `;

        const button = document.querySelector('[data-action="toggle-show-all"]') as HTMLElement;
        button.dispatchEvent(new Event('click', { bubbles: true }));

        expect(showAllwordsClick).toHaveBeenCalled();
      });
    });

    describe('data-confirm attribute', () => {
      it('shows confirmation before executing action', async () => {
        answerConfirms(true);
        document.body.innerHTML = `
          <button data-action="navigate" data-url="/page" data-confirm="Are you sure you want to navigate?">Go</button>
        `;

        const button = document.querySelector('[data-action="navigate"]') as HTMLElement;
        button.dispatchEvent(new Event('click', { bubbles: true }));

        await settle();
        expect(confirmPrompts).toHaveBeenCalledWith('Are you sure you want to navigate?');
        expect(window.location.href).toBe('/page');
      });

      it('prevents action if confirmation is cancelled', async () => {
        answerConfirms(false);
        document.body.innerHTML = `
          <button data-action="navigate" data-url="/page" data-confirm="Are you sure?">Go</button>
        `;
        window.location.href = 'http://localhost/original';

        const button = document.querySelector('[data-action="navigate"]') as HTMLElement;
        button.dispatchEvent(new Event('click', { bubbles: true }));

        await settle();
        expect(confirmPrompts).toHaveBeenCalled();
        expect(window.location.href).toBe('http://localhost/original');
      });
    });

    describe('form submission confirmation', () => {
      it('shows confirmation before form submission', async () => {
        answerConfirms(true);
        document.body.innerHTML = `
          <form data-confirm-submit="Submit this form?">
            <button type="submit">Submit</button>
          </form>
        `;

        const submissions = trackSubmissions();
        document.querySelector('form')!.requestSubmit();

        expect(submissions).toHaveLength(0);
        await settle();
        expect(confirmPrompts).toHaveBeenCalledWith('Submit this form?');
        expect(submissions).toHaveLength(1);
      });

      it('asks before the form\'s own handlers run', async () => {
        answerConfirms(false);
        document.body.innerHTML = `
          <form data-confirm-submit="Restore?">
            <button type="submit">Submit</button>
          </form>
        `;
        const form = document.querySelector('form')!;
        const ownHandler = vi.fn();
        form.addEventListener('submit', ownHandler);

        form.requestSubmit();
        await settle();

        expect(confirmPrompts).toHaveBeenCalledWith('Restore?');
        expect(ownHandler).not.toHaveBeenCalled();
      });

      it('prevents submission if confirmation is cancelled', async () => {
        answerConfirms(false);
        document.body.innerHTML = `
          <form data-confirm-submit="Submit this form?">
            <button type="submit">Submit</button>
          </form>
        `;

        const form = document.querySelector('form')!;
        const event = new Event('submit', { bubbles: true, cancelable: true });
        form.dispatchEvent(event);

        await settle();
        expect(confirmPrompts).toHaveBeenCalled();
        expect(event.defaultPrevented).toBe(true);
      });

      it('uses default message if not specified', async () => {
        answerConfirms(true);
        document.body.innerHTML = `
          <form data-confirm-submit>
            <button type="submit">Submit</button>
          </form>
        `;

        const form = document.querySelector('form')!;
        const event = new Event('submit', { bubbles: true, cancelable: true });
        form.dispatchEvent(event);

        await settle();
        expect(confirmPrompts).toHaveBeenCalledWith('Are you sure?');
      });
    });

    describe('data-action="delete-translation"', () => {
      it('calls deleteTranslation', () => {
        document.body.innerHTML = `
          <button data-action="delete-translation">Delete</button>
        `;

        const button = document.querySelector('[data-action="delete-translation"]') as HTMLElement;
        button.dispatchEvent(new Event('click', { bubbles: true }));

        expect(deleteTranslation).toHaveBeenCalled();
      });
    });

    describe('data-action="add-translation"', () => {
      it('calls addTranslation with word data', () => {
        document.body.innerHTML = `
          <button data-action="add-translation" data-word="hello">Add</button>
        `;

        const button = document.querySelector('[data-action="add-translation"]') as HTMLElement;
        button.dispatchEvent(new Event('click', { bubbles: true }));

        expect(addTranslation).toHaveBeenCalledWith('hello');
      });

      it('does nothing without word data', () => {
        document.body.innerHTML = `
          <button data-action="add-translation">Add</button>
        `;

        const button = document.querySelector('[data-action="add-translation"]') as HTMLElement;
        button.dispatchEvent(new Event('click', { bubbles: true }));

        expect(addTranslation).not.toHaveBeenCalled();
      });
    });

    describe('data-action="open-window"', () => {
      it('opens URL in new window', () => {
        const openSpy = vi.spyOn(window, 'open').mockReturnValue(null);
        document.body.innerHTML = `
          <button data-action="open-window" data-url="http://example.com/page">Open</button>
        `;

        const button = document.querySelector('[data-action="open-window"]') as HTMLElement;
        button.dispatchEvent(new Event('click', { bubbles: true }));

        expect(openSpy).toHaveBeenCalledWith('http://example.com/page', '_blank');
      });

      it('uses custom window name', () => {
        const openSpy = vi.spyOn(window, 'open').mockReturnValue(null);
        document.body.innerHTML = `
          <button data-action="open-window" data-url="http://example.com" data-window-name="mywin">Open</button>
        `;

        const button = document.querySelector('[data-action="open-window"]') as HTMLElement;
        button.dispatchEvent(new Event('click', { bubbles: true }));

        expect(openSpy).toHaveBeenCalledWith('http://example.com', 'mywin');
      });

      it('uses anchor href if no data-url', () => {
        const openSpy = vi.spyOn(window, 'open').mockReturnValue(null);
        document.body.innerHTML = `
          <a data-action="open-window" href="http://example.com/link">Open</a>
        `;

        const link = document.querySelector('[data-action="open-window"]') as HTMLElement;
        link.dispatchEvent(new Event('click', { bubbles: true }));

        expect(openSpy).toHaveBeenCalledWith('http://example.com/link', '_blank');
      });
    });

    describe('data-action="know-all"', () => {
      it('calls API to mark all well-known and updates DOM', async () => {
        answerConfirms(true);
        const mockWords = [
          { wid: 1, hex: 'abc', term: 'word1', status: 99 },
          { wid: 2, hex: 'def', term: 'word2', status: 99 }
        ];
        const fetchSpy = vi.spyOn(globalThis, 'fetch').mockResolvedValue({
          ok: true,
          json: () => Promise.resolve({ count: 2, words: mockWords })
        } as Response);

        document.body.innerHTML = `
          <button data-action="know-all" data-text-id="42">Know All</button>
        `;

        const button = document.querySelector('[data-action="know-all"]') as HTMLElement;
        button.dispatchEvent(new Event('click', { bubbles: true }));

        await settle();
        expect(confirmPrompts).toHaveBeenCalledWith('Mark all unknown words as Well Known?');
        expect(fetchSpy).toHaveBeenCalledWith('/api/v1/texts/42/mark-all-wellknown', {
          method: 'PUT',
          headers: { 'Content-Type': 'application/json' }
        });

        // Wait for async operations
        await vi.waitFor(() => {
          expect(markWordWellKnownInDOM).toHaveBeenCalledWith(1, 'abc', 'word1');
          expect(markWordWellKnownInDOM).toHaveBeenCalledWith(2, 'def', 'word2');
        });

        fetchSpy.mockRestore();
      });

      it('does nothing if cancelled', async () => {
        answerConfirms(false);
        const fetchSpy = vi.spyOn(globalThis, 'fetch');
        document.body.innerHTML = `
          <button data-action="know-all" data-text-id="42">Know All</button>
        `;

        const button = document.querySelector('[data-action="know-all"]') as HTMLElement;
        button.dispatchEvent(new Event('click', { bubbles: true }));

        await settle();
        expect(fetchSpy).not.toHaveBeenCalled();
        fetchSpy.mockRestore();
      });
    });

    describe('data-action="ignore-all"', () => {
      it('calls API to mark all ignored and updates DOM', async () => {
        answerConfirms(true);
        const mockWords = [
          { wid: 3, hex: 'ghi', term: 'word3', status: 98 }
        ];
        const fetchSpy = vi.spyOn(globalThis, 'fetch').mockResolvedValue({
          ok: true,
          json: () => Promise.resolve({ count: 1, words: mockWords })
        } as Response);

        document.body.innerHTML = `
          <button data-action="ignore-all" data-text-id="42">Ignore All</button>
        `;

        const button = document.querySelector('[data-action="ignore-all"]') as HTMLElement;
        button.dispatchEvent(new Event('click', { bubbles: true }));

        await settle();
        expect(confirmPrompts).toHaveBeenCalledWith('Mark all unknown words as Ignored?');
        expect(fetchSpy).toHaveBeenCalledWith('/api/v1/texts/42/mark-all-ignored', {
          method: 'PUT',
          headers: { 'Content-Type': 'application/json' }
        });

        // Wait for async operations
        await vi.waitFor(() => {
          expect(markWordIgnoredInDOM).toHaveBeenCalledWith(3, 'ghi', 'word3');
        });

        fetchSpy.mockRestore();
      });
    });

    describe('data-action="bulk-translate"', () => {
      it('navigates to bulk translate URL', () => {
        document.body.innerHTML = `
          <button data-action="bulk-translate" data-url="/bulk/translate">Bulk</button>
        `;

        const button = document.querySelector('[data-action="bulk-translate"]') as HTMLElement;
        button.dispatchEvent(new Event('click', { bubbles: true }));

        expect(window.location.href).toBe('/bulk/translate');
      });
    });

    describe('data-action="change-test-status"', () => {
      it('changes status up', () => {
        document.body.innerHTML = `
          <button data-action="change-test-status" data-word-id="123" data-direction="up">+</button>
        `;

        const button = document.querySelector('[data-action="change-test-status"]') as HTMLElement;
        button.dispatchEvent(new Event('click', { bubbles: true }));

        expect(changeTableTestStatus).toHaveBeenCalledWith('123', true);
      });

      it('changes status down', () => {
        document.body.innerHTML = `
          <button data-action="change-test-status" data-word-id="123" data-direction="down">-</button>
        `;

        const button = document.querySelector('[data-action="change-test-status"]') as HTMLElement;
        button.dispatchEvent(new Event('click', { bubbles: true }));

        expect(changeTableTestStatus).toHaveBeenCalledWith('123', false);
      });
    });

    describe('data-action="go-back"', () => {
      it('navigates back in history', () => {
        document.body.innerHTML = `
          <button data-action="go-back">Back</button>
        `;

        const button = document.querySelector('[data-action="go-back"]') as HTMLElement;
        button.dispatchEvent(new Event('click', { bubbles: true }));

        expect(window.history.back).toHaveBeenCalled();
      });
    });

    describe('data-action="show-export-template-help"', () => {
      it('shows export template help modal', () => {
        document.body.innerHTML = `
          <button data-action="show-export-template-help">Help</button>
        `;

        const button = document.querySelector('[data-action="show-export-template-help"]') as HTMLElement;
        button.dispatchEvent(new Event('click', { bubbles: true }));

        expect(showExportTemplateHelp).toHaveBeenCalled();
      });
    });

    describe('pager navigation', () => {
      it('navigates to selected page', () => {
        document.body.innerHTML = `
          <select data-action="pager-navigate" data-base-url="/page">
            <option value="1">1</option>
            <option value="2">2</option>
            <option value="3">3</option>
          </select>
        `;

        const select = document.querySelector('select') as HTMLSelectElement;
        select.value = '2';
        select.dispatchEvent(new Event('change', { bubbles: true }));

        expect(window.location.href).toBe('/page?page=2');
      });
    });

    describe('quick menu navigation', () => {
      it('calls quickMenuRedirection', () => {
        document.body.innerHTML = `
          <select data-action="quick-menu-redirect">
            <option value="">Select</option>
            <option value="option1">Option 1</option>
          </select>
        `;

        const select = document.querySelector('select') as HTMLSelectElement;
        select.value = 'option1';
        select.dispatchEvent(new Event('change', { bubbles: true }));

        expect(quickMenuRedirection).toHaveBeenCalledWith('option1');
      });
    });

    describe('auto-submit button', () => {
      it('clicks the named button on form submit', () => {
        document.body.innerHTML = `
          <form data-auto-submit-button="customBtn">
            <button type="button" name="customBtn">Custom</button>
            <button type="submit">Submit</button>
          </form>
        `;

        const form = document.querySelector('form')!;
        const customBtn = document.querySelector('[name="customBtn"]') as HTMLButtonElement;
        const clickSpy = vi.spyOn(customBtn, 'click');

        const event = new Event('submit', { bubbles: true, cancelable: true });
        form.dispatchEvent(event);

        expect(clickSpy).toHaveBeenCalled();
        expect(event.defaultPrevented).toBe(true);
      });
    });
  });
});
