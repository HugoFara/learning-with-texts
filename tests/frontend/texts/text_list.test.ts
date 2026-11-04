/**
 * Tests for text_list.ts - Text list page interactions
 */
import { settle } from '../helpers/confirm_answers';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { initTextList } from '../../../src/frontend/js/modules/text/pages/text_list';

// Mock dependencies
vi.mock('../../../src/frontend/js/modules/language/stores/language_settings', () => ({
  setLang: vi.fn(),
  resetAll: vi.fn()
}));

vi.mock('../../../src/frontend/js/shared/forms/bulk_actions', () => ({
  selectToggle: vi.fn(),
  multiActionGo: vi.fn()
}));

vi.mock('../../../src/frontend/js/shared/utils/ui_utilities', () => ({
  confirmDelete: vi.fn().mockReturnValue(true)
}));

import { setLang, resetAll } from '../../../src/frontend/js/modules/language/stores/language_settings';
import { selectToggle, multiActionGo } from '../../../src/frontend/js/shared/forms/bulk_actions';
import { confirmDelete } from '../../../src/frontend/js/shared/utils/ui_utilities';

describe('text_list.ts', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
    vi.clearAllMocks();

    // Mock location
    Object.defineProperty(window, 'location', {
      value: {
        href: '',
        pathname: '/texts'
      },
      writable: true
    });
  });

  afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
  });

  // ===========================================================================
  // initTextList Tests
  // ===========================================================================

  describe('initTextList', () => {
    it('does nothing when form1 does not exist', () => {
      expect(() => initTextList()).not.toThrow();
    });

    it('initializes when form1 exists', () => {
      document.body.innerHTML = `
        <form name="form1">
          <input type="text" name="query" />
        </form>
      `;

      expect(() => initTextList()).not.toThrow();
    });

    it('prevents form submission and triggers filter button', () => {
      document.body.innerHTML = `
        <form name="form1">
          <input type="text" name="query" value="test" />
          <button data-action="filter">Filter</button>
        </form>
      `;

      initTextList();

      const form = document.querySelector<HTMLFormElement>('form[name="form1"]')!;
      const event = new Event('submit', { cancelable: true });
      const result = form.dispatchEvent(event);

      // Form submission should be prevented
      expect(result).toBe(false);
    });
  });

  // ===========================================================================
  // Language Filter Tests
  // ===========================================================================

  describe('Language Filter', () => {
    it('calls setLang on language filter change', () => {
      document.body.innerHTML = `
        <form name="form1">
          <select data-action="filter-language">
            <option value="1">English</option>
            <option value="2">French</option>
          </select>
        </form>
      `;

      initTextList();

      const select = document.querySelector<HTMLSelectElement>('[data-action="filter-language"]')!;
      select.value = '2';
      // Use bubbling event to trigger delegated handler
      select.dispatchEvent(new Event('change', { bubbles: true }));

      expect(setLang).toHaveBeenCalledWith(select, '/texts');
    });

    it('uses correct base URL from data attribute', () => {
      document.body.innerHTML = `
        <form name="form1" data-base-url="/text/archived">
          <select data-action="filter-language">
            <option value="1">English</option>
          </select>
        </form>
      `;

      initTextList();

      const select = document.querySelector<HTMLSelectElement>('[data-action="filter-language"]')!;
      select.dispatchEvent(new Event('change', { bubbles: true }));

      expect(setLang).toHaveBeenCalledWith(select, '/text/archived');
    });
  });

  // ===========================================================================
  // Query Mode Filter Tests
  // ===========================================================================

  describe('Query Mode Filter', () => {
    it('navigates with query and mode on mode change', () => {
      document.body.innerHTML = `
        <form name="form1">
          <input type="text" name="query" value="search term" />
          <select data-action="filter-query-mode">
            <option value="title">Title</option>
            <option value="text">Text</option>
          </select>
        </form>
      `;

      initTextList();

      const select = document.querySelector<HTMLSelectElement>('[data-action="filter-query-mode"]')!;
      select.value = 'text';
      select.dispatchEvent(new Event('change', { bubbles: true }));

      expect(window.location.href).toBe('/texts?page=1&query=search%20term&query_mode=text');
    });

    it('handles empty query input', () => {
      document.body.innerHTML = `
        <form name="form1">
          <input type="text" name="query" value="" />
          <select data-action="filter-query-mode">
            <option value="title">Title</option>
          </select>
        </form>
      `;

      initTextList();

      const select = document.querySelector<HTMLSelectElement>('[data-action="filter-query-mode"]')!;
      select.dispatchEvent(new Event('change', { bubbles: true }));

      expect(window.location.href).toBe('/texts?page=1&query=&query_mode=title');
    });
  });

  // ===========================================================================
  // Filter Button Tests
  // ===========================================================================

  describe('Filter Button', () => {
    it('navigates with query on filter button click', () => {
      document.body.innerHTML = `
        <form name="form1">
          <input type="text" name="query" value="my search" />
          <button data-action="filter">Filter</button>
        </form>
      `;

      initTextList();

      const button = document.querySelector<HTMLButtonElement>('[data-action="filter"]')!;
      button.click();

      expect(window.location.href).toBe('/texts?page=1&query=my%20search');
    });

    it('handles special characters in query', () => {
      document.body.innerHTML = `
        <form name="form1">
          <input type="text" name="query" value="test&value=123" />
          <button data-action="filter">Filter</button>
        </form>
      `;

      initTextList();

      const button = document.querySelector<HTMLButtonElement>('[data-action="filter"]')!;
      button.click();

      expect(window.location.href).toContain('query=test%26value%3D123');
    });
  });

  // ===========================================================================
  // Clear Filter Tests
  // ===========================================================================

  describe('Clear Filter', () => {
    it('clears query on clear button click', () => {
      document.body.innerHTML = `
        <form name="form1">
          <input type="text" name="query" value="existing" />
          <button data-action="clear-filter">Clear</button>
        </form>
      `;

      initTextList();

      const button = document.querySelector<HTMLButtonElement>('[data-action="clear-filter"]')!;
      button.click();

      expect(window.location.href).toBe('/texts?page=1&query=');
    });
  });

  // ===========================================================================
  // Tag Filter Tests
  // ===========================================================================

  describe('Tag Filter', () => {
    it('navigates with tag1 on tag select change', () => {
      document.body.innerHTML = `
        <form name="form1">
          <select data-action="filter-tag" data-tag-num="1">
            <option value="0">All</option>
            <option value="5">Important</option>
          </select>
        </form>
      `;

      initTextList();

      const select = document.querySelector<HTMLSelectElement>('[data-action="filter-tag"]')!;
      select.value = '5';
      select.dispatchEvent(new Event('change', { bubbles: true }));

      expect(window.location.href).toBe('/texts?page=1&tag1=5');
    });

    it('navigates with tag2 on second tag select change', () => {
      document.body.innerHTML = `
        <form name="form1">
          <select data-action="filter-tag" data-tag-num="2">
            <option value="0">All</option>
            <option value="3">Review</option>
          </select>
        </form>
      `;

      initTextList();

      const select = document.querySelector<HTMLSelectElement>('[data-action="filter-tag"]')!;
      select.value = '3';
      select.dispatchEvent(new Event('change', { bubbles: true }));

      expect(window.location.href).toBe('/texts?page=1&tag2=3');
    });

    it('defaults to tag1 when data-tag-num is missing', () => {
      document.body.innerHTML = `
        <form name="form1">
          <select data-action="filter-tag">
            <option value="0">All</option>
            <option value="7">Tag7</option>
          </select>
        </form>
      `;

      initTextList();

      const select = document.querySelector<HTMLSelectElement>('[data-action="filter-tag"]')!;
      select.value = '7';
      select.dispatchEvent(new Event('change', { bubbles: true }));

      expect(window.location.href).toBe('/texts?page=1&tag1=7');
    });
  });

  // ===========================================================================
  // Tag Operator Tests
  // ===========================================================================

  describe('Tag Operator', () => {
    it('navigates with tag12 on operator change', () => {
      document.body.innerHTML = `
        <form name="form1">
          <select data-action="filter-tag-operator">
            <option value="0">AND</option>
            <option value="1">OR</option>
          </select>
        </form>
      `;

      initTextList();

      const select = document.querySelector<HTMLSelectElement>('[data-action="filter-tag-operator"]')!;
      select.value = '1';
      select.dispatchEvent(new Event('change', { bubbles: true }));

      expect(window.location.href).toBe('/texts?page=1&tag12=1');
    });
  });

  // ===========================================================================
  // Sort Order Tests
  // ===========================================================================

  describe('Sort Order', () => {
    it('navigates with sort on sort select change', () => {
      document.body.innerHTML = `
        <form name="form1">
          <select data-action="sort">
            <option value="1">Title A-Z</option>
            <option value="2">Title Z-A</option>
          </select>
        </form>
      `;

      initTextList();

      const select = document.querySelector<HTMLSelectElement>('[data-action="sort"]')!;
      select.value = '2';
      select.dispatchEvent(new Event('change', { bubbles: true }));

      expect(window.location.href).toBe('/texts?page=1&sort=2');
    });
  });

  // ===========================================================================
  // Reset All Tests
  // ===========================================================================

  describe('Reset All', () => {
    it('calls resetAll on reset button click', () => {
      document.body.innerHTML = `
        <form name="form1">
          <button data-action="reset-all">Reset</button>
        </form>
      `;

      initTextList();

      const button = document.querySelector<HTMLButtonElement>('[data-action="reset-all"]')!;
      button.click();

      expect(resetAll).toHaveBeenCalledWith('/texts');
    });
  });

  // ===========================================================================
  // Delete Confirmation Tests
  // ===========================================================================

  describe('Delete Confirmation', () => {
    it('navigates to delete URL when confirmed', async () => {
      document.body.innerHTML = `
        <form name="form1">
          <a data-action="confirm-delete" data-url="/texts/delete/5">Delete</a>
        </form>
      `;

      (confirmDelete as any).mockResolvedValue(true);

      initTextList();

      const link = document.querySelector<HTMLElement>('[data-action="confirm-delete"]')!;
      link.click();

      await settle();
      expect(confirmDelete).toHaveBeenCalled();
      expect(window.location.href).toBe('/texts/delete/5');
    });

    it('does not navigate when delete is cancelled', () => {
      document.body.innerHTML = `
        <form name="form1">
          <a data-action="confirm-delete" data-url="/texts/delete/5">Delete</a>
        </form>
      `;

      (confirmDelete as any).mockReturnValue(false);

      initTextList();

      const link = document.querySelector<HTMLElement>('[data-action="confirm-delete"]')!;
      link.click();

      expect(confirmDelete).toHaveBeenCalled();
      expect(window.location.href).toBe('');
    });

    it('does not navigate when data-url is missing', () => {
      document.body.innerHTML = `
        <form name="form1">
          <a data-action="confirm-delete">Delete</a>
        </form>
      `;

      (confirmDelete as any).mockReturnValue(true);

      initTextList();

      const link = document.querySelector<HTMLElement>('[data-action="confirm-delete"]')!;
      link.click();

      expect(window.location.href).toBe('');
    });
  });

  // ===========================================================================
  // Mark Toggle Tests
  // ===========================================================================

  describe('Mark Toggle', () => {
    it('calls selectToggle with true for mark all', () => {
      document.body.innerHTML = `
        <form name="form1"></form>
        <form name="form2">
          <button data-action="mark-toggle" data-mark-all="true">Mark All</button>
        </form>
      `;

      initTextList();

      const button = document.querySelector<HTMLButtonElement>('[data-action="mark-toggle"]')!;
      button.click();

      expect(selectToggle).toHaveBeenCalledWith(true, 'form2');
    });

    it('calls selectToggle with false for mark none', () => {
      document.body.innerHTML = `
        <form name="form1"></form>
        <form name="form2">
          <button data-action="mark-toggle" data-mark-all="false">Mark None</button>
        </form>
      `;

      initTextList();

      const button = document.querySelector<HTMLButtonElement>('[data-action="mark-toggle"]')!;
      button.click();

      expect(selectToggle).toHaveBeenCalledWith(false, 'form2');
    });
  });

  // ===========================================================================
  // Multi-Action Tests
  // ===========================================================================

  describe('Multi-Action', () => {
    it('calls multiActionGo on action select change', () => {
      document.body.innerHTML = `
        <form name="form1"></form>
        <form name="form2">
          <select data-action="multi-action">
            <option value="">Select action</option>
            <option value="delete">Delete</option>
          </select>
        </form>
      `;

      initTextList();

      const select = document.querySelector<HTMLSelectElement>('[data-action="multi-action"]')!;
      const form = document.querySelector<HTMLFormElement>('form[name="form2"]')!;
      select.value = 'delete';
      select.dispatchEvent(new Event('change', { bubbles: true }));

      expect(multiActionGo).toHaveBeenCalledWith(form, select);
    });
  });

  // ===========================================================================
  // Base URL Detection Tests
  // ===========================================================================

  describe('Base URL Detection', () => {
    it('detects /texts from pathname', () => {
      Object.defineProperty(window, 'location', {
        value: { href: '', pathname: '/texts' },
        writable: true
      });

      document.body.innerHTML = `
        <form name="form1">
          <button data-action="filter">Filter</button>
          <input type="text" name="query" value="test" />
        </form>
      `;

      initTextList();

      const button = document.querySelector<HTMLButtonElement>('[data-action="filter"]')!;
      button.click();

      expect(window.location.href).toContain('/texts?');
    });

    it('detects /text/archived from pathname', () => {
      Object.defineProperty(window, 'location', {
        value: { href: '', pathname: '/text/archived' },
        writable: true
      });

      document.body.innerHTML = `
        <form name="form1">
          <button data-action="filter">Filter</button>
          <input type="text" name="query" value="test" />
        </form>
      `;

      initTextList();

      const button = document.querySelector<HTMLButtonElement>('[data-action="filter"]')!;
      button.click();

      expect(window.location.href).toContain('/text/archived?');
    });
  });

  // ===========================================================================
  // Edge Cases
  // ===========================================================================

  describe('Edge Cases', () => {
    it('handles missing query input', () => {
      document.body.innerHTML = `
        <form name="form1">
          <button data-action="filter">Filter</button>
        </form>
      `;

      initTextList();

      const button = document.querySelector<HTMLButtonElement>('[data-action="filter"]')!;
      button.click();

      expect(window.location.href).toBe('/texts?page=1&query=');
    });

    it('handles missing form2 for multi-action', () => {
      document.body.innerHTML = `
        <form name="form1">
          <select data-action="multi-action">
            <option value="delete">Delete</option>
          </select>
        </form>
      `;

      initTextList();

      const select = document.querySelector<HTMLSelectElement>('[data-action="multi-action"]')!;

      // Should not throw when form2 is missing
      expect(() => {
        select.dispatchEvent(new Event('change'));
      }).not.toThrow();
    });

    it('handles Unicode query values', () => {
      document.body.innerHTML = `
        <form name="form1">
          <input type="text" name="query" value="日本語テスト" />
          <button data-action="filter">Filter</button>
        </form>
      `;

      initTextList();

      const button = document.querySelector<HTMLButtonElement>('[data-action="filter"]')!;
      button.click();

      expect(window.location.href).toContain(encodeURIComponent('日本語テスト'));
    });
  });
});
