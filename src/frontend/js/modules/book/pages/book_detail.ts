/**
 * Book detail page — renders entirely from `/api/v1/books/{id}` and
 * `/api/v1/books/{id}/chapters`.
 *
 * The PHP view carries only the book ID; every field on the page, including
 * the title, arrives over the API.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.4.0
 */

import { confirmDeletion } from '@shared/components/confirm_dialog';
import Alpine from 'alpinejs';
import { t } from '@shared/i18n/translator';
import { BooksApi, type Book, type BookChapter } from '../api/books_api';

/** Alpine component state for the book detail page. */
export interface BookDetailData {
  book: Book | null;
  chapters: BookChapter[];
  bookId: number;
  isLoading: boolean;
  error: string;
  init(): Promise<void>;
  load(): Promise<void>;
  remove(): Promise<void>;
  readHref(chapter: BookChapter): string;
  editHref(chapter: BookChapter): string;
  continueHref(): string;
  isCurrentChapter(chapter: BookChapter): boolean;
  progressLabel(): string;
  chapterCountLabel(): string;
  sourceLabel(): string;
}

/**
 * Read the book ID the scaffold was rendered for.
 *
 * @returns The book ID, or 0 when the blob is missing or malformed
 */
function readBookId(): number {
  const el = document.getElementById('book-detail-config');
  if (el) {
    try {
      return Number(JSON.parse(el.textContent || '{}').bookId) || 0;
    } catch {
      // Malformed blob: the component will surface a not-found error.
    }
  }
  return 0;
}

/**
 * Build the book detail component.
 *
 * @returns Alpine component state
 */
export function bookDetailData(): BookDetailData {
  return {
    book: null,
    chapters: [],
    bookId: readBookId(),
    isLoading: true,
    error: '',

    async init() {
      await this.load();
    },

    async load() {
      this.isLoading = true;
      this.error = '';

      // GET /books/{id} already nests the chapters, so one request is enough.
      const response = await BooksApi.get(this.bookId);
      this.isLoading = false;

      if (response.error || response.data?.success !== true || !response.data.data) {
        this.error = response.error || response.data?.error || t('book.flash.book_not_found');
        return;
      }

      this.book = response.data.data.book;
      this.chapters = response.data.data.chapters ?? [];
    },

    async remove() {
      if (!this.book || !await confirmDeletion(t('book.confirm_delete_book'))) {
        return;
      }

      const response = await BooksApi.remove(this.book.id);
      if (response.error || response.data?.success !== true) {
        this.error = response.error || response.data?.error || t('book.flash.delete_failed');
        return;
      }

      window.location.href = '/books';
    },

    readHref(chapter: BookChapter) {
      return `/text/${chapter.id}/read`;
    },

    editHref(chapter: BookChapter) {
      return `/texts/${chapter.id}/edit`;
    },

    continueHref() {
      // The reader resumes at the recorded chapter when there is one, and
      // otherwise starts at the beginning.
      const current = this.chapters.find(c => c.num === this.book?.currentChapter);
      const target = current ?? this.chapters[0];
      return target ? `/text/${target.id}/read` : '#';
    },

    isCurrentChapter(chapter: BookChapter) {
      return chapter.num === this.book?.currentChapter;
    },

    progressLabel() {
      return `${Math.round((this.book?.progress ?? 0) * 10) / 10}%`;
    },

    chapterCountLabel() {
      return t('book.chapter_x_of_y', {
        current: this.book?.currentChapter ?? 0,
        total: this.book?.totalChapters ?? 0
      });
    },

    sourceLabel() {
      return (this.book?.sourceType ?? '').toUpperCase();
    }
  };
}

/** Register the component with Alpine. */
export function initBookDetailAlpine(): void {
  Alpine.data('bookDetail', bookDetailData);
}

initBookDetailAlpine();
