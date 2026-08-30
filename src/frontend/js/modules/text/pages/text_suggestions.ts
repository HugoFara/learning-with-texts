/**
 * Text Suggestions - Gutenberg & Feed browsing on the new text page.
 *
 * Alpine.js components that let users discover texts from Project Gutenberg
 * and their configured RSS feeds, directly on the /texts/new page.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.0.0
 */

import Alpine from 'alpinejs';
import { initIcons } from '@shared/icons/lucide_icons';
import { getCsrfToken } from '@shared/api/client';
import { importEpubForm } from '@modules/book/api/books_api';
import { saveTextForm } from './text_form_save';
import { checkTextForm } from './text_check_run';

// ── Gutenberg browser ───────────────────────────────────────────────

interface BookStats {
  totalWords: number;
  totalUniqueWords: number;
  knownWords: number;
  unknownWords: number;
  coveragePercent: number;
}

interface GutenbergBook {
  id: number;
  title: string;
  authors: string[];
  languages: string[];
  subjects: string[];
  downloadCount: number;
  textUrl: string;
  difficultyTier?: 'easy' | 'medium' | 'hard';
  stats?: BookStats;
  statsLoading?: boolean;
  statsError?: boolean;
}

interface GutenbergBrowserData {
  books: GutenbergBook[];
  hasMore: boolean;
  page: number;
  loading: boolean;
  error: string;
  importing: number | null;

  init(): void;
  fetchBooks(page: number): Promise<void>;
  fetchStatsForBooks(books: GutenbergBook[]): void;
  loadMore(): Promise<void>;
  importBook(book: GutenbergBook): void;
  formatAuthors(authors: string[]): string;
  tierLabel(tier: string): string;
  tierClass(tier: string): string;
  bookTierLabel(book: GutenbergBook): string;
  bookTierClass(book: GutenbergBook): string;
  importingClass(book: GutenbergBook): string;
  isImporting(): boolean;
  loadingClass(): string;
  showPlaceholder(): boolean;
  showNoResults(): boolean;
  formatWordCount(n: number): string;
  coverageBarWidth(book: GutenbergBook): string;
  coverageBarClass(book: GutenbergBook): string;
  coverageLabel(book: GutenbergBook): string;
  bookWordCount(book: GutenbergBook): string;
}

function getSelectedLanguageId(): number {
  const input = document.getElementById('TxLgID') as HTMLInputElement | null;
  if (!input) return 0;
  return parseInt(input.value, 10) || 0;
}

/**
 * Listen for language changes on the TxLgID hidden input.
 * Uses event delegation on document to avoid timing issues with Alpine init.
 */
function onLanguageChange(callback: (langId: number) => void): void {
  document.addEventListener('change', (e) => {
    const target = e.target as HTMLElement;
    if (target.id === 'TxLgID') {
      const langId = parseInt((target as HTMLInputElement).value, 10) || 0;
      callback(langId);
    }
  });
}

