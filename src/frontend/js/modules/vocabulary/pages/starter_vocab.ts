/**
 * Starter Vocabulary Alpine.js Component (CSP-compliant)
 *
 * Handles the starter vocabulary import flow after language creation:
 * choose sources -> import frequency words -> enrich -> import dicts -> done
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.0.0
 */

import Alpine from 'alpinejs';
import { apiPost } from '@shared/api/client';
import { readPageConfig } from '@shared/utils/page_config';

interface StarterVocabConfig {
  importUrl: string;
  enrichUrl: string;
  csrfToken: string;
  langId: number;
  curatedDictionaries: CuratedDictGroup[];
  isAvailable: boolean;
}

interface ImportResult {
  imported: number;
  skipped: number;
  total: number;
}

interface EnrichStats {
  done: number;
  failed: number;
  total: number;
}

interface CuratedDictSource {
  name: string;
  url: string;
  format: string;
  entries: string;
  license: string;
  notes: string;
  directDownload?: boolean;
  dictType?: 'translation' | 'definition';
  targetLanguage?: string;
}

interface CuratedDictGroup {
  language: string;
  languageName: string;
  sources: CuratedDictSource[];
}

interface CuratedImportResponse {
  success: boolean;
  dictId?: number;
  imported?: number;
  /** Entries dropped because their headword exceeded the term column. */
  skipped?: number;
  error?: string;
}

interface DictMessage {
  success: boolean;
  text: string;
}

function readConfig(): StarterVocabConfig {
  return readPageConfig<StarterVocabConfig>('starter-vocab-config', {
    importUrl: '',
    enrichUrl: '',
    csrfToken: '',
    langId: 0,
    curatedDictionaries: [],
    isAvailable: false
  });
}

Alpine.data('starterVocab', () => {
  const config = readConfig();

  return {
    step: 'choose' as string,
    size: 100,
    mode: 'translation' as string,
    useWiktionary: config.isAvailable,
    wiktResult: { imported: 0, skipped: 0, total: 0 } as ImportResult,
    enrichStats: { done: 0, failed: 0, total: 0 } as EnrichStats,
    enrichWarning: '',
    enrichProgress: 0,
    errorMessage: '',
    _stopEnrichment: false,

    // Curated dictionary state
    dictSources: config.curatedDictionaries.flatMap(g => g.sources),
    selectedDictUrls: [] as string[],
    dictMessages: [] as DictMessage[],
    dictBatchImporting: false,
    dictBatchCurrent: 0,
    dictBatchTotal: 0,

    sizeClass(value: number): string {
      return this.size === value ? 'button is-primary is-selected' : 'button';
    },

    setSize(value: number): void {
      this.size = value;
    },

    toggleWiktionary(): void {
      this.useWiktionary = !this.useWiktionary;
    },

    canImport(): boolean {
      return this.useWiktionary || this.selectedDictUrls.length > 0;
    },

    enrichingLabel(): string {
      return this.mode === 'translation'
        ? 'Fetching translations...'
        : 'Fetching definitions...';
    },

    enrichedModeLabel(): string {
      return this.mode === 'translation' ? 'translations' : 'definitions';
    },

    async startImport(): Promise<void> {
      try {
        // Phase 1: Wiktionary frequency words
        if (this.useWiktionary) {
          this.step = 'importing';

          const formData = new FormData();
          formData.append('count', String(this.size));
          formData.append('_csrf_token', config.csrfToken);

          const response = await fetch(config.importUrl, {
            method: 'POST',
            body: formData,
          });

          const data = await response.json();

          if (!response.ok) {
            this.errorMessage = data.error || 'Unknown error occurred.';
            this.step = 'error';
            return;
          }

          this.wiktResult = data;

          if (data.imported > 0) {
            this.enrichStats = { done: 0, failed: 0, total: data.imported };
            this._stopEnrichment = false;
            this.step = 'enriching';
            await this.enrichAll();
          }
        }

        // Phase 2: Curated dictionaries
        if (this.selectedDictUrls.length > 0) {
          await this.importDictBatch();
        }

        this.step = 'done';
      } catch {
        this.errorMessage = 'Network error. Please check your connection.';
        this.step = 'error';
      }
    },

    async enrichAll(): Promise<void> {
      while (!this._stopEnrichment) {
        const formData = new FormData();
        formData.append('mode', this.mode);
        formData.append('_csrf_token', config.csrfToken);

        const response = await fetch(config.enrichUrl, {
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

    async importDictBatch(): Promise<void> {
      const sources = this.dictSources.filter(
        (s: CuratedDictSource) => this.selectedDictUrls.includes(s.url)
      );
      if (sources.length === 0) return;

      this.step = 'dictImporting';
      this.dictBatchImporting = true;
      this.dictBatchTotal = sources.length;
      this.dictBatchCurrent = 0;
      this.dictMessages = [];

      for (const source of sources) {
        this.dictBatchCurrent++;
        const response = await apiPost<CuratedImportResponse>(
          '/local-dictionaries/import-curated',
          {
            language_id: config.langId,
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
          const skipped = result.skipped ?? 0;
          // Say so when entries were dropped, rather than reporting a count
          // that silently differs from the dictionary's own entry total.
          const skippedNote = skipped > 0
            ? ` (${skipped} skipped: headword too long)`
            : '';
          this.dictMessages.push({
            success: true,
            text: `${source.name}: imported ${result.imported ?? 0} entries.${skippedNote}`,
          });
        } else {
          this.dictMessages.push({
            success: false,
            text: `${source.name}: ${result.error ?? 'Import failed.'}`,
          });
        }
      }

      this.dictBatchImporting = false;
      this.selectedDictUrls = [];
    },

    stopEnrichment(): void {
      this._stopEnrichment = true;
    },

    retryImport(): void {
      this.step = 'choose';
    },

    // Curated dictionary methods

    isSourceSelected(url: string): boolean {
      return this.selectedDictUrls.includes(url);
    },

    toggleSource(url: string): void {
      const idx = this.selectedDictUrls.indexOf(url);
      if (idx >= 0) {
        this.selectedDictUrls.splice(idx, 1);
      } else {
        this.selectedDictUrls.push(url);
      }
    },

    dictTypeLabel(source: CuratedDictSource): string {
      if (source.dictType === 'definition') {
        return 'Definitions';
      }
      if (source.dictType === 'translation' && source.targetLanguage) {
        return source.targetLanguage;
      }
      return 'Translation';
    },

    removeDictMessage(index: number): void {
      this.dictMessages.splice(index, 1);
    },
  };
});
