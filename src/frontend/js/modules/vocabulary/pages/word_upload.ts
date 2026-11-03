/**
 * Word Upload Module - Alpine.js component for word import.
 *
 * Handles word import form, import mode selection, and paginated
 * display of imported terms. Supports three import modes:
 * - Frequency word import with Wiktionary enrichment
 * - Curated dictionary browser
 * - Manual upload (CSV/TSV file, paste, dictionary file)
 *
 * @author  HugoFara <git@hugofara.net>
 * @license Unlicense <http://unlicense.org/>
 * @since   3.0.0 Extracted from PHP inline scripts
 * @since   3.1.0 Migrated to Alpine.js component
 */

import Alpine from 'alpinejs';
import { apiPost } from '@shared/api/client';
import { escapeHtml, renderTags } from '@shared/utils/html_utils';
import { statuses } from '@shared/stores/app_data';
import { iconHtml } from '@shared/icons/icons';

// Interface for imported term record
interface ImportedTerm {
  WoID: number;
  WoText: string;
  WoTranslation: string;
  WoRomanization: string;
  WoSentence: string;
  WoStatus: number;
  SentOK: number;
  taglist: string;
}

// Interface for navigation data
interface NavigationData {
  current_page: number;
  total_pages: number;
}

// Interface for API response
interface ImportedTermsResponse {
  navigation: NavigationData;
  terms: ImportedTerm[];
}

/**
 * Configuration for upload result view.
 */
export interface UploadResultConfig {
  lastUpdate: string;
  rtl: boolean;
  recno: number;
}

/**
 * Column label/example maps for preview.
 */
const COL_LABELS: Record<string, string> = {
  w: 'Term', t: 'Translation', r: 'Romanization', s: 'Sentence', g: 'Tags'
};
const COL_EXAMPLES: Record<string, string> = {
  w: 'Haus', t: 'house', r: 'haus', s: 'Das Haus ist gross.', g: 'A1 housing'
};

/**
 * Page config read from JSON script tag.
 */
interface PageConfig {
  activeTab?: string;
  currentLanguageId?: number;
  currentLanguageName?: string;
  isFrequencyAvailable?: boolean;
  importUrl?: string;
  enrichUrl?: string;
  csrfToken?: string;
}

/**
 * Page-level wrapper component for main tab state and frequency import.
 *
 * Manages three tabs: frequency, dictionary, manual.
 * Handles frequency word import with enrichment (AJAX-driven).
 */
