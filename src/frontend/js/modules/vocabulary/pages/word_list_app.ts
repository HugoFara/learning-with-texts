/**
 * Word List App - Alpine.js component for word/term management.
 *
 * This component provides a full reactive SPA for:
 * - Filtered, paginated word list with sorting
 * - Bulk selection and actions
 * - Inline editing of translations and romanizations
 * - Mobile-responsive table/card views
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.0.0
 */

import Alpine from 'alpinejs';
import { initIcons } from '@shared/icons/lucide_icons';
import { t } from '@shared/i18n/translator';
import { getCsrfToken } from '@shared/api/client';
import {
  WordsApi,
  type WordItem,
  type PaginationInfo,
  type FilterOptions,
  type WordListFilters
} from '@modules/vocabulary/api/words_api';

const STORAGE_KEY = 'lwt_word_list_filters';

/**
 * Page configuration from PHP.
 */
interface PageConfig {
  activeLanguageId: number;
  perPage: number;
  csrfToken?: string;
}

/**
 * Editing state for inline edit.
 */
interface EditingState {
  id: number;
  field: 'translation' | 'romanization';
}

/**
 * Column visibility settings.
 */
export interface ColumnVisibility {
  romanization: boolean;
  translation: boolean;
  tags: boolean;
  sentence: boolean;
  status: boolean;
  score: boolean;
}

const COLUMNS_STORAGE_KEY = 'lwt_word_list_columns';

/**
 * Alpine.js component data interface.
 */
export interface WordListData {
  // State
  loading: boolean;
  words: WordItem[];
  filters: WordListFilters;
  pagination: PaginationInfo;
  filterOptions: FilterOptions;
  marked: Set<number>;

  // Column visibility
  columns: ColumnVisibility;
  columnsOpen: boolean;
  toggleColumn(col: keyof ColumnVisibility): void;
  toggleColumnsDropdown(): void;
  closeColumnsDropdown(): void;
  loadColumnState(): void;
  saveColumnState(): void;
  updateRomanizationVisibility(): void;

  // Inline edit state
  editingWord: EditingState | null;
  editValue: string;
  editSaving: boolean;

  // Lifecycle
  init(): Promise<void>;

  // Data loading
  loadWords(): Promise<void>;
  loadFilterOptions(): Promise<void>;

  // Filter methods
  setFilter(key: keyof WordListFilters, value: unknown): void;
  setFilterFromEvent(key: keyof WordListFilters, event: Event): void;
  syncQueryValue(event: Event): void;
  applyQueryFilter(): void;
  applyFilter(key: keyof WordListFilters): void;
  resetFilters(): void;
  loadFilterState(): void;
  saveFilterState(): void;

  // Pagination
  perPageOptions: number[];
  setPerPage(value: number | string): void;
  setPerPageFromEvent(event: Event): void;
  goToPage(page: number): Promise<void>;
  goToPrevPage(): Promise<void>;
  goToNextPage(): Promise<void>;
  goToLastPage(): Promise<void>;
  isFirstPage(): boolean;
  isLastPage(): boolean;
  paginationText(): string;

  // Selection
  markAll(checked: boolean): void;
  toggleMark(wordId: number, checked: boolean): void;
  isMarked(wordId: number): boolean;
  getMarkedIds(): number[];
  getMarkedCount(): number;

  // Bulk actions
  handleMultiAction(event: Event): Promise<void>;
  handleAllAction(event: Event): Promise<void>;

  // Inline edit
  startEdit(wordId: number, field: 'translation' | 'romanization'): void;
  saveEdit(): Promise<void>;
  cancelEdit(): void;
  isEditing(wordId: number, field: 'translation' | 'romanization'): boolean;

  // Helpers
  formatScore(score: number): string;
  getStatusClass(status: number): string;
  statusDisplay(word: WordItem): string;
  getDisplayValue(word: WordItem, field: 'translation' | 'romanization'): string;
  termCountLabel(): string;
  pageLabel(): string;
  markedCountLabel(): string;

  // Page title
  updatePageTitle(): void;
  getSelectedLanguageName(): string;
}

/**
 * Read page configuration from the embedded JSON script tag.
 */
function getPageConfig(): PageConfig {
  const configEl = document.getElementById('word-list-config');
  if (configEl) {
    try {
      return JSON.parse(configEl.textContent || '{}');
    } catch {
      // Invalid JSON
    }
  }
  return { activeLanguageId: 0, perPage: 50 };
}