export function gutenbergBrowserData(): GutenbergBrowserData {
  return {
    books: [],
    hasMore: false,
    page: 1,
    loading: false,
    error: '',
    importing: null,

    init() {
      const langId = getSelectedLanguageId();
      if (langId > 0) {
        this.fetchBooks(1);
      }

      // Re-fetch when language selector changes (delegated on document)
      onLanguageChange((newLangId) => {
        this.books = [];
        this.page = 1;
        this.error = '';
        if (newLangId > 0) {
          this.fetchBooks(1);
        }
      });
    },

    async fetchBooks(page: number) {
      const langId = getSelectedLanguageId();
      if (this.loading || langId <= 0) return;

      this.loading = true;
      this.error = '';

      try {
        const params = new URLSearchParams({
          language_id: String(langId),
          page: String(page),
        });

        const response = await fetch(`/api/v1/texts/gutenberg-suggestions?${params}`);
        const data = await response.json();

        if (!response.ok || data.error) {
          this.error = data.error || 'Could not load suggestions.';
          return;
        }

        const newBooks: GutenbergBook[] = data.results || [];
        if (page === 1) {
          this.books = newBooks;
        } else {
          this.books = this.books.concat(newBooks);
        }
        this.hasMore = data.next || false;
        this.page = page;
        requestAnimationFrame(() => initIcons());

        // Progressively fetch vocabulary stats for each book
        this.fetchStatsForBooks(newBooks);
      } catch {
        this.error = 'Could not reach the server.';
      } finally {
        this.loading = false;
      }
    },

    fetchStatsForBooks(books: GutenbergBook[]) {
      const langId = getSelectedLanguageId();
      if (langId <= 0) return;

      const maxConcurrent = 3;
      let running = 0;
      const queue = [...books];

      const next = () => {
        while (running < maxConcurrent && queue.length > 0) {
          const book = queue.shift()!;
          running++;
          book.statsLoading = true;

          const params = new URLSearchParams({
            url: book.textUrl,
            language_id: String(langId),
          });

          fetch(`/api/v1/texts/library-preview?${params}`)
            .then(r => r.json())
            .then(data => {
              // Response::success sends the payload flat (no `{data: …}`
              // wrapper); presence of total_words confirms a usable
              // analysis. Older code expected the wrap and read
              // `data.data?.X` everywhere — those reads always
              // produced undefined and statsError was set unconditionally.
              if (!data.error && data.total_words !== undefined) {
                book.stats = {
                  totalWords: data.total_words || 0,
                  totalUniqueWords: data.total_unique_words || 0,
                  knownWords: data.known_words || 0,
                  unknownWords: data.unknown_words || 0,
                  coveragePercent: data.coverage_percent || 0,
                };
                // Update difficulty from actual coverage analysis
                if (data.difficulty_label) {
                  book.difficultyTier = data.difficulty_label;
                }
              } else {
                book.statsError = true;
              }
            })
            .catch(() => {
              book.statsError = true;
            })
            .finally(() => {
              book.statsLoading = false;
              running--;
              next();
            });
        }
      };

      next();
    },

    async loadMore() {
      if (this.loading || !this.hasMore) return;
      await this.fetchBooks(this.page + 1);
    },

    importBook(book: GutenbergBook) {
      this.importing = book.id;

      // Fill the URL input and switch to URL import mode
      const urlInput = document.getElementById('webpageUrl') as HTMLInputElement | null;
      if (urlInput) {
        urlInput.value = book.textUrl;
      }

      // Pre-fill title
      const titleInput = document.querySelector<HTMLInputElement>('input[name="TxTitle"]');
      if (titleInput) {
        titleInput.value = book.title;
      }

      // Switch to URL import mode and trigger fetch
      const formEl = document.querySelector<HTMLFormElement>('form[x-data]');
      if (formEl) {
        formEl.dispatchEvent(new CustomEvent('auto-import-url', { bubbles: true }));
      }
    },

    formatAuthors(authors: string[]): string {
      if (authors.length === 0) return 'Unknown author';
      return authors.join(', ');
    },

    tierLabel(tier: string): string {
      return tier === 'easy' ? 'Easy' : tier === 'hard' ? 'Hard' : 'Medium';
    },

    tierClass(tier: string): string {
      if (tier === 'easy') return 'is-success is-light';
      if (tier === 'hard') return 'is-danger is-light';
      return 'is-warning is-light';
    },

    bookTierLabel(book: GutenbergBook): string {
      return this.tierLabel(book.difficultyTier || '');
    },

    bookTierClass(book: GutenbergBook): string {
      return this.tierClass(book.difficultyTier || '');
    },

    importingClass(book: GutenbergBook): string {
      return this.importing === book.id ? 'is-loading' : '';
    },

    isImporting(): boolean {
      return this.importing !== null;
    },

    loadingClass(): string {
      return this.loading ? 'is-loading' : '';
    },

    showPlaceholder(): boolean {
      return !this.loading && this.books.length === 0 && !this.error && getSelectedLanguageId() <= 0;
    },

    showNoResults(): boolean {
      return !this.loading && this.books.length === 0 && !this.error && getSelectedLanguageId() > 0;
    },

    formatWordCount(n: number): string {
      if (n >= 1000) return Math.round(n / 1000) + 'k';
      return String(n);
    },

    coverageBarWidth(book: GutenbergBook): string {
      if (!book.stats) return 'width: 0%';
      return 'width: ' + Math.round(book.stats.coveragePercent) + '%';
    },

    coverageBarClass(book: GutenbergBook): string {
      if (!book.stats) return 'has-background-grey-light';
      const pct = book.stats.coveragePercent;
      if (pct >= 95) return 'has-background-success';
      if (pct >= 85) return 'has-background-warning';
      return 'has-background-danger';
    },

    coverageLabel(book: GutenbergBook): string {
      if (!book.stats) return '';
      return book.stats.coveragePercent + '% known';
    },

    bookWordCount(book: GutenbergBook): string {
      if (!book.stats) return '';
      return this.formatWordCount(book.stats.totalWords) + ' words';
    },
  };
}

