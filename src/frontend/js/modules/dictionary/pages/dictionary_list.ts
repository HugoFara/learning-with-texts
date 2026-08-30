/**
 * Local dictionary list — renders from `GET /local-dictionaries`.
 *
 * Replaces the server-rendered table and the same-origin form POSTs it used
 * for create, enable/disable and delete, all of which now go through the API.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.4.0
 */

import Alpine from 'alpinejs';
import { t } from '@shared/i18n/translator';
import {
  LocalDictionariesApi,
  type LocalDictionary
} from '../api/local_dictionaries_api';

/** Alpine component state for the dictionary list. */
export interface DictionaryListData {
  dictionaries: LocalDictionary[];
  languageId: number;
  isLoading: boolean;
  error: string;
  busyId: number;
  newName: string;
  isCreating: boolean;
  init(): Promise<void>;
  load(): Promise<void>;
  create(): Promise<void>;
  toggle(dict: LocalDictionary): Promise<void>;
  confirmDelete(dict: LocalDictionary): Promise<void>;
  descriptionOf(dict: LocalDictionary): string;
  formatLabel(dict: LocalDictionary): string;
  entryCountLabel(dict: LocalDictionary): string;
  statusLabel(dict: LocalDictionary): string;
  statusClass(dict: LocalDictionary): string;
  toggleIcon(dict: LocalDictionary): string;
  toggleClass(dict: LocalDictionary): string;
  toggleTitle(dict: LocalDictionary): string;
  isBusy(dict: LocalDictionary): boolean;
  hasDescription(dict: LocalDictionary): boolean;
}

/**
 * Read the language ID the scaffold was rendered for.
 *
 * @returns The language ID, or 0 when the blob is missing or malformed
 */
function readLanguageId(): number {
  const el = document.getElementById('dictionary-list-config');
  if (el) {
    try {
      return Number(JSON.parse(el.textContent || '{}').languageId) || 0;
    } catch {
      // Malformed blob: the component reports the failed load instead.
    }
  }
  return 0;
}

/**
 * Build the dictionary list component.
 *
 * @returns Alpine component state
 */
export function dictionaryListData(): DictionaryListData {
  return {
    dictionaries: [],
    languageId: readLanguageId(),
    isLoading: true,
    error: '',
    busyId: 0,
    newName: '',
    isCreating: false,

    async init() {
      await this.load();
    },

    async load() {
      this.isLoading = true;
      this.error = '';

      const response = await LocalDictionariesApi.list(this.languageId);
      this.isLoading = false;

      if (response.error || !response.data) {
        this.error = response.error || t('dictionary.no_local_dicts');
        this.dictionaries = [];
        return;
      }

      this.dictionaries = response.data.dictionaries ?? [];
    },

    async create() {
      const name = this.newName.trim();
      if (name === '' || this.isCreating) {
        return;
      }

      this.isCreating = true;
      this.error = '';

      const response = await LocalDictionariesApi.create(this.languageId, name);
      this.isCreating = false;

      if (response.error || response.data?.success === false) {
        this.error = response.error || response.data?.error || '';
        return;
      }

      this.newName = '';

      // The endpoint echoes the row back, so append it rather than refetching
      // the whole list.
      const created = response.data?.dictionary;
      if (created) {
        this.dictionaries = [...this.dictionaries, created];
        return;
      }

      await this.load();
    },

    async toggle(dict: LocalDictionary) {
      this.busyId = dict.id;
      const response = await LocalDictionariesApi.setEnabled(dict.id, !dict.enabled);
      this.busyId = 0;

      if (response.error || response.data?.success === false) {
        this.error = response.error || response.data?.error || '';
        return;
      }

      // Reflect the new state without a refetch; the row is the only thing
      // that changed.
      dict.enabled = !dict.enabled;
    },

    async confirmDelete(dict: LocalDictionary) {
      if (!window.confirm(t('dictionary.confirm_delete_dict'))) {
        return;
      }

      this.busyId = dict.id;
      const response = await LocalDictionariesApi.remove(dict.id);
      this.busyId = 0;

      if (response.error || response.data?.success === false) {
        this.error = response.error || response.data?.error || '';
        return;
      }

      this.dictionaries = this.dictionaries.filter(d => d.id !== dict.id);
    },

    descriptionOf(dict: LocalDictionary) {
      return dict.description ?? '';
    },

    hasDescription(dict: LocalDictionary) {
      return Boolean(dict.description);
    },

    formatLabel(dict: LocalDictionary) {
      return (dict.source_format ?? '').toUpperCase();
    },

    entryCountLabel(dict: LocalDictionary) {
      // Matches the PHP number_format() the table used to apply.
      return dict.entry_count.toLocaleString('en-US');
    },

    statusLabel(dict: LocalDictionary) {
      return dict.enabled ? t('common.enabled') : t('common.disabled');
    },

    statusClass(dict: LocalDictionary) {
      return dict.enabled ? 'tag is-success' : 'tag is-warning';
    },

    toggleIcon(dict: LocalDictionary) {
      return dict.enabled ? 'eye-off' : 'eye';
    },

    toggleClass(dict: LocalDictionary) {
      return dict.enabled ? 'button is-warning' : 'button is-success';
    },

    toggleTitle(dict: LocalDictionary) {
      return dict.enabled ? t('dictionary.toggle_disable') : t('dictionary.toggle_enable');
    },

    isBusy(dict: LocalDictionary) {
      return this.busyId === dict.id;
    }
  };
}

/** Register the component with Alpine. */
export function initDictionaryListAlpine(): void {
  Alpine.data('dictionaryList', dictionaryListData);
}

initDictionaryListAlpine();