export function wordUploadPageApp() {
  const configEl = document.getElementById('word-upload-page-config');
  let cfg: PageConfig = {};
  if (configEl) {
    try {
      cfg = JSON.parse(configEl.textContent || '{}');
    } catch {
      // use default
    }
  }

  const importUrl = cfg.importUrl || '';
  const enrichUrl = cfg.enrichUrl || '';
  const csrfToken = cfg.csrfToken || '';

  return {
    activeTab: cfg.activeTab || 'frequency',
    selectedLanguageId: cfg.currentLanguageId || 0,
    selectedLanguageName: cfg.currentLanguageName || '',

    // Frequency import state
    freqStep: 'choose' as string,
    freqSize: 100,
    freqMode: 'translation' as string,
    freqResult: { imported: 0, skipped: 0, total: 0 },
    enrichStats: { done: 0, failed: 0, total: 0 },
    enrichProgress: 0,
    enrichWarning: '',
    _stopEnrichment: false,
    freqError: '',

    setActiveTab(tab: string): void {
      this.activeTab = tab;
    },

    sizeClass(value: number): string {
      return this.freqSize === value ? 'button is-info is-selected' : 'button';
    },

    setSize(value: number): void {
      this.freqSize = value;
    },

    freqEnrichingLabel(): string {
      return this.freqMode === 'translation'
        ? 'Fetching translations...'
        : 'Fetching definitions...';
    },

    freqEnrichedModeLabel(): string {
      return this.freqMode === 'translation' ? 'translations' : 'definitions';
    },

    async startFrequencyImport(): Promise<void> {
      this.freqStep = 'importing';
      this.freqError = '';

      try {
        const formData = new FormData();
        formData.append('count', String(this.freqSize));
        formData.append('_csrf_token', csrfToken);

        const response = await fetch(importUrl, {
          method: 'POST',
          body: formData,
        });

        const data = await response.json();

        if (!response.ok) {
          this.freqError = data.error || 'Unknown error occurred.';
          this.freqStep = 'error';
          return;
        }

        this.freqResult = data;

        if (data.imported > 0) {
          this.enrichStats = { done: 0, failed: 0, total: data.imported };
          this._stopEnrichment = false;
          this.freqStep = 'enriching';
          await this.enrichAll();
        }

        this.freqStep = 'done';
      } catch {
        this.freqError = 'Network error. Please check your connection.';
        this.freqStep = 'error';
      }
    },

    async enrichAll(): Promise<void> {
      while (!this._stopEnrichment) {
        const formData = new FormData();
        formData.append('mode', this.freqMode);
        formData.append('_csrf_token', csrfToken);

        const response = await fetch(enrichUrl, {
          method: 'POST',
          body: formData,
        });

        const data = await response.json();

        if (!response.ok) {
          this.enrichWarning = data.error || 'Enrichment encountered an error.';
          return;
        }

        this.enrichStats.done = data.total - data.remaining;
        this.enrichStats.total = data.total;
        this.enrichStats.failed += data.failed;
        this.enrichProgress = data.total > 0
          ? Math.round(((data.total - data.remaining) / data.total) * 100)
          : 100;

        if (data.warning) {
          this.enrichWarning = data.warning;
        }

        if (data.remaining <= 0) {
          return;
        }
      }
    },

    stopEnrichment(): void {
      this._stopEnrichment = true;
    },

    resetFrequencyImport(): void {
      this.freqStep = 'choose';
      this.freqResult = { imported: 0, skipped: 0, total: 0 };
      this.enrichStats = { done: 0, failed: 0, total: 0 };
      this.enrichProgress = 0;
      this.enrichWarning = '';
      this.freqError = '';
    }
  };
}

/**
 * Alpine.js component for the manual upload form.
 *
 * Manages manual method sub-tabs, import mode, column assignment,
 * delimiter, and dictionary format.
 */
export function wordUploadFormApp() {
  return {
    manualMethod: 'dict-file' as string,
    importMode: '0',
    showDelimiter: false,
    delimiter: 'c',
    cols: ['w', 't', 'x', 'x', 'x'] as string[],
    extraCols: 0,
    dictFormat: 'csv',
    dictFileName: '',

    setManualMethod(method: string): void {
      this.manualMethod = method;
    },

    updateImportMode(event: Event): void {
      const val = (event.target as HTMLSelectElement).value;
      this.importMode = val;
      this.showDelimiter = val === '4' || val === '5';
    },

    updateDictFileName(event: Event): void {
      const input = event.target as HTMLInputElement;
      this.dictFileName = input.files?.[0]?.name || '';
    },

    previewHeaders(): string[] {
      return this.cols.map(c => COL_LABELS[c]).filter(Boolean);
    },

    previewRow(): string[] {
      return this.cols.map(c => COL_EXAMPLES[c]).filter(Boolean);
    },

    hasPreview(): boolean {
      return this.previewHeaders().length > 0;
    },

    addColumn(): void {
      if (this.extraCols < 3) {
        this.extraCols++;
      }
    },

    removeColumn(): void {
      if (this.extraCols > 0) {
        this.cols[1 + this.extraCols] = 'x';
        this.extraCols--;
      }
    },

    showExtraCol(n: number): boolean {
      return this.extraCols >= n;
    },

    get dictFileLabel(): string {
      return this.dictFileName || 'No file selected';
    },

    get isDictFile(): boolean {
      return this.manualMethod === 'dict-file';
    },

    get isNotDictFile(): boolean {
      return this.manualMethod !== 'dict-file';
    },

    get showDictCsvOptions(): boolean {
      return this.manualMethod === 'dict-file' && this.dictFormat === 'csv';
    },

    get showNonDictOptions(): boolean {
      return this.manualMethod !== 'dict-file';
    }
  };
}

