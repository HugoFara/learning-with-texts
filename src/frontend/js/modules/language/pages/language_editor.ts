/**
 * Language editor — reads and writes the language through `/api/v1`.
 *
 * Replaces the PHP-filled `value=`/`checked=`/`selected=` attributes on
 * `form.php` and the full-page POST that backed them. Creating goes through
 * `POST /languages`, editing through `PUT /languages/{id}`.
 *
 * The option lists (parser types, translator names, lookup modes) stay
 * server-rendered: they are static configuration, and parser availability is
 * knowledge only the server has. Only the *selected values* come from here.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.4.0
 */

import Alpine from 'alpinejs';
import { t } from '@shared/i18n/translator';
import { LanguagesApi, type LanguageFull } from '../api/languages_api';
import { LocalDictionariesApi, type LocalDictionary } from '@modules/dictionary/api/local_dictionaries_api';
import { readPageConfig } from '@shared/utils/page_config';

/** Config the scaffold ships for the editor. */
export interface LanguageEditorConfig {
  languageId: number | null;
  isNew: boolean;
}

/**
 * The editable language. Mirrors the field set `PUT /languages/{id}` accepts;
 * `LanguageFieldRoundTripTest` keeps that in step with what GET returns.
 */
export type EditableLanguage = Omit<LanguageFull, 'id'>;

/** Defaults for a language that does not exist yet. */
export function blankLanguage(): EditableLanguage {
  return {
    name: '',
    dict1Uri: '',
    dict2Uri: '',
    translatorUri: '',
    dict1PopUp: false,
    dict2PopUp: false,
    translatorPopUp: false,
    sourceLang: '',
    targetLang: '',
    exportTemplate: '',
    textSize: 100,
    characterSubstitutions: '',
    regexpSplitSentences: '.!?',
    exceptionsSplitSentences: '',
    regexpWordCharacters: 'a-zA-ZÀ-ÖØ-öø-ȳ',
    removeSpaces: false,
    splitEachChar: false,
    rightToLeft: false,
    ttsVoiceApi: '',
    showRomanization: false,
    parserType: 'regex',
    // New languages default to preferring a local dictionary, matching the
    // hidden field the retired form shipped for the create case.
    localDictMode: 1
  };
}

/** Alpine component state. */
export interface LanguageEditorData {
  lang: EditableLanguage;
  languageId: number | null;
  isNew: boolean;
  isLoading: boolean;
  isSaving: boolean;
  error: string;
  dictionaries: LocalDictionary[];
  showAdvanced: boolean;
  showTranslatorKey: boolean;
  init(): Promise<void>;
  load(): Promise<void>;
  loadDictionaries(): Promise<void>;
  save(): Promise<void>;
  toggleAdvanced(): void;
  onTranslatorChange(event: Event): void;
  isRegexParser(): boolean;
  isMecabParser(): boolean;
  textSizeStyle(): string;
  manageDictionariesUrl(): string;
  entryCountLabel(dict: LocalDictionary): string;
  formatLabel(dict: LocalDictionary): string;
  statusLabel(dict: LocalDictionary): string;
  statusClass(dict: LocalDictionary): string;
}

/** Read the scaffold's config blob. */
function readConfig(): LanguageEditorConfig {
  // A null languageId is not carried in the blob, so the default stands
  // and the editor opens in create mode.
  return readPageConfig<LanguageEditorConfig>('language-form-config', {
    languageId: null,
    isNew: true
  });
}

/**
 * Build the language editor component.
 *
 * @returns Alpine component data
 */
export function languageEditorData(): LanguageEditorData {
  return {
    lang: blankLanguage(),
    languageId: null,
    isNew: true,
    isLoading: false,
    isSaving: false,
    error: '',
    dictionaries: [],
    showAdvanced: false,
    showTranslatorKey: false,

    async init(): Promise<void> {
      const config = readConfig();
      this.languageId = config.languageId;
      this.isNew = config.isNew;
      // Editing opens on the advanced panel, creating starts collapsed.
      this.showAdvanced = !config.isNew;

      if (!config.isNew && config.languageId) {
        await this.load();
        await this.loadDictionaries();
      }
    },

    async load(): Promise<void> {
      if (!this.languageId) {
        return;
      }
      this.isLoading = true;
      this.error = '';

      const response = await LanguagesApi.get(this.languageId);
      this.isLoading = false;

      if (response.error || !response.data) {
        this.error = response.error || t('language.errors.language_not_found');
        return;
      }

      // Spread over the blank defaults so a field the API has yet to expose
      // keeps a sane value rather than becoming undefined and then being
      // written back as empty.
      const fields = { ...response.data.language } as Partial<LanguageFull>;
      delete fields.id;
      this.lang = { ...blankLanguage(), ...fields };
    },

    async loadDictionaries(): Promise<void> {
      if (!this.languageId) {
        return;
      }
      const response = await LocalDictionariesApi.list(this.languageId);
      if (response.data) {
        this.dictionaries = response.data.dictionaries;
      }
    },

    async save(): Promise<void> {
      if (this.lang.name.trim() === '') {
        this.error = t('language.form.name_required');
        return;
      }

      this.isSaving = true;
      this.error = '';

      const response = this.isNew
        ? await LanguagesApi.create(this.lang)
        : await LanguagesApi.update(Number(this.languageId), this.lang);

      this.isSaving = false;

      if (response.error || !response.data || !response.data.success) {
        const body = response.data;
        this.error = (body && body.error) ? body.error : (response.error || t('language.errors.save_failed'));
        return;
      }

      if (this.isNew) {
        // Creating lands on the starter-vocabulary step, as the retired
        // server-side redirect did.
        const created = response.data as { id?: number };
        window.location.href = created.id
          ? `/languages/${created.id}/starter-vocab`
          : '/languages';
        return;
      }
      window.location.href = '/languages';
    },

    toggleAdvanced(): void {
      this.showAdvanced = !this.showAdvanced;
    },

    onTranslatorChange(event: Event): void {
      const select = event.target as HTMLSelectElement;
      this.showTranslatorKey = select.value === 'libretranslate';
    },

    isRegexParser(): boolean {
      return this.lang.parserType === 'regex';
    },

    isMecabParser(): boolean {
      return this.lang.parserType === 'mecab';
    },

    textSizeStyle(): string {
      return `font-size: ${this.lang.textSize}%`;
    },

    manageDictionariesUrl(): string {
      return `/languages/${this.languageId}/dictionaries`;
    },

    entryCountLabel(dict: LocalDictionary): string {
      return dict.entry_count.toLocaleString();
    },

    formatLabel(dict: LocalDictionary): string {
      return dict.source_format.toUpperCase();
    },

    statusLabel(dict: LocalDictionary): string {
      return dict.enabled
        ? t('language.form.dict_status_enabled')
        : t('language.form.dict_status_disabled');
    },

    statusClass(dict: LocalDictionary): string {
      return dict.enabled ? 'tag is-success is-light' : 'tag is-warning is-light';
    }
  };
}

Alpine.data('languageEditor', languageEditorData);
