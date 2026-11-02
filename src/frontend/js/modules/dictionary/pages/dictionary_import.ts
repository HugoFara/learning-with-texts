/**
 * Dictionary Import Form Alpine.js component.
 *
 * Provides interactive form handling for dictionary file imports.
 * Handles file format selection and file input validation.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.0.0
 */

import Alpine from 'alpinejs';

interface DictionaryImportData {
  format: string;
  fileName: string;
  submitting: boolean;
  acceptTypes: Record<string, string>;
  fileSelected(event: Event): void;
  resetOptions(): void;
}

/**
 * Alpine.js data component for the dictionary import form.
 * Handles file format switching and file selection display.
 */
export function dictionaryImportData(): DictionaryImportData {
  return {
    format: 'csv',
    fileName: '',
    submitting: false,
    acceptTypes: {
      csv: '.csv,.tsv,.txt',
      json: '.json',
      // StarDict needs companion .idx/.dict files alongside .ifo, so the
      // user uploads an archive that contains all three.
      stardict: '.zip,.tar,.tgz,.gz,.bz2,.xz',
      // CC-CEDICT ships as cedict_ts.u8, usually inside a .zip or .txt.gz.
      cedict: '.u8,.txt,.zip,.gz'
    },

    fileSelected(event: Event): void {
      const input = event.target as HTMLInputElement;
      const file = input.files?.[0];
      this.fileName = file ? file.name : '';
    },

    resetOptions(): void {
      // Reset options when format changes
      // Can be extended if format-specific options need clearing
    }
  };
}

/**
 * Initialize the dictionary import Alpine.js component.
 * This must be called before Alpine.start().
 */
export function initDictionaryImportAlpine(): void {
  Alpine.data('dictionaryImport', dictionaryImportData);
}

// Expose for global access if needed
declare global {
  interface Window {
    dictionaryImportData: typeof dictionaryImportData;
    initDictionaryImportAlpine: typeof initDictionaryImportAlpine;
  }
}

window.dictionaryImportData = dictionaryImportData;
window.initDictionaryImportAlpine = initDictionaryImportAlpine;

// Register Alpine data component immediately (before Alpine.start() in main.ts)
initDictionaryImportAlpine();
