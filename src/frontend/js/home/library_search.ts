/**
 * Library Search - Search Project Gutenberg for texts to import.
 *
 * Alpine.js component that provides search functionality for the
 * Project Gutenberg catalog, with results displayed on the home page.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.0.0
 */

import Alpine from 'alpinejs';
import { initIcons } from '@shared/icons/lucide_icons';
import { t, tn } from '@shared/i18n/translator';

interface GutenbergBook {
  id: number;
  title: string;
  authors: string[];
  languages: string[];
  subjects: string[];
  downloadCount: number;
  textUrl: string;
  difficultyTier?: 'easy' | 'medium' | 'hard';
}

interface SearchResponse {
  results: GutenbergBook[];
  count: number;
  next: boolean;
  error?: string;
}

interface PreviewData {
  total_unique_words: number;
  known_words: number;
  unknown_words: number;
  coverage_percent: number;
  difficulty_label: string;
  sample_unknown_words: string[];
}

interface LibrarySearchData {
  open: boolean;
  query: string;
  results: GutenbergBook[];
  totalCount: number;
  hasMore: boolean;
  page: number;
  loading: boolean;
  error: string;
  searched: boolean;
  importing: number | null;
  previewBookId: number | null;
  previewLoading: boolean;
  previewData: PreviewData | null;
  previewError: string;

  search(): Promise<void>;
  loadMore(): Promise<void>;
  importBook(book: GutenbergBook): void;
  togglePreview(book: GutenbergBook): Promise<void>;
  formatAuthors(authors: string[]): string;
  formatDownloads(count: number): string;
  downloadsLabel(count: number): string;
  booksFoundLabel(count: number): string;
  coverageLabel(data: PreviewData): string;
  coverageDetailedLabel(data: PreviewData): string;
  tierLabel(tier: string): string;
  tierClass(tier: string): string;
  coverageClass(label: string): string;
  close(): void;
}

/**
 * Alpine.js data component for library search.
 */