// ── Global Digital Library browser ──────────────────────────────────

interface GdlBook {
  id: number;
  title: string;
  publisher: string;
  description: string;
  language: string;
  license: string;
  level: string;
  difficultyTier?: 'easy' | 'medium' | 'hard';
  thumbnail: string;
  sourceUri: string;
  epubUrl: string;
}

interface GdlBrowserData {
  books: GdlBook[];
  hasMore: boolean;
  page: number;
  query: string;
  loading: boolean;
  error: string;
  importing: number | null;

  init(): void;
  fetchBooks(page: number): Promise<void>;
  doSearch(): void;
  clearSearch(): void;
  loadMore(): Promise<void>;
  importBook(book: GdlBook): Promise<void>;
  formatMeta(book: GdlBook): string;
  bookTierLabel(book: GdlBook): string;
  bookTierClass(book: GdlBook): string;
  hasLevel(book: GdlBook): boolean;
  importingClass(book: GdlBook): string;
  isImporting(): boolean;
  loadingClass(): string;
  showPlaceholder(): boolean;
  showNoResults(): boolean;
}

export function gdlBrowserData(): GdlBrowserData {
  return {
    books: [],
    hasMore: false,
    page: 1,
    query: '',
    loading: false,
    error: '',
    importing: null,

    init() {
      const langId = getSelectedLanguageId();
      if (langId > 0) {
        this.fetchBooks(1);
      }

      onLanguageChange((newLangId) => {
        this.books = [];
        this.page = 1;
        this.error = '';
        if (newLangId > 0) {
          this.fetchBooks(1);
        }
      });
    },

    async fetchBooks(page: number) {
      const langId = getSelectedLanguageId();
      if (this.loading || langId <= 0) return;

      this.loading = true;
      this.error = '';

      try {
        const params = new URLSearchParams({
          language_id: String(langId),
          page: String(page),
        });
        if (this.query.trim()) {
          params.set('q', this.query.trim());
        }

        const response = await fetch(`/api/v1/texts/gdl-search?${params}`);
        const data = await response.json();

        if (!response.ok || data.error) {
          this.error = data.error || 'Could not load books.';
          return;
        }

        const newBooks: GdlBook[] = data.results || [];
        if (page === 1) {
          this.books = newBooks;
        } else {
          this.books = this.books.concat(newBooks);
        }
        this.hasMore = data.next || false;
        this.page = page;
        requestAnimationFrame(() => initIcons());
      } catch {
        this.error = 'Could not reach the server.';
      } finally {
        this.loading = false;
      }
    },

    doSearch() {
      this.books = [];
      this.page = 1;
      this.fetchBooks(1);
    },

    clearSearch() {
      this.query = '';
      this.doSearch();
    },

    async loadMore() {
      if (this.loading || !this.hasMore) return;
      await this.fetchBooks(this.page + 1);
    },

    async importBook(book: GdlBook) {
      if (this.importing !== null) return;
      this.importing = book.id;
      this.error = '';

      try {
        const headers: Record<string, string> = { 'Content-Type': 'application/json' };
        const csrf = getCsrfToken();
        if (csrf) {
          headers['X-CSRF-TOKEN'] = csrf;
        }

        // GDL books are ePUB; the dedicated endpoint downloads and extracts
        // text server-side (and rejects image-only picture books).
        const response = await fetch('/api/v1/texts/extract-epub-url', {
          method: 'POST',
          headers,
          body: JSON.stringify({ url: book.epubUrl }),
        });
        const data = await response.json();

        if (!response.ok || data.error) {
          this.error = data.error || `Server error: ${response.status}`;
          return;
        }

        // Populate the form and advance to the review step, matching the
        // webpage-import flow (form listens for `webpage-imported`).
        const set = (name: string, value: string): void => {
          const el = document.querySelector<HTMLInputElement | HTMLTextAreaElement>(
            `[name="${name}"]`,
          );
          if (el) el.value = value;
        };
        set('TxTitle', data.title || book.title);
        set('TxText', data.text || '');
        set('TxSourceURI', data.sourceUri || book.sourceUri);

        const formEl = document.querySelector<HTMLFormElement>('form[x-data]');
        if (formEl) {
          formEl.dispatchEvent(new CustomEvent('webpage-imported', { bubbles: true }));
        }
      } catch {
        this.error = 'Could not reach the server.';
      } finally {
        this.importing = null;
      }
    },

    formatMeta(book: GdlBook): string {
      const parts: string[] = [];
      if (book.publisher) parts.push(book.publisher);
      if (book.license) parts.push(book.license);
      return parts.join(' · ');
    },

    hasLevel(book: GdlBook): boolean {
      return !!book.level;
    },

    bookTierLabel(book: GdlBook): string {
      return book.level || '';
    },

    bookTierClass(book: GdlBook): string {
      const tier = book.difficultyTier;
      if (tier === 'easy') return 'is-success is-light';
      if (tier === 'hard') return 'is-danger is-light';
      return 'is-warning is-light';
    },

    importingClass(book: GdlBook): string {
      return this.importing === book.id ? 'is-loading' : '';
    },

    isImporting(): boolean {
      return this.importing !== null;
    },

    loadingClass(): string {
      return this.loading ? 'is-loading' : '';
    },

    showPlaceholder(): boolean {
      return !this.loading && this.books.length === 0 && !this.error && getSelectedLanguageId() <= 0;
    },

    showNoResults(): boolean {
      return !this.loading && this.books.length === 0 && !this.error && getSelectedLanguageId() > 0;
    },
  };
}

