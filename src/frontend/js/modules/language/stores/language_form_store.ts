/**
 * Language Form Store - Alpine.js store for language edit form state management.
 *
 * Provides centralized state management for the language create/edit form.
 * Handles form data, validation, dirty state detection, and API interactions.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.0.0
 */

import Alpine from 'alpinejs';
import { t } from '@shared/i18n/translator';
import {
  LanguagesApi,
  type LanguageDefinition,
  type LanguageCreateRequest,
  type LanguageUpdateRequest
} from '@modules/language/api/languages_api';

/**
 * Form data structure.
 */
export interface LanguageFormData {
  name: string;
  dict1Uri: string;
  dict2Uri: string;
  translatorUri: string;
  dict1PopUp: boolean;
  dict2PopUp: boolean;
  translatorPopUp: boolean;
  sourceLang: string;
  targetLang: string;
  exportTemplate: string;
  textSize: number;
  characterSubstitutions: string;
  regexpSplitSentences: string;
  exceptionsSplitSentences: string;
  regexpWordCharacters: string;
  removeSpaces: boolean;
  splitEachChar: boolean;
  parserType: string;
  rightToLeft: boolean;
  ttsVoiceApi: string;
  showRomanization: boolean;
}

/**
 * Validation errors keyed by field name.
 */
export interface LanguageValidationErrors {
  name: string | null;
  dict1Uri: string | null;
  regexpSplitSentences: string | null;
  regexpWordCharacters: string | null;
  textSize: string | null;
  ttsVoiceApi: string | null;
  general: string | null;
}

/**
 * Result of a save operation.
 */
export interface LanguageSaveResult {
  success: boolean;
  id?: number;
  reparsed?: number;
  error?: string;
}

/**
 * Language form store state interface.
 */
export interface LanguageFormStoreState {
  // Form data
  formData: LanguageFormData;
  originalData: LanguageFormData | null;

  // Context
  languageId: number | null;
  isNew: boolean;
  allLanguages: Record<string, number>;
  definitions: Record<string, LanguageDefinition>;

  // UI state
  isLoading: boolean;
  isSubmitting: boolean;
  errors: LanguageValidationErrors;

  // Computed
  readonly isDirty: boolean;
  readonly isValid: boolean;
  readonly canSubmit: boolean;

  // Methods
  loadForEdit(id: number | null): Promise<void>;
  loadDefinitions(): Promise<void>;
  applyPreset(l1: string, l2: string): void;
  reset(): void;
  validate(): boolean;
  validateField(field: keyof LanguageFormData): void;
  save(): Promise<LanguageSaveResult>;
}

/**
 * Create initial empty form data with sensible defaults.
 */
function createEmptyFormData(): LanguageFormData {
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
    exportTemplate: '$y\\t$t\\n',
    textSize: 100,
    characterSubstitutions: '',
    regexpSplitSentences: '.!?',
    exceptionsSplitSentences: '',
    regexpWordCharacters: 'a-zA-ZÀ-ÖØ-öø-ȳ',
    removeSpaces: false,
    splitEachChar: false,
    parserType: '',
    rightToLeft: false,
    ttsVoiceApi: '',
    showRomanization: true
  };
}

/**
 * Create initial validation errors (all null = no errors).
 */
function createEmptyErrors(): LanguageValidationErrors {
  return {
    name: null,
    dict1Uri: null,
    regexpSplitSentences: null,
    regexpWordCharacters: null,
    textSize: null,
    ttsVoiceApi: null,
    general: null
  };
}

/**
 * Deep clone form data for dirty detection.
 */
function cloneFormData(data: LanguageFormData): LanguageFormData {
  return { ...data };
}

/**
 * Create the language form store data object.
 */