export function librarySearchData(): LibrarySearchData {
  return {
    open: false,
    query: '',
    results: [],
    totalCount: 0,
    hasMore: false,
    page: 1,
    loading: false,
    error: '',
    searched: false,
    importing: null,
    previewBookId: null,
    previewLoading: false,
    previewData: null,
    previewError: '',

    async search() {
      const q = this.query.trim();
      if (!q) return;

      this.loading = true;
      this.error = '';
      this.page = 1;
      this.results = [];
      this.searched = true;

      try {
        const configEl = document.getElementById('home-warnings-config');
        const config = configEl ? JSON.parse(configEl.textContent || '{}') : {};
        const langId = config.currentLanguageId || 0;

        const params = new URLSearchParams({
          q,
          language_id: String(langId),
          page: '1',
        });

        const response = await fetch(`/api/v1/texts/library-search?${params}`);
        const data: SearchResponse = await response.json();

        if (!response.ok || data.error) {
          this.error = data.error || 'Search failed. Please try again.';
          return;
        }

        this.results = data.results;
        this.totalCount = data.count;
        this.hasMore = data.next;
        requestAnimationFrame(() => initIcons());
      } catch {
        this.error = 'Could not reach the server. Please try again.';
      } finally {
        this.loading = false;
      }
    },

    async loadMore() {
      if (this.loading || !this.hasMore) return;

      this.loading = true;
      this.page += 1;

      try {
        const configEl = document.getElementById('home-warnings-config');
        const config = configEl ? JSON.parse(configEl.textContent || '{}') : {};
        const langId = config.currentLanguageId || 0;

        const params = new URLSearchParams({
          q: this.query.trim(),
          language_id: String(langId),
          page: String(this.page),
        });

        const response = await fetch(`/api/v1/texts/library-search?${params}`);
        const data: SearchResponse = await response.json();

        if (response.ok && !data.error) {
          this.results = this.results.concat(data.results);
          this.hasMore = data.next;
          requestAnimationFrame(() => initIcons());
        }
      } catch {
        // Silently fail on load-more
      } finally {
        this.loading = false;
      }
    },

    importBook(book: GutenbergBook) {
      this.importing = book.id;
      // Navigate to the text creation form with the Gutenberg URL pre-filled
      const configEl = document.getElementById('home-warnings-config');
      const config = configEl ? JSON.parse(configEl.textContent || '{}') : {};
      const basePath = config.basePath || '';

      const params = new URLSearchParams({
        import_url: book.textUrl,
        import_title: book.title,
      });

      window.location.href = `${basePath}/texts/new?${params}`;
    },

    async togglePreview(book: GutenbergBook) {
      // Toggle off if already previewing this book
      if (this.previewBookId === book.id) {
        this.previewBookId = null;
        this.previewData = null;
        this.previewError = '';
        return;
      }

      this.previewBookId = book.id;
      this.previewLoading = true;
      this.previewData = null;
      this.previewError = '';

      try {
        const configEl = document.getElementById('home-warnings-config');
        const config = configEl ? JSON.parse(configEl.textContent || '{}') : {};
        const langId = config.currentLanguageId || 0;

        const params = new URLSearchParams({
          url: book.textUrl,
          language_id: String(langId),
        });

        const response = await fetch(`/api/v1/texts/library-preview?${params}`);
        const data = await response.json();

        // Check that we're still previewing the same book
        if (this.previewBookId !== book.id) return;

        if (!response.ok || data.error) {
          this.previewError = data.error || 'Could not analyze this text.';
          return;
        }

        this.previewData = data as unknown as PreviewData;
      } catch {
        if (this.previewBookId === book.id) {
          this.previewError = 'Could not reach the server.';
        }
      } finally {
        this.previewLoading = false;
      }
    },

    formatAuthors(authors: string[]): string {
      if (authors.length === 0) return 'Unknown author';
      return authors.join(', ');
    },

    formatDownloads(count: number): string {
      if (count >= 1000000) return (count / 1000000).toFixed(1) + 'M';
      if (count >= 1000) return (count / 1000).toFixed(1) + 'K';
      return String(count);
    },

    tierLabel(tier: string): string {
      return tier === 'easy' ? 'Easy' : tier === 'hard' ? 'Hard' : 'Medium';
    },

    tierClass(tier: string): string {
      if (tier === 'easy') return 'is-success is-light';
      if (tier === 'hard') return 'is-danger is-light';
      return 'is-warning is-light';
    },

    coverageClass(label: string): string {
      if (label === 'easy') return 'is-success';
      if (label === 'hard') return 'is-danger';
      return 'is-warning';
    },

    downloadsLabel(count: number): string {
      return tn('home.downloads', count, { count: this.formatDownloads(count) });
    },

    booksFoundLabel(count: number): string {
      return tn('home.books_found', count);
    },

    coverageLabel(data: PreviewData): string {
      return t('home.you_know_x_of_unique_words', { percent: data.coverage_percent });
    },

    coverageDetailedLabel(data: PreviewData): string {
      return t('home.you_know_x_of_unique_words_detailed', {
        percent: data.coverage_percent,
        known: data.known_words,
        total: data.total_unique_words,
      });
    },

    close() {
      this.open = false;
      this.previewBookId = null;
      this.previewData = null;
      this.previewError = '';
    },
  };
}

/**
 * Initialize the library search Alpine.js component.
 */
export function initLibrarySearch(): void {
  Alpine.data('librarySearch', librarySearchData);

  // Delegated click handler for the search card (CSP-safe, no inline JS)
  document.addEventListener('click', (e) => {
    const target = e.target as HTMLElement;
    if (target.closest('[data-action="open-library-search"]')) {
      document.dispatchEvent(new CustomEvent('open-library-search'));
    }
  });
}

// Register immediately (before Alpine.start())
initLibrarySearch();
