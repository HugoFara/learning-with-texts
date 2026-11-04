/**
 * Archived Texts Grouped App - Alpine.js component for grouped archived texts by language.
 *
 * This component manages:
 * - Collapsible language sections with state persistence
 * - Lazy loading of archived texts per language
 * - Per-language pagination with "Show More"
 * - Per-language bulk selection and actions
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.0.0
 */

import Alpine from 'alpinejs';
import { initIcons } from '@shared/icons/lucide_icons';
import { apiGet, getCsrfToken } from '@shared/api/client';
import { confirmDelete } from '@shared/utils/ui_utilities';

const STORAGE_KEY = 'lwt_collapsed_archived_languages';

/**
 * Language with archived text count from API.
 */
interface LanguageWithArchivedTexts {
  id: number;
  name: string;
  text_count: number;
}

/**
 * Archived text item from API.
 */
interface ArchivedTextItem {
  id: number;
  title: string;
  has_audio: boolean;
  source_uri: string;
  has_source: boolean;
  annotated: boolean;
  taglist: string;
}

/**
 * Pagination info from API.
 */
interface PaginationInfo {
  current_page: number;
  per_page: number;
  total: number;
  total_pages: number;
}

/**
 * State for archived texts within a single language section.
 */
interface LanguageArchivedTextsState {
  texts: ArchivedTextItem[];
  pagination: PaginationInfo;
  loading: boolean;
  marked: Set<number>;
}

/**
 * Response from languages/with-archived-texts API.
 */
interface LanguagesWithArchivedTextsResponse {
  languages: LanguageWithArchivedTexts[];
}

/**
 * Response from texts/archived-by-language API.
 */
interface ArchivedTextsByLanguageResponse {
  texts: ArchivedTextItem[];
  pagination: PaginationInfo;
}

/**
 * Page configuration from PHP.
 */
interface PageConfig {
  activeLanguageId: number;
}

/**
 * Alpine.js component data interface.
 */
export interface ArchivedTextsGroupedData {
  // State
  loading: boolean;
  languages: LanguageWithArchivedTexts[];
  collapsedLanguages: number[];
  languageStates: Map<number, LanguageArchivedTextsState>;
  sort: number;
  activeLanguageId: number;

  // Lifecycle
  init(): Promise<void>;

  // Data loading
  loadLanguages(): Promise<void>;
  loadTextsForLanguage(langId: number, page?: number): Promise<void>;

  // Collapse state
  isCollapsed(langId: number): boolean;
  toggleLanguage(langId: number): Promise<void>;
  saveCollapseState(): void;
  loadCollapseState(): void;
  initializeDefaultCollapseState(): void;

  // Text operations
  getTextsForLanguage(langId: number): ArchivedTextItem[];
  hasMoreTexts(langId: number): boolean;
  loadMoreTexts(langId: number): Promise<void>;
  isLoadingMore(langId: number): boolean;

  // Selection
  markAll(langId: number, checked: boolean): void;
  toggleMark(langId: number, textId: number, checked: boolean): void;
  isMarked(langId: number, textId: number): boolean;
  hasMarkedInLanguage(langId: number): boolean;
  getMarkedIds(langId: number): number[];
  getMarkedCount(langId: number): number;

  // Actions
  handleMultiAction(langId: number, event: Event): Promise<void>;
  handleDelete(event: Event, url: string): Promise<void>;
  handleRestDelete(event: Event, url: string): Promise<void>;
  handlePostAction(event: Event, url: string): void;

  // Sorting
  handleSortChange(event: Event): void;

  // Utility
  parseTags(tagList: string): string[];

  // CSP-friendly view helpers — Alpine's CSP build can't eval arrow
  // functions or chained array methods, so any complex inline
  // expression has to live on the component instead.
  totalArchivedSummary(): string;
  archivedCountLabel(text_count: number): string;
  collapseAriaLabel(langId: number, langName: string): string;
  chevronIcon(langId: number): string;
}

/**
 * Read page configuration from the embedded JSON script tag.
 */
function getPageConfig(): PageConfig {
  const configEl = document.getElementById('archived-texts-grouped-config');
  if (configEl) {
    try {
      return JSON.parse(configEl.textContent || '{}');
    } catch {
      // Invalid JSON
    }
  }
  return { activeLanguageId: 0 };
}

/**
 * Create the archived texts grouped app Alpine.js component.
 */