/**
 * Word upload result Alpine component data interface.
 */
export interface WordUploadResultData {
  // Config
  lastUpdate: string;
  rtl: boolean;
  recno: number;

  // State
  currentPage: number;
  totalPages: number;
  terms: ImportedTerm[];
  isLoading: boolean;
  hasTerms: boolean;

  // Methods
  init(): void;
  loadPage(page: number): Promise<void>;
  goToPage(page: number): void;
  goToPageFromEvent(event: Event): void;
  goFirst(): void;
  goPrev(): void;
  goNext(): void;
  goLast(): void;
  formatTermRow(term: ImportedTerm): string;
  getStatusInfo(status: number): { name: string; abbr: string };
  setTableBodyHtml(el: HTMLElement): void;
}

/**
 * Alpine.js component for word upload result display.
 */
export function wordUploadResultApp(config: UploadResultConfig = { lastUpdate: '', rtl: false, recno: 0 }): WordUploadResultData {
  return {
    // Config
    lastUpdate: config.lastUpdate,
    rtl: config.rtl,
    recno: config.recno,

    // State
    currentPage: 1,
    totalPages: 1,
    terms: [],
    isLoading: false,
    hasTerms: false,

    /**
     * Initialize the component.
     */
    init(): void {
      // Read config from JSON script tag if available
      const configEl = document.querySelector<HTMLScriptElement>('script[data-lwt-upload-result-config]');
      if (configEl) {
        try {
          const jsonConfig = JSON.parse(configEl.textContent || '{}') as UploadResultConfig;
          this.lastUpdate = jsonConfig.lastUpdate ?? this.lastUpdate;
          this.rtl = jsonConfig.rtl ?? this.rtl;
          this.recno = jsonConfig.recno ?? this.recno;
        } catch {
          // Invalid JSON, use defaults
        }
      }

      this.hasTerms = this.recno > 0;
      if (this.hasTerms) {
        this.loadPage(1);
      }
    },

    /**
     * Load a page of imported terms.
     */
    async loadPage(page: number): Promise<void> {
      if (this.recno === 0) {
        this.hasTerms = false;
        return;
      }

      this.isLoading = true;

      const params = new URLSearchParams({
        last_update: this.lastUpdate,
        count: String(this.recno),
        page: String(page)
      });

      try {
        const response = await fetch('/api/v1/terms/imported?' + params.toString());
        if (!response.ok) {
          throw new Error(`HTTP error: ${response.status}`);
        }
        const data: ImportedTermsResponse = await response.json();

        this.currentPage = data.navigation.current_page;
        this.totalPages = data.navigation.total_pages;
        this.terms = data.terms;
        this.hasTerms = true;
      } catch (error) {
        console.error('Failed to fetch imported terms:', error);
        this.hasTerms = false;
      } finally {
        this.isLoading = false;
      }
    },

    /**
     * Navigate to a specific page.
     */
    goToPage(page: number): void {
      if (page >= 1 && page <= this.totalPages) {
        this.loadPage(page);
      }
    },

    /**
     * Navigate to the page chosen in the picker.
     *
     * The number parsing lives here because the CSP Alpine build has no access
     * to JS globals such as parseInt inside an inline expression.
     *
     * @param event Change event from the page select
     */
    goToPageFromEvent(event: Event): void {
      const select = event.target as HTMLSelectElement | null;
      const page = parseInt(select?.value ?? '', 10);
      if (Number.isFinite(page)) {
        this.goToPage(page);
      }
    },

    /**
     * Go to first page.
     */
    goFirst(): void {
      this.goToPage(1);
    },

    /**
     * Go to previous page.
     */
    goPrev(): void {
      this.goToPage(this.currentPage - 1);
    },

    /**
     * Go to next page.
     */
    goNext(): void {
      this.goToPage(this.currentPage + 1);
    },

    /**
     * Go to last page.
     */
    goLast(): void {
      this.goToPage(this.totalPages);
    },

    /**
     * Format a term row as HTML.
     */
    formatTermRow(term: ImportedTerm): string {
      const statusInfo = this.getStatusInfo(term.WoStatus);

      return `<tr>
        <td>
          <span dir="${this.rtl ? 'rtl' : 'ltr'}">${escapeHtml(term.WoText)}</span>
          <span class="has-text-grey"> / </span>
          <span id="roman${term.WoID}" class="edit_area clickedit has-text-grey-dark">${term.WoRomanization !== '' ? escapeHtml(term.WoRomanization) : '*'}</span>
        </td>
        <td>
          <span id="trans${term.WoID}" class="edit_area clickedit">${escapeHtml(term.WoTranslation)}</span>
        </td>
        <td>
          <span class="tags">${renderTags(term.taglist)}</span>
        </td>
        <td class="has-text-centered">
          ${term.SentOK !== 0
    ? iconHtml('check', { title: escapeHtml(term.WoSentence), alt: 'Yes', className: 'has-text-success' })
    : iconHtml('x', { title: '(No valid sentence)', alt: 'No', className: 'has-text-danger' })
}
        </td>
        <td class="has-text-centered" title="${escapeHtml(statusInfo.name)}">
          <span class="tag is-light">${escapeHtml(statusInfo.abbr)}</span>
        </td>
      </tr>`;
    },

    /**
     * Get status info for a status code.
     */
    getStatusInfo(status: number): { name: string; abbr: string } {
      return statuses[status] || { name: 'Unknown', abbr: '?' };
    },

    /**
     * Set table body HTML (CSP-compatible - use with x-effect)
     */
    setTableBodyHtml(el: HTMLElement): void {
      el.innerHTML = this.terms.map(term => this.formatTermRow(term)).join('');
    }
  };
}