// ── Feed browser ────────────────────────────────────────────────────

interface FeedSummary {
  id: number;
  name: string;
  langId: number;
  langName: string;
  articleCount: number;
  lastUpdate: string;
}

interface FeedArticle {
  id: number;
  title: string;
  link: string;
  description: string;
  date: string;
  audio: string;
  hasText: boolean;
  status: string;
  textId: number | null;
}

interface FeedBrowserData {
  feeds: FeedSummary[];
  articles: FeedArticle[];
  selectedFeed: FeedSummary | null;
  loadingFeeds: boolean;
  loadingArticles: boolean;
  error: string;
  articlePage: number;
  articleTotalPages: number;

  init(): void;
  fetchFeeds(): Promise<void>;
  selectFeed(feed: FeedSummary): Promise<void>;
  backToFeeds(): void;
  loadArticlePage(page: number): Promise<void>;
  nextPage(): void;
  prevPage(): void;
  importArticle(article: FeedArticle): void;
  statusClass(status: string): string;
  statusLabel(status: string): string;
  feedInfo(feed: FeedSummary): string;
  selectedFeedName(): string;
  showPagination(): boolean;
  canGoPrev(): boolean;
  canGoNext(): boolean;
  isImported(article: FeedArticle): boolean;
  showEmptyFeeds(): boolean;
  showEmptyArticles(): boolean;
}

