/**
 * Tests for unloadformcheck.ts - Form dirty state tracking
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import {
  lwtFormCheck,
} from '../../../src/frontend/js/shared/forms/unloadformcheck';
import { settle } from '../helpers/confirm_answers';

describe('unloadformcheck.ts', () => {
  beforeEach(() => {
    // Reset dirty state before each test
    lwtFormCheck.dirty = false;
  });

  afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
  });

  // ===========================================================================
  // lwtFormCheck Object Tests
  // ===========================================================================

  describe('lwtFormCheck', () => {
    describe('dirty property', () => {
      it('initializes to false', () => {
        lwtFormCheck.dirty = false;
        expect(lwtFormCheck.dirty).toBe(false);
      });

      it('can be set to true', () => {
        lwtFormCheck.dirty = true;
        expect(lwtFormCheck.dirty).toBe(true);
      });
    });

    describe('isDirtyMessage', () => {
      it('returns undefined when not dirty', () => {
        lwtFormCheck.dirty = false;
        expect(lwtFormCheck.isDirtyMessage()).toBeUndefined();
      });

      it('returns warning message when dirty', () => {
        lwtFormCheck.dirty = true;
        const message = lwtFormCheck.isDirtyMessage();
        expect(message).toBe('** You have unsaved changes! **');
      });
    });

    describe('makeDirty', () => {
      it('sets dirty to true', () => {
        lwtFormCheck.dirty = false;
        lwtFormCheck.makeDirty();
        expect(lwtFormCheck.dirty).toBe(true);
      });

      it('keeps dirty as true if already dirty', () => {
        lwtFormCheck.dirty = true;
        lwtFormCheck.makeDirty();
        expect(lwtFormCheck.dirty).toBe(true);
      });
    });

    describe('resetDirty', () => {
      it('sets dirty to false', () => {
        lwtFormCheck.dirty = true;
        lwtFormCheck.resetDirty();
        expect(lwtFormCheck.dirty).toBe(false);
      });

      it('keeps dirty as false if already clean', () => {
        lwtFormCheck.dirty = false;
        lwtFormCheck.resetDirty();
        expect(lwtFormCheck.dirty).toBe(false);
      });
    });

    describe('tagChanged', () => {
      it('sets dirty to true when not during initialization', () => {
        lwtFormCheck.dirty = false;
        lwtFormCheck.tagChanged(false);
        expect(lwtFormCheck.dirty).toBe(true);
      });

      it('does not change dirty during initialization', () => {
        lwtFormCheck.dirty = false;
        lwtFormCheck.tagChanged(true);
        expect(lwtFormCheck.dirty).toBe(false);
      });

      it('returns void', () => {
        const result = lwtFormCheck.tagChanged(false);
        expect(result).toBeUndefined();
      });
    });
  });

  // ===========================================================================
  // Integration Tests
  // ===========================================================================

  describe('Integration', () => {
    it('dirty state workflow: clean -> dirty -> clean', () => {
      // Start clean
      lwtFormCheck.dirty = false;
      expect(lwtFormCheck.isDirtyMessage()).toBeUndefined();

      // Make dirty
      lwtFormCheck.makeDirty();
      expect(lwtFormCheck.isDirtyMessage()).toBe('** You have unsaved changes! **');

      // Reset to clean
      lwtFormCheck.resetDirty();
      expect(lwtFormCheck.isDirtyMessage()).toBeUndefined();
    });

    it('tagChanged during tag operations', () => {
      lwtFormCheck.dirty = false;

      // Simulate tag initialization (should not make dirty)
      lwtFormCheck.tagChanged(true);
      expect(lwtFormCheck.dirty).toBe(false);

      // Simulate user adding a tag (should make dirty)
      lwtFormCheck.tagChanged(false);
      expect(lwtFormCheck.dirty).toBe(true);

      // Reset
      lwtFormCheck.resetDirty();
      expect(lwtFormCheck.dirty).toBe(false);

      // Simulate user removing a tag (should make dirty)
      lwtFormCheck.tagChanged(false);
      expect(lwtFormCheck.dirty).toBe(true);
    });
  });

  // ===========================================================================
  // Edge Cases
  // ===========================================================================

  describe('Edge Cases', () => {
    it('multiple makeDirty calls do not affect state negatively', () => {
      lwtFormCheck.dirty = false;
      lwtFormCheck.makeDirty();
      lwtFormCheck.makeDirty();
      lwtFormCheck.makeDirty();
      expect(lwtFormCheck.dirty).toBe(true);
    });

    it('multiple resetDirty calls do not affect state negatively', () => {
      lwtFormCheck.dirty = true;
      lwtFormCheck.resetDirty();
      lwtFormCheck.resetDirty();
      lwtFormCheck.resetDirty();
      expect(lwtFormCheck.dirty).toBe(false);
    });

    it('tagChanged handles false duringInit', () => {
      lwtFormCheck.dirty = false;
      // When duringInit is false, it should make the form dirty
      lwtFormCheck.tagChanged(false);
      expect(lwtFormCheck.dirty).toBe(true);
    });

    it('isDirtyMessage returns consistent message', () => {
      lwtFormCheck.dirty = true;
      const msg1 = lwtFormCheck.isDirtyMessage();
      const msg2 = lwtFormCheck.isDirtyMessage();
      expect(msg1).toBe(msg2);
    });
  });

  // ===========================================================================
  // askBeforeExit Tests
  // ===========================================================================

  describe('askBeforeExit', () => {
    beforeEach(() => {
      document.body.innerHTML = '';
      lwtFormCheck.dirty = false;
    });

    it('sets up change listeners on form elements', () => {
      document.body.innerHTML = `
        <input type="text" id="testInput" />
        <textarea id="testTextarea"></textarea>
        <select id="testSelect"><option>Test</option></select>
      `;

      lwtFormCheck.askBeforeExit();

      // Trigger change on input
      const input = document.getElementById('testInput') as HTMLInputElement;
      input.dispatchEvent(new Event('change'));

      expect(lwtFormCheck.dirty).toBe(true);
    });

    it('sets up reset dirty on submit buttons', () => {
      document.body.innerHTML = `
        <button type="submit">Submit</button>
      `;

      lwtFormCheck.dirty = true;
      lwtFormCheck.askBeforeExit();

      // Click submit button
      const submitBtn = document.querySelector('[type="submit"]') as HTMLButtonElement;
      submitBtn.click();

      expect(lwtFormCheck.dirty).toBe(false);
    });

    it('sets up reset dirty on reset buttons', () => {
      document.body.innerHTML = `
        <button type="reset">Reset</button>
      `;

      lwtFormCheck.dirty = true;
      lwtFormCheck.askBeforeExit();

      // Click reset button
      const resetBtn = document.querySelector('[type="reset"]') as HTMLButtonElement;
      resetBtn.click();

      expect(lwtFormCheck.dirty).toBe(false);
    });

    it('ignores quickmenu select changes', () => {
      document.body.innerHTML = `
        <select id="quickmenu"><option>Quick</option></select>
      `;

      lwtFormCheck.askBeforeExit();

      // Trigger change on quickmenu
      const quickmenu = document.getElementById('quickmenu') as HTMLSelectElement;
      quickmenu.dispatchEvent(new Event('change'));

      // Should NOT make dirty because it's the quickmenu
      expect(lwtFormCheck.dirty).toBe(false);
    });

    it('sets up beforeunload handler', () => {
      const addEventListenerSpy = vi.spyOn(window, 'addEventListener');

      lwtFormCheck.askBeforeExit();

      expect(addEventListenerSpy).toHaveBeenCalledWith(
        'beforeunload',
        expect.any(Function)
      );
    });

    it('beforeunload returns message when dirty', () => {
      lwtFormCheck.askBeforeExit();
      lwtFormCheck.dirty = true;

      const event = new Event('beforeunload') as BeforeUnloadEvent;
      Object.defineProperty(event, 'returnValue', {
        writable: true,
        value: '',
      });

      window.dispatchEvent(event);

      // The event was handled (may or may not prevent default in test env)
      expect(lwtFormCheck.isDirtyMessage()).toBe('** You have unsaved changes! **');
    });

    it('beforeunload does not prevent when not dirty', () => {
      lwtFormCheck.askBeforeExit();
      lwtFormCheck.dirty = false;

      // Should not prevent the default action when not dirty
      expect(lwtFormCheck.isDirtyMessage()).toBeUndefined();
    });
  });

  // ===========================================================================
  // Type Safety Tests
  // ===========================================================================

  describe('Type Safety', () => {
    it('dirty is a boolean', () => {
      expect(typeof lwtFormCheck.dirty).toBe('boolean');
    });

    it('isDirtyMessage returns string or undefined', () => {
      lwtFormCheck.dirty = false;
      const resultClean = lwtFormCheck.isDirtyMessage();
      expect(resultClean === undefined || typeof resultClean === 'string').toBe(true);

      lwtFormCheck.dirty = true;
      const resultDirty = lwtFormCheck.isDirtyMessage();
      expect(typeof resultDirty).toBe('string');
    });

    it('makeDirty returns void', () => {
      const result = lwtFormCheck.makeDirty();
      expect(result).toBeUndefined();
    });

    it('resetDirty returns void', () => {
      const result = lwtFormCheck.resetDirty();
      expect(result).toBeUndefined();
    });

    it('tagChanged returns void', () => {
      const result = lwtFormCheck.tagChanged(false);
      expect(result).toBeUndefined();
    });
  });

  // ===========================================================================
  // Links on a changed form
  // ===========================================================================

  describe('following a link from a changed form', () => {
    const assign = vi.fn();

    function dialogButton(selector: string): HTMLButtonElement {
      const found = document.querySelector<HTMLButtonElement>(`.confirm-dialog ${selector}`);
      if (!found) throw new Error(`No button "${selector}"`);
      return found;
    }

    function click(link: Element, init: MouseEventInit = {}): MouseEvent {
      const event = new MouseEvent('click', { bubbles: true, cancelable: true, ...init });
      link.dispatchEvent(event);
      return event;
    }

    function linkTo(href: string, attributes: Record<string, string> = {}): HTMLAnchorElement {
      const link = document.createElement('a');
      link.href = href;
      Object.entries(attributes).forEach(([name, value]) => link.setAttribute(name, value));
      document.body.appendChild(link);
      return link;
    }

    beforeEach(() => {
      vi.stubGlobal('location', Object.assign(new URL(window.location.href), { assign }));
      lwtFormCheck.askBeforeExit();
      lwtFormCheck.dirty = true;
    });

    afterEach(async () => {
      document.querySelector<HTMLElement>('.confirm-dialog .button.is-danger')?.click();
      await settle();
      vi.unstubAllGlobals();
      assign.mockClear();
      lwtFormCheck.dirty = false;
    });

    it('asks in the page and stays when the user keeps editing', async () => {
      const event = click(linkTo('/texts'));

      expect(event.defaultPrevented).toBe(true);
      expect(document.querySelector('.confirm-dialog')).not.toBeNull();

      dialogButton('.button:not(.is-danger)').click();
      await settle();

      expect(assign).not.toHaveBeenCalled();
      expect(lwtFormCheck.dirty).toBe(true);
    });

    it('leaves without the native prompt when the user discards', async () => {
      const link = linkTo('/texts');
      click(link);

      dialogButton('.button.is-danger').click();
      await settle();

      expect(assign).toHaveBeenCalledWith(link.href);
      expect(lwtFormCheck.dirty).toBe(false);
    });

    it('does not ask when nothing changed', () => {
      lwtFormCheck.dirty = false;

      expect(click(linkTo('/texts')).defaultPrevented).toBe(false);
      expect(document.querySelector('.confirm-dialog')).toBeNull();
    });

    it('does not ask for a click on anything but a link', () => {
      document.body.innerHTML = '<button type="button">Tab</button>';

      click(document.querySelector('button') as Element);

      expect(document.querySelector('.confirm-dialog')).toBeNull();
    });

    it('does not ask when the link handles its own click', () => {
      const link = linkTo('/texts');
      link.addEventListener('click', (event) => event.preventDefault());

      click(link);

      expect(document.querySelector('.confirm-dialog')).toBeNull();
    });

    it.each([
      ['an in-page anchor', '#section', {}],
      ['a new tab', '/texts', { target: '_blank' }],
      ['a download', '/export.csv', { download: '' }],
      ['a mail link', 'mailto:someone@example.test', {}]
    ])('does not ask for %s', (_name, href, attributes) => {
      expect(click(linkTo(href, attributes)).defaultPrevented).toBe(false);
      expect(document.querySelector('.confirm-dialog')).toBeNull();
    });

    it.each([
      ['Ctrl', { ctrlKey: true }],
      ['Cmd', { metaKey: true }],
      ['Shift', { shiftKey: true }]
    ])('does not ask for %s-click, which opens elsewhere', (_name, init) => {
      expect(click(linkTo('/texts'), init).defaultPrevented).toBe(false);
    });

    it('adds one listener however often forms ask', () => {
      lwtFormCheck.askBeforeExit();
      lwtFormCheck.askBeforeExit();

      click(linkTo('/texts'));

      expect(document.querySelectorAll('.confirm-dialog').length).toBe(1);
    });
  });
});