/**
 * A curated dictionary source entry.
 */
interface CuratedDictSource {
  name: string;
  url: string;
  format: string;
  entries: string;
  license: string;
  notes: string;
  directDownload?: boolean;
  dictType?: string;
  targetLanguage?: string;
}

/**
 * API response for curated dictionary import.
 */
interface CuratedImportResponse {
  success: boolean;
  dictId?: number;
  imported?: number;
  vocabCreated?: number;
  error?: string;
}

/**
 * A curated dictionary language group.
 */
interface CuratedDictGroup {
  language: string;
  languageName: string;
  sources: CuratedDictSource[];
}

/**
 * Find a curated dictionary group matching a user language name.
 *
 * Handles cases like "Chinese (Simplified)" matching "Chinese",
 * or "French" matching "French".
 */
function findGroupByLanguageName(
  groups: CuratedDictGroup[],
  langName: string
): CuratedDictGroup | undefined {
  const lower = langName.toLowerCase();
  if (!lower) return undefined;
  // Exact match first
  const exact = groups.find(g => g.languageName.toLowerCase() === lower);
  if (exact) return exact;
  // User name starts with curated name ("Chinese (Simplified)" -> "Chinese")
  const prefix = groups.find(g => lower.startsWith(g.languageName.toLowerCase()));
  if (prefix) return prefix;
  // Curated name starts with user name
  return groups.find(g => g.languageName.toLowerCase().startsWith(lower));
}

/**
 * Alpine.js component for browsing curated dictionaries.
 */