export function feedBrowserData(): FeedBrowserData {
  return {
    feeds: [],
    articles: [],
    selectedFeed: null,
    loadingFeeds: false,
    loadingArticles: false,
    error: '',
    articlePage: 1,
    articleTotalPages: 1,

    init() {
      this.fetchFeeds();

      // Refetch when language changes (delegated on document)
      onLanguageChange(() => {
        this.selectedFeed = null;
        this.articles = [];
        this.fetchFeeds();
      });
    },

    async fetchFeeds() {
      this.loadingFeeds = true;
      this.error = '';

      try {
        const langId = getSelectedLanguageId();
        const params = new URLSearchParams({ per_page: '100' });
        if (langId > 0) {
          params.set('lang', String(langId));
        }

        const response = await fetch(`/api/v1/feeds/list?${params}`);
        const data = await response.json();

        if (!response.ok || data.error) {
          this.error = data.error || 'Could not load feeds.';
          return;
        }

        this.feeds = data.feeds || [];
        requestAnimationFrame(() => initIcons());
      } catch {
        this.error = 'Could not reach the server.';
      } finally {
        this.loadingFeeds = false;
      }
    },

    async selectFeed(feed: FeedSummary) {
      this.selectedFeed = feed;
      this.articlePage = 1;
      await this.loadArticlePage(1);
    },

    backToFeeds() {
      this.selectedFeed = null;
      this.articles = [];
    },

    async loadArticlePage(page: number) {
      if (!this.selectedFeed) return;
      this.loadingArticles = true;
      this.error = '';

      try {
        const params = new URLSearchParams({
          feed_id: String(this.selectedFeed.id),
          page: String(page),
          per_page: '20',
        });

        const response = await fetch(`/api/v1/feeds/articles?${params}`);
        const data = await response.json();

        if (!response.ok || data.error) {
          this.error = data.error || 'Could not load articles.';
          return;
        }

        this.articles = data.articles || [];
        this.articlePage = data.pagination?.page || page;
        this.articleTotalPages = data.pagination?.total_pages || 1;
        requestAnimationFrame(() => initIcons());
      } catch {
        this.error = 'Could not reach the server.';
      } finally {
        this.loadingArticles = false;
      }
    },

    importArticle(article: FeedArticle) {
      if (article.link) {
        // Fill the URL input and switch to URL import mode
        const urlInput = document.getElementById('webpageUrl') as HTMLInputElement | null;
        if (urlInput) {
          urlInput.value = article.link;
        }

        // Pre-fill title
        const titleInput = document.querySelector<HTMLInputElement>('input[name="TxTitle"]');
        if (titleInput) {
          titleInput.value = article.title;
        }

        // Pre-fill audio if available
        if (article.audio) {
          const audioInput = document.getElementById('TxAudioURI') as HTMLInputElement | null;
          if (audioInput) {
            audioInput.value = article.audio;
          }
        }

        // Pre-fill source URI
        const sourceInput = document.getElementById('TxSourceURI') as HTMLInputElement | null;
        if (sourceInput) {
          sourceInput.value = article.link;
        }

        // Switch to URL import mode and trigger fetch
        const formEl = document.querySelector<HTMLFormElement>('form[x-data]');
        if (formEl) {
          formEl.dispatchEvent(new CustomEvent('auto-import-url', { bubbles: true }));
        }
      }
    },

    statusClass(status: string): string {
      if (status === 'imported') return 'is-success is-light';
      if (status === 'archived') return 'is-info is-light';
      if (status === 'error') return 'is-danger is-light';
      return 'is-light';
    },

    statusLabel(status: string): string {
      if (status === 'imported') return 'Imported';
      if (status === 'archived') return 'Archived';
      if (status === 'error') return 'Error';
      return 'New';
    },

    feedInfo(feed: FeedSummary): string {
      return feed.langName + ' \u00B7 ' + feed.articleCount + ' articles \u00B7 Updated ' + feed.lastUpdate;
    },

    selectedFeedName(): string {
      return this.selectedFeed ? this.selectedFeed.name : '';
    },

    showPagination(): boolean {
      return this.articleTotalPages > 1;
    },

    canGoPrev(): boolean {
      return this.articlePage <= 1;
    },

    canGoNext(): boolean {
      return this.articlePage >= this.articleTotalPages;
    },

    nextPage() {
      this.loadArticlePage(this.articlePage + 1);
    },

    prevPage() {
      this.loadArticlePage(this.articlePage - 1);
    },

    isImported(article: FeedArticle): boolean {
      return article.status === 'imported';
    },

    showEmptyFeeds(): boolean {
      return this.feeds.length === 0 && !this.loadingFeeds;
    },

    showEmptyArticles(): boolean {
      return this.articles.length === 0 && !this.loadingArticles;
    },
  };
}