export function archivedTextsGroupedData(): ArchivedTextsGroupedData {
  const config = getPageConfig();

  return {
    loading: true,
    languages: [],
    collapsedLanguages: [],
    languageStates: new Map(),
    sort: 1,
    activeLanguageId: config.activeLanguageId,

    async init() {
      this.loadCollapseState();
      await this.loadLanguages();

      // If no stored collapse state, collapse all except active language
      if (!localStorage.getItem(STORAGE_KEY)) {
        this.initializeDefaultCollapseState();
      }

      // Load texts for expanded languages (up to first 3)
      let loadedCount = 0;
      for (const lang of this.languages) {
        if (!this.isCollapsed(lang.id) && loadedCount < 3) {
          await this.loadTextsForLanguage(lang.id);
          loadedCount++;
        }
      }

      this.loading = false;

      // Refresh icons after render
      setTimeout(() => {
        initIcons();
      }, 0);
    },

    async loadLanguages() {
      const response = await apiGet<LanguagesWithArchivedTextsResponse>(
        '/languages/with-archived-texts'
      );
      if (response.data) {
        this.languages = response.data.languages;
        // Initialize state for each language
        for (const lang of this.languages) {
          this.languageStates.set(lang.id, {
            texts: [],
            pagination: {
              current_page: 0,
              per_page: 10,
              total: lang.text_count,
              total_pages: Math.ceil(lang.text_count / 10)
            },
            loading: false,
            marked: new Set()
          });
        }
      }
    },

    async loadTextsForLanguage(langId: number, page: number = 1) {
      const state = this.languageStates.get(langId);
      if (!state) return;

      state.loading = true;

      const response = await apiGet<ArchivedTextsByLanguageResponse>(
        `/texts/archived-by-language/${langId}`,
        { page, per_page: 10, sort: this.sort }
      );

      if (response.data) {
        if (page === 1) {
          state.texts = response.data.texts;
        } else {
          state.texts.push(...response.data.texts);
        }
        state.pagination = response.data.pagination;
      }

      state.loading = false;

      // Refresh icons
      setTimeout(() => {
        initIcons();
      }, 0);
    },

    // Collapse state management
    isCollapsed(langId: number): boolean {
      return this.collapsedLanguages.includes(langId);
    },

    async toggleLanguage(langId: number) {
      const index = this.collapsedLanguages.indexOf(langId);
      if (index > -1) {
        // Expanding
        this.collapsedLanguages.splice(index, 1);
        // Load texts when expanding if not loaded
        const state = this.languageStates.get(langId);
        if (state && state.texts.length === 0) {
          await this.loadTextsForLanguage(langId);
        }
      } else {
        // Collapsing
        this.collapsedLanguages.push(langId);
      }
      this.saveCollapseState();

      // Refresh icons
      setTimeout(() => {
        initIcons();
      }, 0);
    },

    saveCollapseState() {
      try {
        localStorage.setItem(
          STORAGE_KEY,
          JSON.stringify(this.collapsedLanguages)
        );
      } catch {
        // localStorage unavailable
      }
    },

    loadCollapseState() {
      try {
        const stored = localStorage.getItem(STORAGE_KEY);
        if (stored) {
          this.collapsedLanguages = JSON.parse(stored);
        }
      } catch {
        this.collapsedLanguages = [];
      }
    },

    initializeDefaultCollapseState() {
      // Collapse all languages except the active one
      this.collapsedLanguages = this.languages
        .filter((lang) => lang.id !== this.activeLanguageId)
        .map((lang) => lang.id);
      this.saveCollapseState();
    },

    // Text operations
    getTextsForLanguage(langId: number): ArchivedTextItem[] {
      return this.languageStates.get(langId)?.texts ?? [];
    },

    hasMoreTexts(langId: number): boolean {
      const state = this.languageStates.get(langId);
      if (!state) return false;
      return state.pagination.current_page < state.pagination.total_pages;
    },

    async loadMoreTexts(langId: number) {
      const state = this.languageStates.get(langId);
      if (!state || state.loading) return;
      await this.loadTextsForLanguage(langId, state.pagination.current_page + 1);
    },

    isLoadingMore(langId: number): boolean {
      return this.languageStates.get(langId)?.loading ?? false;
    },

    // Selection
    markAll(langId: number, checked: boolean) {
      const state = this.languageStates.get(langId);
      if (!state) return;

      if (checked) {
        state.texts.forEach((t) => state.marked.add(t.id));
      } else {
        state.marked.clear();
      }
    },

    toggleMark(langId: number, textId: number, checked: boolean) {
      const state = this.languageStates.get(langId);
      if (!state) return;

      if (checked) {
        state.marked.add(textId);
      } else {
        state.marked.delete(textId);
      }
    },

    isMarked(langId: number, textId: number): boolean {
      return this.languageStates.get(langId)?.marked.has(textId) ?? false;
    },

    hasMarkedInLanguage(langId: number): boolean {
      const state = this.languageStates.get(langId);
      return state ? state.marked.size > 0 : false;
    },

    getMarkedIds(langId: number): number[] {
      const state = this.languageStates.get(langId);
      return state ? Array.from(state.marked) : [];
    },

    getMarkedCount(langId: number): number {
      return this.languageStates.get(langId)?.marked.size ?? 0;
    },

    // Actions
    async handleMultiAction(langId: number, event: Event) {
      const select = event.target as HTMLSelectElement;
      const action = select.value;
      if (!action) return;

      const markedIds = this.getMarkedIds(langId);
      if (markedIds.length === 0) {
        select.value = '';
        return;
      }

      // Create a temporary form with the marked IDs
      const form = document.createElement('form');
      form.method = 'POST';
      form.action = '/text/archived';

      // CsrfMiddleware rejects POST/PUT/DELETE/PATCH without an
      // _csrf_token field or X-CSRF-TOKEN header.
      const csrf = getCsrfToken();
      if (csrf) {
        const csrfField = document.createElement('input');
        csrfField.type = 'hidden';
        csrfField.name = '_csrf_token';
        csrfField.value = csrf;
        form.appendChild(csrfField);
      }

      // Add marked text IDs
      markedIds.forEach((id) => {
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

      // Handle special actions that need confirmation
      if (action === 'del') {
        if (!await confirmDelete()) {
          select.value = '';
          return;
        }
      }

      // Submit form
      document.body.appendChild(form);
      form.submit();
    },

    async handleDelete(event: Event, url: string) {
      event.preventDefault();
      if (await confirmDelete()) {
        window.location.href = url;
      }
    },

    async handleRestDelete(event: Event, url: string) {
      event.preventDefault();
      if (await confirmDelete()) {
        const headers: Record<string, string> = {
          'X-Requested-With': 'XMLHttpRequest',
        };
        const csrf = getCsrfToken();
        if (csrf) {
          headers['X-CSRF-TOKEN'] = csrf;
        }
        fetch(url, { method: 'DELETE', headers }).then(() => {
          window.location.reload();
        }).catch((error) => {
          console.error('Delete failed:', error);
          alert('Failed to delete. Please try again.');
        });
      }
    },

    handlePostAction(event: Event, url: string) {
      event.preventDefault();
      const form = document.createElement('form');
      form.method = 'POST';
      form.action = url;
      form.style.display = 'none';
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
      document.body.appendChild(form);
      form.submit();
    },

    // Sorting
    handleSortChange(event: Event) {
      const select = event.target as HTMLSelectElement;
      this.sort = parseInt(select.value, 10) || 1;

      // Reload all loaded languages with new sort
      for (const lang of this.languages) {
        const state = this.languageStates.get(lang.id);
        if (state && state.texts.length > 0) {
          state.texts = [];
          state.pagination.current_page = 0;
          if (!this.isCollapsed(lang.id)) {
            this.loadTextsForLanguage(lang.id);
          }
        }
      }
    },

    // Utility - parse tags from comma-separated string
    parseTags(tagList: string): string[] {
      if (!tagList || tagList.trim() === '') {
        return [];
      }
      return tagList.split(',').map(t => t.trim()).filter(t => t !== '');
    },

    // CSP-friendly helpers used by the template (see interface).
    totalArchivedSummary(): string {
      let total = 0;
      for (const lang of this.languages) {
        total += lang.text_count;
      }
      const plural = this.languages.length === 1 ? '' : 's';
      return `${total} archived texts in ${this.languages.length} language${plural}`;
    },
    archivedCountLabel(text_count: number): string {
      return text_count + ' archived text' + (text_count === 1 ? '' : 's');
    },
    collapseAriaLabel(langId: number, langName: string): string {
      return this.isCollapsed(langId)
        ? 'Expand ' + langName + ' texts'
        : 'Collapse ' + langName + ' texts';
    },
    chevronIcon(langId: number): string {
      return this.isCollapsed(langId) ? 'chevron-right' : 'chevron-down';
    }
  };
}

/**
 * Initialize the archived texts grouped app Alpine.js component.
 */
export function initArchivedTextsGroupedAlpine(): void {
  Alpine.data('archivedTextsGroupedApp', archivedTextsGroupedData);
}

// Expose for global access
declare global {
  interface Window {
    archivedTextsGroupedData: typeof archivedTextsGroupedData;
  }
}

window.archivedTextsGroupedData = archivedTextsGroupedData;

// Register Alpine data component immediately (before Alpine.start() in main.ts)
initArchivedTextsGroupedAlpine();