function createLanguageFormStore(): LanguageFormStoreState {
  return {
    // Form data
    formData: createEmptyFormData(),
    originalData: null,

    // Context
    languageId: null,
    isNew: true,
    allLanguages: {},
    definitions: {},

    // UI state
    isLoading: false,
    isSubmitting: false,
    errors: createEmptyErrors(),

    /**
     * Check if form has unsaved changes.
     */
    get isDirty(): boolean {
      if (!this.originalData) return false;

      const keys = Object.keys(this.formData) as Array<keyof LanguageFormData>;
      return keys.some((key) => this.formData[key] !== this.originalData![key]);
    },

    /**
     * Check if form is valid (no validation errors).
     */
    get isValid(): boolean {
      const errorKeys = Object.keys(this.errors) as Array<
        keyof LanguageValidationErrors
      >;
      return errorKeys.every((key) => this.errors[key] === null);
    },

    /**
     * Check if form can be submitted.
     */
    get canSubmit(): boolean {
      return this.isValid && !this.isSubmitting && !this.isLoading;
    },

    /**
     * Load language data for editing.
     */
    async loadForEdit(id: number | null): Promise<void> {
      this.isLoading = true;
      this.errors = createEmptyErrors();
      this.languageId = id;
      this.isNew = id === null;

      try {
        if (id === null) {
          // New language - use defaults
          this.formData = createEmptyFormData();
          this.originalData = cloneFormData(this.formData);
          await this.loadDefinitions();
          this.isLoading = false;
          return;
        }

        // Editing existing language
        const response = await LanguagesApi.get(id);

        if (response.error || !response.data) {
          this.errors.general = response.error || t('language.errors.load_failed');
          this.isLoading = false;
          return;
        }

        const lang = response.data.language;
        this.allLanguages = response.data.allLanguages;

        // Set form data from language
        this.formData = {
          name: lang.name,
          dict1Uri: lang.dict1Uri,
          dict2Uri: lang.dict2Uri,
          translatorUri: lang.translatorUri,
          dict1PopUp: lang.dict1PopUp ?? false,
          dict2PopUp: lang.dict2PopUp ?? false,
          translatorPopUp: lang.translatorPopUp ?? false,
          sourceLang: lang.sourceLang ?? '',
          targetLang: lang.targetLang ?? '',
          exportTemplate: lang.exportTemplate,
          textSize: lang.textSize,
          characterSubstitutions: lang.characterSubstitutions,
          regexpSplitSentences: lang.regexpSplitSentences,
          exceptionsSplitSentences: lang.exceptionsSplitSentences,
          regexpWordCharacters: lang.regexpWordCharacters,
          removeSpaces: lang.removeSpaces,
          splitEachChar: lang.splitEachChar,
          parserType: lang.parserType ?? '',
          rightToLeft: lang.rightToLeft,
          ttsVoiceApi: lang.ttsVoiceApi,
          showRomanization: lang.showRomanization
        };

        this.originalData = cloneFormData(this.formData);
        await this.loadDefinitions();
      } catch (error) {
        console.error('Error loading language:', error);
        this.errors.general = t('language.errors.load_failed');
      }

      this.isLoading = false;
    },

    /**
     * Load language definitions (presets).
     */
    async loadDefinitions(): Promise<void> {
      try {
        const response = await LanguagesApi.getDefinitions();

        if (response.error || !response.data) {
          console.error(
            'Failed to load language definitions:',
            response.error
          );
          return;
        }

        this.definitions = response.data.definitions;
      } catch (error) {
        console.error('Error loading language definitions:', error);
      }
    },

    /**
     * Apply a language preset from the wizard.
     *
     * @param l1 Native language name (source for translator)
     * @param l2 Study language name (target language)
     */
    applyPreset(l1: string, l2: string): void {
      const l1Def = this.definitions[l1];
      const l2Def = this.definitions[l2];

      if (!l2Def) {
        console.error('Unknown study language:', l2);
        return;
      }

      // Set the language name
      this.formData.name = l2;

      // Set dictionary URLs
      const l1Code = l1Def?.glosbeIso || 'en';
      const l2Code = l2Def.glosbeIso;
      this.formData.dict1Uri = `https://glosbe.com/${l2Code}/${l1Code}/lwt_term`;
      this.formData.dict1PopUp = true;

      // Set translator URL
      const l1GoogleCode = l1Def?.googleIso || 'en';
      const l2GoogleCode = l2Def.googleIso;
      this.formData.translatorUri = `https://translate.google.com/?sl=${l2GoogleCode}&tl=${l1GoogleCode}&text=lwt_term&op=translate`;
      this.formData.translatorPopUp = true;

      // Set source/target language codes
      this.formData.sourceLang = l2GoogleCode;
      this.formData.targetLang = l1GoogleCode;

      // Set text size based on language
      this.formData.textSize = l2Def.biggerFont ? 150 : 100;

      // Set parsing rules
      this.formData.regexpSplitSentences = l2Def.sentSplRegExp;
      this.formData.regexpWordCharacters = l2Def.wordCharRegExp;
      this.formData.splitEachChar = l2Def.makeCharacterWord;
      this.formData.parserType = l2Def.parserType ?? '';
      this.formData.removeSpaces = l2Def.removeSpaces;
      this.formData.rightToLeft = l2Def.rightToLeft;
    },

    /**
     * Reset the form to initial state.
     */
    reset(): void {
      this.formData = createEmptyFormData();
      this.originalData = null;
      this.languageId = null;
      this.isNew = true;
      this.allLanguages = {};
      this.isLoading = false;
      this.isSubmitting = false;
      this.errors = createEmptyErrors();
    },

    /**
     * Validate all form fields.
     */
    validate(): boolean {
      this.validateField('name');
      this.validateField('regexpSplitSentences');
      this.validateField('regexpWordCharacters');
      this.validateField('textSize');
      this.validateField('ttsVoiceApi');
      return this.isValid;
    },

    /**
     * Validate a single form field.
     */
    validateField(field: keyof LanguageFormData): void {
      switch (field) {
        case 'name':
          if (!this.formData.name.trim()) {
            this.errors.name = t('language.errors.name_required');
          } else if (this.formData.name.length > 40) {
            this.errors.name = t('language.errors.name_too_long');
          } else {
            // Check for duplicate name (only among other languages)
            const existingId = this.allLanguages[this.formData.name];
            if (existingId && existingId !== this.languageId) {
              this.errors.name = t('language.errors.name_duplicate');
            } else {
              this.errors.name = null;
            }
          }
          break;

        case 'regexpSplitSentences':
          if (!this.formData.regexpSplitSentences.trim()) {
            this.errors.regexpSplitSentences = t('language.errors.split_sentences_required');
          } else {
            this.errors.regexpSplitSentences = null;
          }
          break;

        case 'regexpWordCharacters':
          if (!this.formData.regexpWordCharacters.trim()) {
            this.errors.regexpWordCharacters = t('language.errors.word_chars_required');
          } else {
            this.errors.regexpWordCharacters = null;
          }
          break;

        case 'textSize':
          if (this.formData.textSize < 50 || this.formData.textSize > 300) {
            this.errors.textSize = t('language.errors.text_size_range');
          } else {
            this.errors.textSize = null;
          }
          break;

        case 'ttsVoiceApi':
          // Validate JSON if provided
          if (this.formData.ttsVoiceApi.trim()) {
            try {
              JSON.parse(this.formData.ttsVoiceApi);
              this.errors.ttsVoiceApi = null;
            } catch {
              this.errors.ttsVoiceApi = t('language.errors.tts_voice_api_invalid');
            }
          } else {
            this.errors.ttsVoiceApi = null;
          }
          break;
      }
    },

    /**
     * Save the form (create or update language).
     */
    async save(): Promise<LanguageSaveResult> {
      // Validate first
      if (!this.validate()) {
        return { success: false, error: t('language.errors.fix_validation') };
      }

      this.isSubmitting = true;
      this.errors.general = null;

      try {
        if (this.isNew) {
          // Create new language
          const createData: LanguageCreateRequest = { ...this.formData };

          const response = await LanguagesApi.create(createData);

          if (response.error || !response.data?.success) {
            const errorMsg =
              response.error ||
              response.data?.error ||
              t('language.errors.create_failed');
            this.errors.general = errorMsg;
            this.isSubmitting = false;
            return { success: false, error: errorMsg };
          }

          this.languageId = response.data.id ?? null;
          this.isNew = false;
          this.originalData = cloneFormData(this.formData);
          this.isSubmitting = false;

          return { success: true, id: response.data.id };
        } else {
          // Update existing language
          if (this.languageId === null) {
            this.isSubmitting = false;
            return { success: false, error: t('language.errors.id_missing') };
          }

          const updateData: LanguageUpdateRequest = { ...this.formData };

          const response = await LanguagesApi.update(
            this.languageId,
            updateData
          );

          if (response.error || !response.data?.success) {
            const errorMsg =
              response.error ||
              response.data?.error ||
              t('language.errors.update_failed');
            this.errors.general = errorMsg;
            this.isSubmitting = false;
            return { success: false, error: errorMsg };
          }

          this.originalData = cloneFormData(this.formData);
          this.isSubmitting = false;

          return {
            success: true,
            id: this.languageId,
            reparsed: response.data.reparsed
          };
        }
      } catch (error) {
        console.error('Error saving language:', error);
        this.errors.general = t('language.errors.save_failed');
        this.isSubmitting = false;
        return { success: false, error: this.errors.general };
      }
    }
  };
}

/**
 * Initialize the language form store as an Alpine.js store.
 */
export function initLanguageFormStore(): void {
  Alpine.store('languageForm', createLanguageFormStore());
}

/**
 * Get the language form store instance.
 */
export function getLanguageFormStore(): LanguageFormStoreState {
  return Alpine.store('languageForm') as LanguageFormStoreState;
}

// Register the store immediately
initLanguageFormStore();

// Expose for global access
declare global {
  interface Window {
    getLanguageFormStore: typeof getLanguageFormStore;
  }
}

window.getLanguageFormStore = getLanguageFormStore;