// ── Text New Form (two-step wizard) ──────────────────────────────────

interface TextNewFormData {
  step: number;
  source: string;
  showAdvanced: boolean;
  autoImporting: boolean;
  saving: boolean;
  fileTab: 'computer' | 'server';
  fileType: '' | 'epub' | 'subtitle' | 'audio' | 'other';

  init(): void;
  isBusy(): boolean;
  selectSource(source: string): void;
  goBack(): void;
  goToReview(): void;
  sourceActive(source: string): string;
  showTextArea(): boolean;
  showFileInfo(): boolean;
  handleFileChange(event: Event): void;
  selectFileTab(tab: 'computer' | 'server'): void;
  fileTabActive(tab: string): string;
  isEpub(): boolean;
  submitOp(): string;
  saveError: string;
  hasSaveError(): boolean;
  handleSubmit(event: Event): void;
}

export function textNewFormData(): TextNewFormData {
  return {
    step: 1,
    source: '',
    showAdvanced: false,
    autoImporting: false,
    saving: false,
    fileTab: 'computer',
    fileType: '',
    saveError: '',

    init() {
      // When arriving via import_url (Gutenberg/Feed) or import_epub_url
      // (GDL "Kids' Library"), skip step 1 and show the loading/review state.
      const params = new URLSearchParams(window.location.search);
      if (params.get('import_url') || params.get('import_epub_url')) {
        this.source = 'url';
        this.step = 2;
        this.autoImporting = true;
      }
      // file_import.ts dispatches this when a local file's type is detected.
      document.addEventListener('lwt:file-import', (e) => {
        const detail = (e as CustomEvent<{ type: TextNewFormData['fileType'] }>).detail;
        this.fileType = detail?.type ?? '';
      });
    },

    selectSource(source: string) {
      this.source = source;
      if (source === 'paste') {
        this.step = 2;
      }
      if (source !== 'file') {
        this.fileType = '';
      }
    },

    goBack() {
      this.step = 1;
      this.autoImporting = false;
    },

    goToReview() {
      this.step = 2;
      this.autoImporting = false;
    },

    sourceActive(source: string): string {
      return this.source === source ? 'is-primary is-light' : '';
    },

    showTextArea(): boolean {
      return this.source !== 'file';
    },

    showFileInfo(): boolean {
      return this.source === 'file';
    },

    handleFileChange(event: Event) {
      const input = event.target as HTMLInputElement;
      const wrapper = input.closest('.file') as HTMLElement | null;
      const fileNameEl = wrapper ? wrapper.querySelector('.file-name') : null;
      if (fileNameEl && input.files && input.files.length > 0) {
        fileNameEl.textContent = input.files[0].name;
      }
    },

    selectFileTab(tab: 'computer' | 'server') {
      this.fileTab = tab;
    },

    fileTabActive(tab: string): string {
      return this.fileTab === tab ? 'is-active' : '';
    },

    isEpub(): boolean {
      return this.fileType === 'epub';
    },

    /**
     * Whether the last save or import reported a failure.
     *
     * @returns True when an error message is pending
     */
    hasSaveError(): boolean {
      return this.saveError !== '';
    },

    /**
     * Whether a save or an auto-import is in flight.
     *
     * The two are tracked apart because `autoImporting` also drives the
     * "fetching the page" banner, which belongs to the URL/Gutenberg import
     * alone — a plain save reusing that flag announced a Gutenberg fetch that
     * was never happening.
     *
     * @returns True while the submit button should stay disabled
     */
    isBusy(): boolean {
      return this.autoImporting || this.saving;
    },

    /**
     * Save through the API rather than posting the form to the page origin.
     *
     * An EPUB goes to the books API, everything else to POST /api/v1/texts,
     * which answers with a bookId of its own when the text was long enough to
     * be split into chapters.
     *
     * @param event Submit event
     */
    handleSubmit(event: Event) {
      event.preventDefault();
      const form = event.target as HTMLFormElement | null;
      if (!form || this.isBusy()) return;

      this.saveError = '';
      this.saving = true;

      const save = this.isEpub()
        ? importEpubForm(form).then((result) => ({
            textId: null,
            bookId: result.bookId,
            error: result.error,
          }))
        : saveTextForm(form, 0);

      void save.then((result) => {
        if (result.bookId !== null) {
          window.location.href = `/book/${result.bookId}`;
          return;
        }
        if (result.textId !== null) {
          window.location.href = `/text/${result.textId}/read`;
          return;
        }
        this.saveError = result.error;
        this.saving = false;
      });
    },

    submitOp(): string {
      return this.isEpub() ? 'Import' : 'Save and Open';
    },
  };
}