/**
 * Create the word list app Alpine.js component.
 */
export function wordListData(): WordListData {
  const config = getPageConfig();

  return {
    loading: true,
    words: [],
    filters: {
      lang: config.activeLanguageId || null,
      text_id: null,
      status: '',
      query: '',
      query_mode: 'term,rom,transl',
      regex_mode: '',
      tag1: null,
      tag2: null,
      tag12: 0,
      sort: 1,
      page: 1,
      per_page: config.perPage || 50
    },
    pagination: {
      page: 1,
      per_page: config.perPage || 50,
      total: 0,
      total_pages: 0
    },
    filterOptions: {
      languages: [],
      texts: [],
      tags: [],
      statuses: [],
      sorts: []
    },
    marked: new Set(),

    perPageOptions: [25, 50, 100, 200, 500],
    columns: {
      romanization: true,
      translation: true,
      tags: true,
      sentence: false,
      status: true,
      score: true
    },
    columnsOpen: false,

    editingWord: null,
    editValue: '',
    editSaving: false,

    async init() {
      this.loadColumnState();
      this.loadFilterState();
      await this.loadFilterOptions();
      await this.loadWords();
      this.loading = false;
      this.updatePageTitle();

      // Refresh icons after render
      setTimeout(() => {
        initIcons();
      }, 0);
    },

    async loadWords() {
      const response = await WordsApi.getList(this.filters);

      if (response.data) {
        this.words = response.data.words;
        this.pagination = response.data.pagination;
        // Update filters with actual page from response
        this.filters.page = response.data.pagination.page;
      }

      // Refresh icons
      setTimeout(() => {
        initIcons();
      }, 0);
    },

    async loadFilterOptions() {
      const langId =
        this.filters.lang !== null && this.filters.lang !== ''
          ? Number(this.filters.lang)
          : null;
      const response = await WordsApi.getFilterOptions(langId);

      if (response.data) {
        this.filterOptions = response.data;
        this.updateRomanizationVisibility();
      }
    },

    updateRomanizationVisibility() {
      if (!this.filters.lang) return;
      const langId = Number(this.filters.lang);
      const lang = this.filterOptions.languages.find((l) => l.id === langId);
      if (lang) {
        this.columns.romanization = lang.showRomanization;
        this.saveColumnState();
      }
    },

    setFilter(key, value) {
      // Select elements return strings; coerce numeric filter keys
      if (key === 'sort' || key === 'page' || key === 'per_page') {
        value = Number(value);
      }
      (this.filters as Record<string, unknown>)[key] = value;

      // Reset to page 1 when filter changes (except for page changes)
      if (key !== 'page') {
        this.filters.page = 1;
        this.marked.clear();
      }

      this.saveFilterState();
      this.loadWords();

      // Reload filter options when language changes (to update texts list)
      if (key === 'lang') {
        this.filters.text_id = null;
        this.loadFilterOptions();
        this.updatePageTitle();
      }
    },

    setFilterFromEvent(key: keyof WordListFilters, event: Event) {
      const target = event.target as HTMLSelectElement;
      this.setFilter(key, target.value);
    },

    syncQueryValue(event: Event) {
      this.filters.query = (event.target as HTMLInputElement).value;
    },

    applyQueryFilter() {
      this.setFilter('query', this.filters.query);
    },

    applyFilter(key) {
      this.setFilter(key, (this.filters as Record<string, unknown>)[key]);
    },

    resetFilters() {
      const config = getPageConfig();
      this.filters = {
        lang: null,
        text_id: null,
        status: '',
        query: '',
        query_mode: 'term,rom,transl',
        regex_mode: '',
        tag1: null,
        tag2: null,
        tag12: 0,
        sort: 1,
        page: 1,
        per_page: config.perPage || 50
      };
      this.marked.clear();

      try {
        localStorage.removeItem(STORAGE_KEY);
      } catch {
        // localStorage unavailable
      }

      this.loadFilterOptions();
      this.loadWords();
    },

    loadFilterState() {
      // First check URL params
      const urlParams = new URLSearchParams(window.location.search);
      if (urlParams.has('lang')) {
        const langParam = urlParams.get('lang');
        this.filters.lang = langParam ? parseInt(langParam, 10) : null;
      }

      // Then check localStorage for other filters
      try {
        const stored = localStorage.getItem(STORAGE_KEY);
        if (stored) {
          const parsed = JSON.parse(stored);
          // Merge stored filters with current (URL params take precedence)
          this.filters = { ...this.filters, ...parsed };
          // But keep URL lang param if present
          if (urlParams.has('lang')) {
            const langParam = urlParams.get('lang');
            this.filters.lang = langParam ? parseInt(langParam, 10) : null;
          }
        }
      } catch {
        // localStorage unavailable or invalid JSON
      }
    },

    saveFilterState() {
      try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(this.filters));
      } catch {
        // localStorage unavailable
      }
    },

    setPerPage(value: number | string) {
      this.filters.per_page = Number(value);
      this.filters.page = 1;
      this.marked.clear();
      this.saveFilterState();
      this.loadWords();
    },

    setPerPageFromEvent(event: Event) {
      const target = event.target as HTMLSelectElement;
      this.setPerPage(target.value);
    },

    toggleColumn(col: keyof ColumnVisibility) {
      this.columns[col] = !this.columns[col];
      this.saveColumnState();
    },

    toggleColumnsDropdown() {
      this.columnsOpen = !this.columnsOpen;
    },

    closeColumnsDropdown() {
      this.columnsOpen = false;
    },

    loadColumnState() {
      try {
        const stored = localStorage.getItem(COLUMNS_STORAGE_KEY);
        if (stored) {
          const parsed = JSON.parse(stored);
          this.columns = { ...this.columns, ...parsed };
        }
      } catch {
        // localStorage unavailable
      }
    },

    saveColumnState() {
      try {
        localStorage.setItem(COLUMNS_STORAGE_KEY, JSON.stringify(this.columns));
      } catch {
        // localStorage unavailable
      }
    },

    async goToPage(page: number) {
      if (page < 1 || page > this.pagination.total_pages) return;
      this.setFilter('page', page);
      window.scrollTo({ top: 0, behavior: 'smooth' });
    },

    async goToPrevPage() {
      await this.goToPage(this.pagination.page - 1);
    },

    async goToNextPage() {
      await this.goToPage(this.pagination.page + 1);
    },

    async goToLastPage() {
      await this.goToPage(this.pagination.total_pages);
    },

    isFirstPage(): boolean {
      return this.pagination.page <= 1;
    },

    isLastPage(): boolean {
      return this.pagination.page >= this.pagination.total_pages;
    },

    paginationText(): string {
      if (this.pagination.total_pages === 0) return '0 / 0';
      return this.pagination.page + ' / ' + this.pagination.total_pages;
    },

    markAll(checked: boolean) {
      if (checked) {
        this.words.forEach((w) => this.marked.add(w.id));
      } else {
        this.marked.clear();
      }
    },

    toggleMark(wordId: number, checked: boolean) {
      if (checked) {
        this.marked.add(wordId);
      } else {
        this.marked.delete(wordId);
      }
    },

    isMarked(wordId: number): boolean {
      return this.marked.has(wordId);
    },

    getMarkedIds(): number[] {
      return Array.from(this.marked);
    },

    getMarkedCount(): number {
      return this.marked.size;
    },

    async handleMultiAction(event: Event) {
      const select = event.target as HTMLSelectElement;
      const action = select.value;
      if (!action) return;

      const ids = this.getMarkedIds();
      if (ids.length === 0) {
        alert(t('vocabulary.list.no_terms_selected'));
        select.value = '';
        return;
      }

      let data: string | undefined;

      // Handle actions that need extra data
      if (action === 'addtag' || action === 'deltag') {
        const tag = prompt(t('vocabulary.list.prompt_tag'));
        if (!tag) {
          select.value = '';
          return;
        }
        if (tag.includes(' ') || tag.includes(',') || tag.length > 20) {
          alert(t('vocabulary.list.invalid_tag'));
          select.value = '';
          return;
        }
        data = tag;
      }

      // Confirm destructive actions
      if (action === 'del') {
        if (!confirm(t('vocabulary.list.confirm_delete', { count: ids.length }))) {
          select.value = '';
          return;
        }
      }

      // Handle review action - redirect to review page
      if (action === 'review') {
        const reviewUrl = `/review?selection=${ids.join(',')}`;
        window.location.href = reviewUrl;
        return;
      }

      // Handle export actions - these need form submission
      if (action === 'exp' || action === 'expann' || action === 'exptsv') {
        this.submitExportForm(action, ids);
        select.value = '';
        return;
      }

      // Anki .apkg export of selected terms — POSTs marked[] to the apkg
      // endpoint, which streams the file as a download.
      if (action === 'expapkg') {
        this.postApkgExport(ids);
        select.value = '';
        return;
      }

      const response = await WordsApi.bulkAction(ids, action, data);

      if (response.data?.success) {
        this.marked.clear();
        await this.loadWords();
      } else {
        alert(response.data?.message || response.error || t('vocabulary.list.action_failed'));
      }

      select.value = '';
    },

    async handleAllAction(event: Event) {
      const select = event.target as HTMLSelectElement;
      const action = select.value;
      if (!action) return;

      // Anki .apkg actions: not selection-based, not destructive, no confirm.
      if (action === 'allexpapkg') {
        this.postApkgExport([]);
        select.value = '';
        return;
      }
      if (action === 'allimpapkg') {
        window.location.href = '/vocabulary/apkg/import';
        select.value = '';
        return;
      }

      if (
        !confirm(
          t('vocabulary.list.confirm_apply_all', { count: this.pagination.total })
        )
      ) {
        select.value = '';
        return;
      }

      let data: string | undefined;

      if (action.endsWith('addtag') || action.endsWith('deltag')) {
        const tag = prompt(t('vocabulary.list.prompt_tag_simple'));
        if (!tag) {
          select.value = '';
          return;
        }
        data = tag;
      }

      // Strip 'all' prefix for action codes
      const actionCode = action.replace(/^all/, '');

      const response = await WordsApi.allAction(this.filters, actionCode, data);

      if (response.data?.success) {
        await this.loadWords();
      } else {
        alert(response.data?.message || response.error || t('vocabulary.list.action_failed'));
      }

      select.value = '';
    },

    startEdit(wordId: number, field: 'translation' | 'romanization') {
      const word = this.words.find((w) => w.id === wordId);
      if (!word) return;

      this.editingWord = { id: wordId, field };

      // Get current value
      const currentValue = field === 'translation' ? word.translation : word.romanization;
      this.editValue = currentValue === '*' ? '' : currentValue;

      // Focus the textarea after render
      setTimeout(() => {
        const textarea = document.querySelector(
          `[data-edit-id="${wordId}"][data-edit-field="${field}"]`
        ) as HTMLTextAreaElement;
        if (textarea) {
          textarea.focus();
          textarea.select();
        }
      }, 0);
    },

    async saveEdit() {
      if (!this.editingWord) return;

      this.editSaving = true;
      const { id, field } = this.editingWord;

      const response = await WordsApi.inlineEdit(id, field, this.editValue);

      if (response.data?.success) {
        // Update the word in the list
        const word = this.words.find((w) => w.id === id);
        if (word) {
          if (field === 'translation') {
            word.translation = response.data.value;
          } else {
            word.romanization = response.data.value;
          }
        }
      } else {
        alert(response.data?.error || response.error || t('vocabulary.list.save_failed'));
      }

      this.editingWord = null;
      this.editValue = '';
      this.editSaving = false;
    },

    cancelEdit() {
      this.editingWord = null;
      this.editValue = '';
    },

    isEditing(wordId: number, field: 'translation' | 'romanization'): boolean {
      return (
        this.editingWord !== null &&
        this.editingWord.id === wordId &&
        this.editingWord.field === field
      );
    },

    /**
     * The "due" column: whole days until the term comes up for review.
     *
     * This used to be the legacy score rendered as a percentage of a formula
     * nobody could read off the screen. Days until due is the same information
     * the scheduler actually acts on, and negative means overdue.
     */
    formatScore(days: number): string {
      if (days <= 0) return t('vocabulary.list.due_now');
      if (days < 30) return t('vocabulary.list.due_days', { count: String(days) });
      if (days < 365) {
        return t('vocabulary.list.due_months', { count: String(Math.round(days / 30)) });
      }
      return t('vocabulary.list.due_years', { count: String(Math.round(days / 365)) });
    },

    getStatusClass(status: number): string {
      if (status === 99) return 'is-info';
      if (status === 98) return 'is-light';
      if (status >= 5) return 'is-success';
      if (status >= 3) return 'is-warning';
      return 'is-danger';
    },

    statusDisplay(word: WordItem): string {
      if (word.status >= 98) return word.statusAbbr;
      return word.statusAbbr + '/' + word.days;
    },

    getDisplayValue(
      word: WordItem,
      field: 'translation' | 'romanization'
    ): string {
      const value = field === 'translation' ? word.translation : word.romanization;
      return value || '*';
    },

    termCountLabel(): string {
      const total = this.pagination.total;
      return t(
        total === 1 ? 'vocabulary.list.term_count_one' : 'vocabulary.list.term_count_other',
        { count: total }
      );
    },

    pageLabel(): string {
      return t('vocabulary.list.page_x_of_y', {
        page: this.pagination.page,
        total: this.pagination.total_pages
      });
    },

    markedCountLabel(): string {
      return t('vocabulary.list.marked_count', { count: this.getMarkedCount() });
    },

    /**
     * Get the name of the currently selected language.
     */
    getSelectedLanguageName(): string {
      if (!this.filters.lang) {
        return '';
      }
      const langId = Number(this.filters.lang);
      const lang = this.filterOptions.languages.find((l) => l.id === langId);
      return lang ? lang.name : '';
    },

    /**
     * Update the page title (h1) and document title based on selected language.
     */
    updatePageTitle(): void {
      const langName = this.getSelectedLanguageName();
      const title = langName
        ? t('vocabulary.list.title_lang_terms', { lang: langName })
        : t('vocabulary.list.title_terms');

      // Update the h1 element
      const h1 = document.querySelector('h1');
      if (h1) {
        // Preserve any debug span that might be present
        const debugSpan = h1.querySelector('.red');
        h1.textContent = title;
        if (debugSpan) {
          h1.appendChild(document.createTextNode(' '));
          h1.appendChild(debugSpan);
        }
      }

      // Update the document title
      document.title = t('vocabulary.list.document_title', { title });
    },

    // Helper method to create and submit export form
    submitExportForm(action: string, ids: number[]) {
      const form = document.createElement('form');
      form.method = 'POST';
      form.action = '/words';

      // CsrfMiddleware rejects POST/PUT/DELETE/PATCH without an
      // _csrf_token field or X-CSRF-TOKEN header. Inject the token
      // from the meta tag added in PageLayoutHelper.
      const csrf = getCsrfToken();
      if (csrf) {
        const csrfField = document.createElement('input');
        csrfField.type = 'hidden';
        csrfField.name = '_csrf_token';
        csrfField.value = csrf;
        form.appendChild(csrfField);
      }

      // Add marked IDs
      ids.forEach((id) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'marked[]';
        input.value = String(id);
        form.appendChild(input);
      });

      // Add action
      const actionInput = document.createElement('input');
      actionInput.type = 'hidden';
      actionInput.name = 'markaction';
      actionInput.value = action;
      form.appendChild(actionInput);

      document.body.appendChild(form);
      form.submit();
    },

    // POST to the apkg export endpoint. Empty ids = whole-language export
    // (the server treats missing/empty marked[] as "all terms"). The browser
    // handles the download via the Content-Disposition response header.
    postApkgExport(ids: number[]) {
      const form = document.createElement('form');
      form.method = 'POST';
      form.action = '/vocabulary/apkg/export';

      const langInput = document.createElement('input');
      langInput.type = 'hidden';
      langInput.name = 'lang_id';
      langInput.value = String(config.activeLanguageId);
      form.appendChild(langInput);

      ids.forEach((id) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'marked[]';
        input.value = String(id);
        form.appendChild(input);
      });

      const csrfInput = document.createElement('input');
      csrfInput.type = 'hidden';
      csrfInput.name = '_csrf_token';
      csrfInput.value = config.csrfToken || '';
      form.appendChild(csrfInput);

      document.body.appendChild(form);
      form.submit();
    },
  } as WordListData & {
    submitExportForm: (action: string, ids: number[]) => void;
    postApkgExport: (ids: number[]) => void;
  };
}

/**
 * Initialize the word list app Alpine.js component.
 */
export function initWordListAlpine(): void {
  Alpine.data('wordListApp', wordListData);
}

// Expose for global access
declare global {
  interface Window {
    wordListData: typeof wordListData;
  }
}

window.wordListData = wordListData;

// Register Alpine data component immediately (before Alpine.start() in main.ts)
initWordListAlpine();
