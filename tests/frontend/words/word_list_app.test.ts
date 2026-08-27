/**
 * Tests for words/word_list_app.ts - Word List Alpine.js component
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { wordListData, initWordListAlpine } from '../../../src/frontend/js/modules/vocabulary/pages/word_list_app';

// Mock the dependencies
vi.mock('../../../src/frontend/js/modules/vocabulary/api/words_api', () => ({
  WordsApi: {
    getList: vi.fn(),
    getFilterOptions: vi.fn(),
    bulkAction: vi.fn(),
    allAction: vi.fn(),
    inlineEdit: vi.fn(),
  },
}));

// Mock lucide with all required icons
vi.mock('lucide', async () => {
  const { lucideMock } = await import('../helpers/lucide_mock');
  return lucideMock;
});

import { WordsApi } from '../../../src/frontend/js/modules/vocabulary/api/words_api';

describe('words/word_list_app.ts', () => {
  let originalLocation: Location;
  let originalLocalStorage: Storage;

  beforeEach(() => {
    document.body.innerHTML = '';
    vi.clearAllMocks();
    vi.useFakeTimers();

    // Mock window.location
    originalLocation = window.location;
    delete (window as { location?: Location }).location;
    window.location = {
      href: '',
      search: '',
    } as Location;

    // Mock localStorage
    originalLocalStorage = window.localStorage;
    const localStorageMock: Storage = {
      getItem: vi.fn(),
      setItem: vi.fn(),
      removeItem: vi.fn(),
      clear: vi.fn(),
      length: 0,
      key: vi.fn(),
    };
    Object.defineProperty(window, 'localStorage', { value: localStorageMock });

    // Mock alert and confirm
    vi.spyOn(window, 'alert').mockImplementation(() => {});
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    vi.spyOn(window, 'prompt').mockReturnValue('test-tag');
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {});

    // Set default mock return values for API calls to prevent unhandled rejections
    vi.mocked(WordsApi.getList).mockResolvedValue({
      data: { words: [], pagination: { page: 1, per_page: 50, total: 0, total_pages: 0 } },
      error: undefined,
    });
    vi.mocked(WordsApi.getFilterOptions).mockResolvedValue({
      data: { languages: [], texts: [], tags: [], statuses: [], sorts: [] },
      error: undefined,
    });
  });

  afterEach(() => {
    vi.restoreAllMocks();
    vi.useRealTimers();
    document.body.innerHTML = '';
    window.location = originalLocation;
    Object.defineProperty(window, 'localStorage', { value: originalLocalStorage });
  });

  // ===========================================================================
  // wordListData Initialization Tests
  // ===========================================================================

  describe('wordListData initialization', () => {
    it('returns object with expected properties', () => {
      const data = wordListData();

      expect(data).toHaveProperty('loading');
      expect(data).toHaveProperty('words');
      expect(data).toHaveProperty('filters');
      expect(data).toHaveProperty('pagination');
      expect(data).toHaveProperty('filterOptions');
      expect(data).toHaveProperty('marked');
    });

    it('initializes loading as true', () => {
      const data = wordListData();

      expect(data.loading).toBe(true);
    });

    it('initializes words as empty array', () => {
      const data = wordListData();

      expect(data.words).toEqual([]);
    });

    it('initializes marked as empty Set', () => {
      const data = wordListData();

      expect(data.marked).toBeInstanceOf(Set);
      expect(data.marked.size).toBe(0);
    });

    it('initializes editingWord as null', () => {
      const data = wordListData();

      expect(data.editingWord).toBeNull();
    });

    it('initializes filters with default values', () => {
      const data = wordListData();

      expect(data.filters.status).toBe('');
      expect(data.filters.query).toBe('');
      expect(data.filters.query_mode).toBe('term,rom,transl');
      expect(data.filters.sort).toBe(1);
      expect(data.filters.page).toBe(1);
    });

    it('reads page config from DOM when available', () => {
      const config = document.createElement('script');
      config.id = 'word-list-config';
      config.type = 'application/json';
      config.textContent = JSON.stringify({ activeLanguageId: 5, perPage: 25 });
      document.body.appendChild(config);

      const data = wordListData();

      expect(data.filters.lang).toBe(5);
      expect(data.filters.per_page).toBe(25);
    });
  });

  // ===========================================================================
  // Selection Tests
  // ===========================================================================

  describe('Selection methods', () => {
    it('markAll adds all word IDs to marked set', () => {
      const data = wordListData();
      data.words = [
        { id: 1 } as never,
        { id: 2 } as never,
        { id: 3 } as never,
      ];

      data.markAll(true);

      expect(data.marked.size).toBe(3);
      expect(data.marked.has(1)).toBe(true);
      expect(data.marked.has(2)).toBe(true);
      expect(data.marked.has(3)).toBe(true);
    });

    it('markAll clears marked set when false', () => {
      const data = wordListData();
      data.marked.add(1);
      data.marked.add(2);

      data.markAll(false);

      expect(data.marked.size).toBe(0);
    });

    it('toggleMark adds word ID when true', () => {
      const data = wordListData();

      data.toggleMark(5, true);

      expect(data.marked.has(5)).toBe(true);
    });

    it('toggleMark removes word ID when false', () => {
      const data = wordListData();
      data.marked.add(5);

      data.toggleMark(5, false);

      expect(data.marked.has(5)).toBe(false);
    });

    it('isMarked returns true for marked words', () => {
      const data = wordListData();
      data.marked.add(10);

      expect(data.isMarked(10)).toBe(true);
    });

    it('isMarked returns false for unmarked words', () => {
      const data = wordListData();

      expect(data.isMarked(10)).toBe(false);
    });

    it('getMarkedIds returns array of marked IDs', () => {
      const data = wordListData();
      data.marked.add(1);
      data.marked.add(3);
      data.marked.add(5);

      const ids = data.getMarkedIds();

      expect(ids).toContain(1);
      expect(ids).toContain(3);
      expect(ids).toContain(5);
      expect(ids.length).toBe(3);
    });

    it('getMarkedCount returns count of marked words', () => {
      const data = wordListData();
      data.marked.add(1);
      data.marked.add(2);

      expect(data.getMarkedCount()).toBe(2);
    });
  });

  // ===========================================================================
  // Filter Tests
  // ===========================================================================

  describe('Filter methods', () => {
    it('setFilter updates filter value', () => {
      const data = wordListData();

      vi.mocked(WordsApi.getList).mockResolvedValue({
        data: { words: [], pagination: { page: 1, per_page: 50, total: 0, total_pages: 0 } },
        error: undefined,
      });

      data.setFilter('status', '1-3');

      expect(data.filters.status).toBe('1-3');
    });

    it('setFilter resets page to 1 for non-page filters', () => {
      const data = wordListData();
      data.filters.page = 5;

      vi.mocked(WordsApi.getList).mockResolvedValue({
        data: { words: [], pagination: { page: 1, per_page: 50, total: 0, total_pages: 0 } },
        error: undefined,
      });

      data.setFilter('status', '1');

      expect(data.filters.page).toBe(1);
    });

    it('setFilter clears marked set when filter changes', () => {
      const data = wordListData();
      data.marked.add(1);
      data.marked.add(2);

      vi.mocked(WordsApi.getList).mockResolvedValue({
        data: { words: [], pagination: { page: 1, per_page: 50, total: 0, total_pages: 0 } },
        error: undefined,
      });

      data.setFilter('lang', 2);

      expect(data.marked.size).toBe(0);
    });

    it('setFilter saves filter state', () => {
      const data = wordListData();

      vi.mocked(WordsApi.getList).mockResolvedValue({
        data: { words: [], pagination: { page: 1, per_page: 50, total: 0, total_pages: 0 } },
        error: undefined,
      });

      data.setFilter('query', 'test');

      expect(window.localStorage.setItem).toHaveBeenCalled();
    });

    it('resetFilters clears all filters', () => {
      const data = wordListData();
      data.filters.lang = 5;
      data.filters.status = '1';
      data.filters.query = 'test';

      vi.mocked(WordsApi.getList).mockResolvedValue({
        data: { words: [], pagination: { page: 1, per_page: 50, total: 0, total_pages: 0 } },
        error: undefined,
      });
      vi.mocked(WordsApi.getFilterOptions).mockResolvedValue({
        data: { languages: [], texts: [], tags: [], statuses: [], sorts: [] },
        error: undefined,
      });

      data.resetFilters();

      expect(data.filters.lang).toBeNull();
      expect(data.filters.status).toBe('');
      expect(data.filters.query).toBe('');
    });
  });

  // ===========================================================================
  // Pagination Tests
  // ===========================================================================

  describe('Pagination methods', () => {
    it('goToPage does nothing for page < 1', async () => {
      const data = wordListData();
      data.filters.page = 2;

      await data.goToPage(0);

      expect(data.filters.page).toBe(2);
    });

    it('goToPage does nothing for page > total_pages', async () => {
      const data = wordListData();
      data.pagination.total_pages = 5;
      data.filters.page = 3;

      await data.goToPage(10);

      expect(data.filters.page).toBe(3);
    });

    it('goToPage updates page and scrolls to top', async () => {
      const data = wordListData();
      data.pagination.total_pages = 10;

      vi.mocked(WordsApi.getList).mockResolvedValue({
        data: { words: [], pagination: { page: 3, per_page: 50, total: 150, total_pages: 10 } },
        error: undefined,
      });

      await data.goToPage(3);

      expect(window.scrollTo).toHaveBeenCalledWith({ top: 0, behavior: 'smooth' });
    });
  });

  // ===========================================================================
  // Inline Edit Tests
  // ===========================================================================

  describe('Inline edit methods', () => {
    it('startEdit sets editingWord state', () => {
      const data = wordListData();
      data.words = [
        { id: 1, translation: 'hello', romanization: 'hola' } as never,
      ];

      data.startEdit(1, 'translation');

      expect(data.editingWord).toEqual({ id: 1, field: 'translation' });
      expect(data.editValue).toBe('hello');
    });

    it('startEdit clears asterisk value', () => {
      const data = wordListData();
      data.words = [
        { id: 1, translation: '*', romanization: '' } as never,
      ];

      data.startEdit(1, 'translation');

      expect(data.editValue).toBe('');
    });

    it('cancelEdit clears editing state', () => {
      const data = wordListData();
      data.editingWord = { id: 1, field: 'translation' };
      data.editValue = 'test';

      data.cancelEdit();

      expect(data.editingWord).toBeNull();
      expect(data.editValue).toBe('');
    });

    it('isEditing returns true for current edit', () => {
      const data = wordListData();
      data.editingWord = { id: 5, field: 'translation' };

      expect(data.isEditing(5, 'translation')).toBe(true);
    });

    it('isEditing returns false for different word', () => {
      const data = wordListData();
      data.editingWord = { id: 5, field: 'translation' };

      expect(data.isEditing(6, 'translation')).toBe(false);
    });

    it('isEditing returns false for different field', () => {
      const data = wordListData();
      data.editingWord = { id: 5, field: 'translation' };

      expect(data.isEditing(5, 'romanization')).toBe(false);
    });

    it('saveEdit calls API and updates word', async () => {
      const data = wordListData();
      data.words = [
        { id: 1, translation: 'old', romanization: '' } as never,
      ];
      data.editingWord = { id: 1, field: 'translation' };
      data.editValue = 'new';

      vi.mocked(WordsApi.inlineEdit).mockResolvedValue({
        data: { success: true, value: 'new' },
        error: undefined,
      });

      await data.saveEdit();

      expect(WordsApi.inlineEdit).toHaveBeenCalledWith(1, 'translation', 'new');
      expect(data.editingWord).toBeNull();
    });
  });

  // ===========================================================================
  // Helper Methods Tests
  // ===========================================================================

  describe('Helper methods', () => {
    it('formatScore scales days until due', () => {
      const data = wordListData();

      expect(data.formatScore(3)).toBe('3d');
      expect(data.formatScore(75)).toBe('3mo');
      expect(data.formatScore(800)).toBe('2y');
    });

    it('formatScore reads an overdue term as due now', () => {
      const data = wordListData();

      expect(data.formatScore(0)).toBe('due');
      expect(data.formatScore(-10)).toBe('due');
    });

    it('getStatusClass returns is-info for status 99', () => {
      const data = wordListData();

      expect(data.getStatusClass(99)).toBe('is-info');
    });

    it('getStatusClass returns is-light for status 98', () => {
      const data = wordListData();

      expect(data.getStatusClass(98)).toBe('is-light');
    });

    it('getStatusClass returns is-success for status >= 5', () => {
      const data = wordListData();

      expect(data.getStatusClass(5)).toBe('is-success');
    });

    it('getStatusClass returns is-warning for status 3-4', () => {
      const data = wordListData();

      expect(data.getStatusClass(3)).toBe('is-warning');
      expect(data.getStatusClass(4)).toBe('is-warning');
    });

    it('getStatusClass returns is-danger for status < 3', () => {
      const data = wordListData();

      expect(data.getStatusClass(1)).toBe('is-danger');
      expect(data.getStatusClass(2)).toBe('is-danger');
    });

    it('getDisplayValue returns value or asterisk', () => {
      const data = wordListData();

      expect(data.getDisplayValue({ translation: 'hello' } as never, 'translation')).toBe('hello');
      expect(data.getDisplayValue({ translation: '' } as never, 'translation')).toBe('*');
    });
  });

  // ===========================================================================
  // Page Title Tests
  // ===========================================================================

  describe('Page title methods', () => {
    it('getSelectedLanguageName returns empty for no selection', () => {
      const data = wordListData();
      data.filters.lang = null;

      expect(data.getSelectedLanguageName()).toBe('');
    });

    it('getSelectedLanguageName returns language name', () => {
      const data = wordListData();
      data.filters.lang = 1;
      data.filterOptions.languages = [
        { id: 1, name: 'English' },
        { id: 2, name: 'Spanish' },
      ];

      expect(data.getSelectedLanguageName()).toBe('English');
    });

    it('updatePageTitle updates document title', () => {
      const data = wordListData();
      data.filters.lang = 1;
      data.filterOptions.languages = [{ id: 1, name: 'English' }];

      const h1 = document.createElement('h1');
      h1.textContent = 'Terms';
      document.body.appendChild(h1);

      data.updatePageTitle();

      expect(document.title).toBe('LWT :: English Terms');
      expect(h1.textContent).toBe('English Terms');
    });
  });

  // ===========================================================================
  // initWordListAlpine Tests
  // ===========================================================================

  describe('initWordListAlpine', () => {
    it('does not throw when called', () => {
      expect(() => initWordListAlpine()).not.toThrow();
    });
  });

  // ===========================================================================
  // Window Exports Tests
  // ===========================================================================

  describe('Window Exports', () => {
    it('exposes wordListData on window', () => {
      expect(window.wordListData).toBeDefined();
    });
  });

  // ===========================================================================
  // Bulk Action Tests
  // ===========================================================================

  describe('Bulk actions', () => {
    it('handleMultiAction returns early when action is empty', async () => {
      const data = wordListData();

      const select = document.createElement('select');
      select.value = '';
      const event = { target: select } as unknown as Event;

      await data.handleMultiAction(event);

      expect(WordsApi.bulkAction).not.toHaveBeenCalled();
    });

    it('getMarkedIds returns empty array when no terms marked', () => {
      const data = wordListData();

      expect(data.getMarkedIds()).toEqual([]);
    });

    it('handleMultiAction cancels when delete not confirmed', async () => {
      const data = wordListData();
      data.marked.add(1);

      vi.spyOn(window, 'confirm').mockReturnValue(false);

      const select = document.createElement('select');
      select.value = 'del';
      const event = { target: select } as unknown as Event;

      await data.handleMultiAction(event);

      // When confirm returns false, it should reset the select
      expect(select.value).toBe('');
    });
  });

  // ===========================================================================
  // All Action Tests
  // ===========================================================================

  describe('All actions (handleAllAction)', () => {
    it('returns early when action is empty', async () => {
      const data = wordListData();

      const select = document.createElement('select');
      select.value = '';
      const event = { target: select } as unknown as Event;

      await data.handleAllAction(event);

      expect(window.confirm).not.toHaveBeenCalled();
    });
  });

  // ===========================================================================
  // Init Method Tests
  // ===========================================================================

  describe('init method', () => {
    it('loads filter state, options, and words on init', async () => {
      const data = wordListData();

      await data.init();

      expect(WordsApi.getFilterOptions).toHaveBeenCalled();
      expect(WordsApi.getList).toHaveBeenCalled();
      expect(data.loading).toBe(false);
    });
  });

  // ===========================================================================
  // loadWords Tests
  // ===========================================================================

  describe('loadWords', () => {
    it('updates words and pagination from API response', async () => {
      const data = wordListData();

      vi.mocked(WordsApi.getList).mockResolvedValue({
        data: {
          words: [{ id: 1, text: 'hello' } as never],
          pagination: { page: 2, per_page: 50, total: 100, total_pages: 2 }
        },
        error: undefined,
      });

      await data.loadWords();

      expect(data.words).toHaveLength(1);
      expect(data.pagination.page).toBe(2);
      expect(data.pagination.total).toBe(100);
    });
  });

  // ===========================================================================
  // loadFilterOptions Tests
  // ===========================================================================

  describe('loadFilterOptions', () => {
    it('passes language ID to API', async () => {
      const data = wordListData();
      data.filters.lang = 5;

      await data.loadFilterOptions();

      expect(WordsApi.getFilterOptions).toHaveBeenCalledWith(5);
    });

    it('passes null when no language selected', async () => {
      const data = wordListData();
      data.filters.lang = null;

      await data.loadFilterOptions();

      expect(WordsApi.getFilterOptions).toHaveBeenCalledWith(null);
    });

    it('handles empty string language as null', async () => {
      const data = wordListData();
      data.filters.lang = '' as unknown as number;

      await data.loadFilterOptions();

      expect(WordsApi.getFilterOptions).toHaveBeenCalledWith(null);
    });
  });

  // ===========================================================================
  // setFilter lang change Tests
  // ===========================================================================

  describe('setFilter language change', () => {
    it('resets text_id and reloads filter options when lang changes', () => {
      const data = wordListData();
      data.filters.text_id = 5;

      vi.mocked(WordsApi.getList).mockResolvedValue({
        data: { words: [], pagination: { page: 1, per_page: 50, total: 0, total_pages: 0 } },
        error: undefined,
      });

      data.setFilter('lang', 3);

      expect(data.filters.text_id).toBeNull();
      expect(WordsApi.getFilterOptions).toHaveBeenCalled();
    });
  });

  // ===========================================================================
  // startEdit with missing word Tests
  // ===========================================================================

  describe('startEdit edge cases', () => {
    it('does nothing when word not found', () => {
      const data = wordListData();
      data.words = [];

      data.startEdit(999, 'translation');

      expect(data.editingWord).toBeNull();
    });
  });

  // ===========================================================================
  // saveEdit error handling Tests
  // ===========================================================================

  describe('saveEdit error handling', () => {
    it('shows alert on API error', async () => {
      const data = wordListData();
      const alertSpy = vi.spyOn(window, 'alert');
      data.words = [{ id: 1, translation: 'old', romanization: '' } as never];
      data.editingWord = { id: 1, field: 'translation' };
      data.editValue = 'new';

      vi.mocked(WordsApi.inlineEdit).mockResolvedValue({
        data: { success: false, error: 'Update failed' },
        error: undefined,
      });

      await data.saveEdit();

      expect(alertSpy).toHaveBeenCalledWith('Update failed');
    });

    it('clears editing state even on error', async () => {
      const data = wordListData();
      data.words = [{ id: 1, translation: 'old', romanization: '' } as never];
      data.editingWord = { id: 1, field: 'translation' };
      data.editValue = 'new';

      vi.mocked(WordsApi.inlineEdit).mockResolvedValue({
        data: { success: false, error: 'Error' },
        error: undefined,
      });

      await data.saveEdit();

      expect(data.editingWord).toBeNull();
      expect(data.editValue).toBe('');
      expect(data.editSaving).toBe(false);
    });

    it('updates romanization field on success', async () => {
      const data = wordListData();
      data.words = [{ id: 1, translation: '', romanization: 'old' } as never];
      data.editingWord = { id: 1, field: 'romanization' };
      data.editValue = 'new';

      vi.mocked(WordsApi.inlineEdit).mockResolvedValue({
        data: { success: true, value: 'new' },
        error: undefined,
      });

      await data.saveEdit();

      expect(data.words[0].romanization).toBe('new');
    });

    it('does nothing when editingWord is null', async () => {
      const data = wordListData();
      data.editingWord = null;

      await data.saveEdit();

      expect(WordsApi.inlineEdit).not.toHaveBeenCalled();
    });
  });

  // ===========================================================================
  // loadFilterState Tests
  // ===========================================================================

  describe('loadFilterState', () => {
    it('reads lang from URL params', () => {
      window.location.search = '?lang=7';

      const data = wordListData();
      data.loadFilterState();

      expect(data.filters.lang).toBe(7);
    });

    it('URL lang takes precedence over localStorage', () => {
      window.location.search = '?lang=7';
      vi.mocked(window.localStorage.getItem).mockReturnValue(
        JSON.stringify({ lang: 3, status: '1' })
      );

      const data = wordListData();
      data.loadFilterState();

      expect(data.filters.lang).toBe(7);
    });

    it('handles localStorage errors gracefully', () => {
      vi.mocked(window.localStorage.getItem).mockImplementation(() => {
        throw new Error('Storage error');
      });

      const data = wordListData();

      expect(() => data.loadFilterState()).not.toThrow();
    });
  });
});