// ── Text Edit Form (existing text) ───────────────────────────────────

interface TextEditFormData {
  textId: number;
  saving: boolean;
  saveError: string;
  checking: boolean;
  hasReport: boolean;

  init(): void;
  hasSaveError(): boolean;
  handleSubmit(event: Event): void;
  check(event: Event): void;
}

export function textEditFormData(): TextEditFormData {
  return {
    textId: 0,
    saving: false,
    saveError: '',
    checking: false,
    hasReport: false,

    init() {
      const configEl = document.getElementById('text-edit-config');
      if (!configEl?.textContent) return;
      try {
        const config = JSON.parse(configEl.textContent) as { textId?: number };
        this.textId = config.textId ?? 0;
      } catch {
        this.textId = 0;
      }
    },

    hasSaveError(): boolean {
      return this.saveError !== '';
    },

    /**
     * Save through PUT /api/v1/texts/{id} instead of posting the form.
     *
     * @param event Submit event
     */
    handleSubmit(event: Event) {
      const submitter = (event as SubmitEvent).submitter as HTMLButtonElement | null;
      const op = submitter?.value ?? 'Change';

      event.preventDefault();
      const form = event.target as HTMLFormElement | null;
      if (!form || this.saving) return;

      this.saveError = '';
      this.saving = true;
      const openAfter = op === 'Change and Open';

      void saveTextForm(form, this.textId).then((result) => {
        if (result.textId === null) {
          this.saveError = result.error;
          this.saving = false;
          return;
        }
        window.location.href = openAfter
          ? `/text/${result.textId}/read`
          : `/texts#rec${result.textId}`;
      });
    },

    /**
     * Report on how the text in the box would parse, without saving it.
     *
     * This used to post the form to a server-rendered report page, losing
     * whatever else was being edited; the report now renders in place.
     *
     * @param event Click event from the Check button
     */
    check(event: Event) {
      const form = (event.target as HTMLElement | null)?.closest('form');
      if (!form || this.checking) return;

      this.saveError = '';
      this.checking = true;

      void checkTextForm(form, 'check_text').then((error) => {
        this.checking = false;
        this.saveError = error;
        this.hasReport = error === '';
      });
    },
  };
}

// ── Registration ────────────────────────────────────────────────────

Alpine.data('textNewForm', textNewFormData);
Alpine.data('textEditForm', textEditFormData);
Alpine.data('gutenbergBrowser', gutenbergBrowserData);
Alpine.data('gdlBrowser', gdlBrowserData);
Alpine.data('feedBrowser', feedBrowserData);
