/**
 * Tests for book_list.ts - Books list Alpine component.
 *
 * Covers the state transitions the Cypress spec cannot reach cheaply:
 * pagination bounds, the page-collapse after deleting a page's last row, and
 * the null-author case that would otherwise print the string "null".
 */
import { answerConfirms, settle } from '../helpers/confirm_answers';
import { describe, it, expect, beforeEach, vi } from 'vitest';

vi.mock('alpinejs', () => ({
  default: { data: vi.fn() }
}));

vi.mock('../../../src/frontend/js/shared/i18n/translator', () => ({
  t: (key: string) => key
}));

vi.mock('../../../src/frontend/js/modules/language/api/languages_api', () => ({
  LanguagesApi: {
    list: vi.fn().mockResolvedValue({
      data: { languages: [{ id: 1, name: 'German' }], currentLanguageId: 1 }
    })
  }
}));

const listMock = vi.fn();
const removeMock = vi.fn();

vi.mock('../../../src/frontend/js/modules/book/api/books_api', () => ({
  BooksApi: {
    list: (...args: unknown[]) => listMock(...args),
    remove: (...args: unknown[]) => removeMock(...args)
  }
}));

import { bookListData } from '../../../src/frontend/js/modules/book/pages/book_list';

/**
 * Build a books API page response.
 *
 * @param books      Rows to return
 * @param totalPages Number of pages the server reports
 * @param page       Current page
 */
function pageOf(books: unknown[], totalPages = 1, page = 1) {
  return {
    data: {
      success: true,
      data: books,
      pagination: { total: books.length, page, per_page: 20, total_pages: totalPages }
    }
  };
}

const aBook = (id: number, over: Record<string, unknown> = {}) => ({
  id,
  title: `Book ${id}`,
  author: 'Someone',
  sourceType: 'epub',
  totalChapters: 3,
  currentChapter: 1,
  progress: 33.333,
  ...over
});

describe('book_list.ts', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    document.body.innerHTML = '';
    window.history.replaceState({}, '', '/books');
  });

  describe('config blob', () => {
    it('starts from the language and page the scaffold supplies', () => {
      document.body.innerHTML =
        '<script type="application/json" id="book-list-config">{"languageId":7,"page":3}</script>';

      const component = bookListData();

      expect(component.languageId).toBe(7);
      expect(component.page).toBe(3);
    });

    it('falls back to defaults when the blob is malformed', () => {
      document.body.innerHTML =
        '<script type="application/json" id="book-list-config">not json</script>';

      const component = bookListData();

      expect(component.languageId).toBe(0);
      expect(component.page).toBe(1);
    });
  });

  describe('loading', () => {
    it('renders the returned page and clears the loading flag', async () => {
      listMock.mockResolvedValue(pageOf([aBook(1), aBook(2)]));

      const component = bookListData();
      await component.load();

      expect(component.books).toHaveLength(2);
      expect(component.isLoading).toBe(false);
      expect(component.error).toBe('');
    });

    it('surfaces a transport error instead of showing a stale list', async () => {
      listMock.mockResolvedValue({ error: 'network down' });

      const component = bookListData();
      await component.load();

      expect(component.error).toBe('network down');
      expect(component.books).toEqual([]);
    });

    it('omits the language filter when no language is selected', async () => {
      listMock.mockResolvedValue(pageOf([]));

      const component = bookListData();
      component.languageId = 0;
      await component.load();

      expect(listMock).toHaveBeenCalledWith(null, 1);
    });
  });

  describe('pagination', () => {
    it('ignores pages outside the reported range', async () => {
      listMock.mockResolvedValue(pageOf([aBook(1)], 2, 1));
      const component = bookListData();
      await component.load();
      listMock.mockClear();

      await component.goToPage(0);
      await component.goToPage(3);
      await component.goToPage(1); // already current

      expect(listMock).not.toHaveBeenCalled();
    });

    it('loads a page inside the range and reflects it in the URL', async () => {
      listMock.mockResolvedValue(pageOf([aBook(1)], 3, 1));
      const component = bookListData();
      await component.load();

      await component.goToPage(2);

      expect(listMock).toHaveBeenLastCalledWith(null, 2);
      expect(window.location.search).toContain('page=2');
    });
  });

  describe('deleting', () => {
    it('steps back a page when the last row on it is removed', async () => {
      answerConfirms(true);
      removeMock.mockResolvedValue({ data: { success: true, message: 'gone' } });
      listMock.mockResolvedValue(pageOf([aBook(9)], 2, 2));

      const component = bookListData();
      component.page = 2;
      await component.load();

      await component.confirmDelete(aBook(9));

      await settle();
      expect(component.page).toBe(1);
      expect(component.notification).toBe('gone');
    });

    it('does nothing when the confirmation is declined', async () => {
      answerConfirms(false);

      const component = bookListData();
      await component.confirmDelete(aBook(1));

      await settle();
      expect(removeMock).not.toHaveBeenCalled();
    });

    it('reports a failed delete rather than dropping the row silently', async () => {
      answerConfirms(true);
      removeMock.mockResolvedValue({ data: { success: false, error: 'in use' } });
      listMock.mockResolvedValue(pageOf([aBook(1), aBook(2)]));

      const component = bookListData();
      await component.load();
      await component.confirmDelete(aBook(1));

      await settle();
      expect(component.error).toBe('in use');
    });
  });

  describe('labels', () => {
    it('renders an empty author rather than the string "null"', () => {
      const component = bookListData();

      expect(component.authorLabel(aBook(1, { author: null }))).toBe('');
    });

    it('rounds the progress percentage to one decimal', () => {
      const component = bookListData();

      expect(component.progressLabel(aBook(1, { progress: 33.333 }))).toBe('33.3%');
    });
  });
});
