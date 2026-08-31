/**
 * Books list page — renders entirely from `/api/v1/books`.
 *
 * The PHP view is a scaffold: it emits no book data, only the mount point and
 * a config blob carrying the language filter and page the URL asked for, so a
 * bookmarked `?lg_id=2&page=3` still lands where the reader expects.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.4.0
 */

import Alpine from 'alpinejs';
import { t } from '@shared/i18n/translator';
import { LanguagesApi, type LanguageListItem } from '@modules/language/api/languages_api';
import { BooksApi, type Book, type BookPagination } from '../api/books_api';
import { readPageConfig } from '@shared/utils/page_config';

/** Config the PHP scaffold hands over. */
interface BookListConfig {
  languageId: number;
  page: number;
}

/** Alpine component state for the books list. */
export interface BookListData {
  books: Book[];
  languages: LanguageListItem[];
  languageId: number;
  page: number;
  pagination: BookPagination;
  isLoading: boolean;
  error: string;
  notification: string;
  init(): Promise<void>;
  load(): Promise<void>;
  changeLanguage(event: Event): Promise<void>;
  goToPage(page: number): Promise<void>;
  confirmDelete(book: Book): Promise<void>;
  clearNotification(): void;
  pageNumbers(): number[];
  bookHref(book: Book): string;
  progressLabel(book: Book): string;
  authorLabel(book: Book): string;
  isCurrentPage(page: number): boolean;
  isSelectedLanguage(lang: LanguageListItem): boolean;
}

/**
 * Read the scaffold's config blob.
 *
 * @returns The language filter and page to start on
 */
function readConfig(): BookListConfig {
  return readPageConfig<BookListConfig>('book-list-config', {
    languageId: 0,
    page: 1
  });
}

/**
 * Keep the address bar in step with the filter, so the page stays
 * bookmarkable and the back button works after filtering.
 *
 * @param languageId Selected language, or 0 for all
 * @param page       1-based page number
 */
function syncUrl(languageId: number, page: number): void {
  const url = new URL(window.location.href);
  if (languageId > 0) {
    url.searchParams.set('lg_id', String(languageId));
  } else {
    url.searchParams.delete('lg_id');
  }
  if (page > 1) {
    url.searchParams.set('page', String(page));
  } else {
    url.searchParams.delete('page');
  }
  window.history.replaceState({}, '', url);
}

/**
 * Build the books list component.
 *
 * @returns Alpine component state
 */
export function bookListData(): BookListData {
  const config = readConfig();

  return {
    books: [],
    languages: [],
    languageId: config.languageId,
    page: config.page,
    pagination: { total: 0, page: 1, per_page: 20, total_pages: 0 },
    isLoading: true,
    error: '',
    notification: '',

    async init() {
      const languages = await LanguagesApi.list();
      this.languages = languages.data?.languages ?? [];
      await this.load();
    },

    async load() {
      this.isLoading = true;
      this.error = '';

      const response = await BooksApi.list(this.languageId || null, this.page);
      this.isLoading = false;

      if (response.error || !response.data) {
        this.error = response.error || t('book.no_books_found');
        this.books = [];
        return;
      }

      this.books = response.data.data ?? [];
      this.pagination = response.data.pagination;
    },

    async changeLanguage(event: Event) {
      const select = event.target as HTMLSelectElement;
      this.languageId = Number(select.value) || 0;
      // A narrower filter can leave the current page past the end.
      this.page = 1;
      syncUrl(this.languageId, this.page);
      await this.load();
    },

    async goToPage(page: number) {
      if (page < 1 || page > this.pagination.total_pages || page === this.page) {
        return;
      }
      this.page = page;
      syncUrl(this.languageId, this.page);
      await this.load();
    },

    async confirmDelete(book: Book) {
      if (!window.confirm(t('book.confirm_delete_book'))) {
        return;
      }

      const response = await BooksApi.remove(book.id);
      if (response.error || response.data?.success !== true) {
        this.error = response.error || response.data?.error || t('book.flash.delete_failed');
        return;
      }

      this.notification = response.data.message ?? '';

      // Deleting the last row of the last page would otherwise strand the
      // reader on an empty page.
      if (this.books.length === 1 && this.page > 1) {
        this.page -= 1;
        syncUrl(this.languageId, this.page);
      }
      await this.load();
    },

    clearNotification() {
      this.notification = '';
    },

    pageNumbers() {
      const pages: number[] = [];
      for (let i = 1; i <= this.pagination.total_pages; i++) {
        pages.push(i);
      }
      return pages;
    },

    bookHref(book: Book) {
      return `/book/${book.id}`;
    },

    progressLabel(book: Book) {
      return `${Math.round(book.progress * 10) / 10}%`;
    },

    // x-text assigns textContent directly, so a null author would print the
    // literal string "null".
    authorLabel(book: Book) {
      return book.author ?? '';
    },

    isCurrentPage(page: number) {
      return page === this.pagination.page;
    },

    isSelectedLanguage(lang: LanguageListItem) {
      return lang.id === this.languageId;
    }
  };
}

/** Register the component with Alpine. */
export function initBookListAlpine(): void {
  Alpine.data('bookList', bookListData);
}

initBookListAlpine();