export function curatedDictBrowser() {
  const configEl = document.getElementById('curated-dictionaries-config');
  let allGroups: CuratedDictGroup[] = [];
  if (configEl) {
    try {
      allGroups = JSON.parse(configEl.textContent || '[]');
    } catch {
      // Invalid JSON, use empty
    }
  }

  return {
    allGroups,
    dictLanguageFilter: '',
    dictSearch: '',

    // Selection + batch import state
    selectedUrls: [] as string[],
    batchImporting: false,
    batchCurrent: 0,
    batchTotal: 0,
    batchMessages: [] as { success: boolean; text: string }[],

    /**
     * Initialize: sync filter with parent's selectedLanguageName.
     */
    init(): void {
      // Read selectedLanguageName from parent scope (wordUploadPageApp)
      const langName = (this as Record<string, unknown>).selectedLanguageName as string || '';
      const match = findGroupByLanguageName(this.allGroups, langName);
      if (match) {
        this.dictLanguageFilter = match.language;
      }

      // Watch for language changes from parent tabs
      (this as unknown as { $watch: (prop: string, cb: (val: string) => void) => void }).$watch('selectedLanguageName', (name: string) => {
        const found = findGroupByLanguageName(this.allGroups, name);
        this.dictLanguageFilter = found ? found.language : '';
      });
    },

    get filteredGroups(): CuratedDictGroup[] {
      let groups = this.allGroups;

      if (this.dictLanguageFilter) {
        groups = groups.filter(g => g.language === this.dictLanguageFilter);
      }

      const search = this.dictSearch.toLowerCase().trim();
      if (search) {
        groups = groups
          .map(g => ({
            ...g,
            sources: g.sources.filter(
              s =>
                s.name.toLowerCase().includes(search) ||
                s.format.toLowerCase().includes(search) ||
                s.notes.toLowerCase().includes(search)
            )
          }))
          .filter(g => g.sources.length > 0);
      }

      return groups;
    },

    /**
     * Check if a source URL is selected.
     */
    isSelected(url: string): boolean {
      return this.selectedUrls.includes(url);
    },

    /**
     * Toggle selection of a dictionary source.
     */
    toggleSelection(url: string): void {
      const idx = this.selectedUrls.indexOf(url);
      if (idx >= 0) {
        this.selectedUrls.splice(idx, 1);
      } else {
        this.selectedUrls.push(url);
      }
    },

    /**
     * Get count of selected dictionaries.
     */
    getSelectedCount(): number {
      return this.selectedUrls.length;
    },

    /**
     * Batch import all selected dictionaries.
     */
    async importSelected(): Promise<void> {
      const langId = (this as Record<string, unknown>).selectedLanguageId as number;
      if (!langId) {
        this.batchMessages = [{ success: false, text: 'Please select a language first.' }];
        return;
      }

      // Collect selected sources from all groups
      const sources: CuratedDictSource[] = [];
      for (const group of this.allGroups) {
        for (const source of group.sources) {
          if (this.selectedUrls.includes(source.url)) {
            sources.push(source);
          }
        }
      }

      if (sources.length === 0) return;

      this.batchImporting = true;
      this.batchTotal = sources.length;
      this.batchCurrent = 0;
      this.batchMessages = [];

      for (const source of sources) {
        this.batchCurrent++;

        const response = await apiPost<CuratedImportResponse>(
          '/local-dictionaries/import-curated',
          {
            language_id: langId,
            url: source.url,
            format: source.format,
            name: source.name,
          }
        );

        const result = response.data ?? {
          success: false,
          error: response.error || 'Unknown error',
        };

        if (result.success) {
          this.batchMessages.push({
            success: true,
            text: `${source.name}: imported ${result.imported ?? 0} entries` +
              (result.vocabCreated ? ` and ${result.vocabCreated} vocabulary terms` : '') +
              '.',
          });
        } else {
          this.batchMessages.push({
            success: false,
            text: `${source.name}: ${result.error ?? 'Import failed.'}`,
          });
        }
      }

      this.batchImporting = false;
      this.selectedUrls = [];
    },

    /**
     * Dismiss a batch message by index.
     */
    dismissMessage(index: number): void {
      this.batchMessages.splice(index, 1);
    }
  };
}

// Register Alpine components
if (typeof Alpine !== 'undefined') {
  Alpine.data('wordUploadPageApp', wordUploadPageApp);
  Alpine.data('wordUploadFormApp', wordUploadFormApp);
  Alpine.data('wordUploadResultApp', wordUploadResultApp);
  Alpine.data('curatedDictBrowser', curatedDictBrowser);
}
