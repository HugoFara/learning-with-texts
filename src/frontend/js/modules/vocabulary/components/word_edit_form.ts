/**
 * Word Edit Form - Alpine.js component for term editing in modal.
 *
 * Provides a reactive form for creating and editing terms within the word modal.
 * Integrates with the word form store for state management.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.0.0
 */

import Alpine from 'alpinejs';
import type { WordFormStoreState, SaveResult } from '../stores/word_form_store';
import type { WordStoreState } from '../stores/word_store';
import type { SimilarTermForEdit } from '@modules/vocabulary/api/terms_api';
import { updateWordStatusInDOM, updateWordTranslationInDOM } from '@modules/text/pages/reading/text_renderer';
import { t } from '@shared/i18n/translator';
import { statusTagClass } from '@shared/stores/statuses';

/**
 * Status display information.
 *
 * `abbr` is a language-neutral short label ("1".."5"); empty for 98/99 where
 * the localized full `label` should be shown instead.
 */
interface StatusInfo {
  value: number;
  label: string;
  abbr: string;
}

type FormDataField = 'translation' | 'romanization' | 'sentence' | 'notes';

/**
 * Edit-form section shown at a time. Splitting the form into tabs (one
 * section visible at once) keeps the modal short enough to leave the
 * reading text visible behind it — see word_modal.php / styles.css
 * `.reading-modal`.
 */
export type FormTab = 'translate' | 'example' | 'notes' | 'tags';

/**
 * Status definitions matching word_modal.ts. Computed lazily so translations are loaded.
 */
function buildStatuses(): StatusInfo[] {
  const learning = t('common.status_learning');
  const learned = t('common.status_learned');
  const wellKnown = t('common.status_well_known');
  const ignored = t('common.status_ignored');
  // For numeric statuses `abbr` is the digit (button label).
  // For 98/99 there is no language-neutral abbreviation, so the
  // localized full name doubles as the button label.
  return [
    { value: 1, label: `${learning} (1)`, abbr: '1' },
    { value: 2, label: `${learning} (2)`, abbr: '2' },
    { value: 3, label: `${learning} (3)`, abbr: '3' },
    { value: 4, label: `${learning} (4)`, abbr: '4' },
    { value: 5, label: learned, abbr: '5' },
    { value: 99, label: wellKnown, abbr: wellKnown },
    { value: 98, label: ignored, abbr: ignored }
  ];
}

/**
 * Word edit form Alpine.js component interface.
 */
export interface WordEditFormData {
  // Computed properties
  readonly formStore: WordFormStoreState;
  readonly wordStore: WordStoreState;
  readonly isLoading: boolean;
  readonly isSubmitting: boolean;
  readonly isDirty: boolean;
  readonly isValid: boolean;
  readonly isNewWord: boolean;
  readonly showRomanization: boolean;
  readonly statuses: StatusInfo[];

  // Section tabs — only one is shown at a time (issue: modal was hiding the
  // reading text). Resets to 'translate' each time the form remounts, since
  // it lives inside the parent's `x-if="viewMode === 'edit'"` template.
  activeTab: FormTab;
  setActiveTab(tab: FormTab): void;
  tabHasError(tab: FormTab): boolean;

  // CSP-safe proxy properties for x-model (avoids nested property assignments)
  translation: string;
  romanization: string;
  sentence: string;
  notes: string;
  readonly formText: string;
  readonly hasGeneralError: boolean;
  readonly generalError: string | null;
  readonly formTags: string[];
  readonly hasTags: boolean;
  readonly hasSimilarTerms: boolean;
  readonly formSimilarTerms: SimilarTermForEdit[];
  readonly canSubmit: boolean;

  // Tag input state
  tagInput: string;
  showTagSuggestions: boolean;
  filteredTags: string[];

  // Methods
  validateField(field: string): void;
  clearGeneralError(): void;
  setFormStatus(value: number): void;
  getStatusButtonClass(status: number): string;
  getSimilarTermDisplay(term: SimilarTermForEdit): string;
  hasFieldError(field: FormDataField): boolean;
  getFieldError(field: FormDataField): string | null;
  save(): Promise<void>;
  cancel(): void;
  addTag(tag: string): void;
  removeTag(tag: string): void;
  filterTags(): void;
  selectTagSuggestion(tag: string): void;
  hideTagSuggestions(): void;
  copyFromSimilar(term: SimilarTermForEdit): void;
  getStatusClass(status: number): string;

  // Callbacks (set by parent modal)
  onSaved?: (result: SaveResult) => void;
  onCancelled?: () => void;
}

/**
 * Create the word edit form Alpine.js component data.
 */
export function wordEditFormData(): WordEditFormData {
  return {
    // Section tabs
    activeTab: 'translate' as FormTab,

    setActiveTab(tab: FormTab): void {
      this.activeTab = tab;
    },

    tabHasError(tab: FormTab): boolean {
      switch (tab) {
        case 'translate':
          return this.hasFieldError('translation') || this.hasFieldError('romanization');
        case 'example':
          return this.hasFieldError('sentence');
        case 'notes':
          return this.hasFieldError('notes');
        case 'tags':
          return false;
      }
    },

    // Tag input state
    tagInput: '',
    showTagSuggestions: false,
    filteredTags: [],

    get formStore(): WordFormStoreState {
      return Alpine.store('wordForm') as WordFormStoreState;
    },

    get wordStore(): WordStoreState {
      return Alpine.store('words') as WordStoreState;
    },

    get isLoading(): boolean {
      return this.formStore.isLoading;
    },

    get isSubmitting(): boolean {
      return this.formStore.isSubmitting;
    },

    get isDirty(): boolean {
      return this.formStore.isDirty;
    },

    get isValid(): boolean {
      return this.formStore.isValid;
    },

    get isNewWord(): boolean {
      return this.formStore.isNewWord;
    },

    get showRomanization(): boolean {
      return this.formStore.showRomanization;
    },

    get statuses(): StatusInfo[] {
      return buildStatuses();
    },

    // CSP-safe proxy properties — Alpine CSP build prohibits nested property
    // assignments like `formStore.formData.translation = ...` in x-model.
    get translation(): string {
      return this.formStore.formData.translation;
    },
    set translation(value: string) {
      this.formStore.formData.translation = value;
    },

    get romanization(): string {
      return this.formStore.formData.romanization;
    },
    set romanization(value: string) {
      this.formStore.formData.romanization = value;
    },

    get sentence(): string {
      return this.formStore.formData.sentence;
    },
    set sentence(value: string) {
      this.formStore.formData.sentence = value;
    },

    get notes(): string {
      return this.formStore.formData.notes;
    },
    set notes(value: string) {
      this.formStore.formData.notes = value;
    },

    get formText(): string {
      return this.formStore.formData.text;
    },

    get hasGeneralError(): boolean {
      return !!this.formStore.errors.general;
    },

    get generalError(): string | null {
      return this.formStore.errors.general;
    },

    get formTags(): string[] {
      return this.formStore.formData.tags;
    },

    get hasTags(): boolean {
      return this.formStore.formData.tags.length > 0;
    },

    get hasSimilarTerms(): boolean {
      return this.formStore.similarTerms.length > 0;
    },

    get formSimilarTerms(): SimilarTermForEdit[] {
      return this.formStore.similarTerms;
    },

    get canSubmit(): boolean {
      return this.formStore.canSubmit;
    },

    clearGeneralError(): void {
      this.formStore.errors.general = null;
    },

    setFormStatus(value: number): void {
      this.formStore.formData.status = value;
    },

    getStatusButtonClass(status: number): string {
      const colorClass = this.getStatusClass(status);
      const outlined = this.formStore.formData.status !== status ? ' is-outlined' : '';
      return colorClass + outlined;
    },

    hasFieldError(field: FormDataField): boolean {
      return !!this.formStore.errors[field];
    },

    getFieldError(field: FormDataField): string | null {
      return this.formStore.errors[field] ?? null;
    },

    getSimilarTermDisplay(term: SimilarTermForEdit): string {
      return term.translation ? ': ' + term.translation : '';
    },

    validateField(field: string): void {
      this.formStore.validateField(field as keyof typeof this.formStore.formData);
    },

    async save(): Promise<void> {
      const result = await this.formStore.save();

      if (!result.success) {
        // Jump to the first tab holding the invalid field so it's visible.
        const tabs: FormTab[] = ['translate', 'example', 'notes'];
        const firstBadTab = tabs.find(tab => this.tabHasError(tab));
        if (firstBadTab) this.activeTab = firstBadTab;
        return;
      }

      if (result.hex) {
        const hex = result.hex;
        const status = this.formStore.formData.status;
        const translation = this.formStore.formData.translation;
        const romanization = this.formStore.formData.romanization;
        const wordId = result.wordId ?? null;

        // Update the word store with new data
        this.wordStore.updateWordInStore(hex, {
          wordId,
          status,
          translation,
          romanization,
          tags: this.formStore.formData.tags.join(', ')
        });

        // Update the DOM elements in the reading text
        updateWordStatusInDOM(hex, status, wordId);
        updateWordTranslationInDOM(hex, translation, romanization);

        // Call the onSaved callback if set, otherwise signal to close the modal
        if (this.onSaved) {
          this.onSaved(result);
        } else {
          // Default behavior: signal the modal to close
          this.formStore.shouldCloseModal = true;
        }
      }
    },

    cancel(): void {
      if (this.isDirty) {
        if (!confirm('You have unsaved changes. Are you sure you want to cancel?')) {
          return;
        }
      }

      // Call the onCancelled callback if set, otherwise signal to return to info view
      if (this.onCancelled) {
        this.onCancelled();
      } else {
        // Default behavior: signal the modal to return to info view
        this.formStore.shouldReturnToInfo = true;
      }
    },

    addTag(tag: string): void {
      tag = tag.trim();
      if (tag && !this.formStore.formData.tags.includes(tag)) {
        this.formStore.formData.tags.push(tag);
      }
      this.tagInput = '';
      this.showTagSuggestions = false;
    },

    removeTag(tag: string): void {
      const index = this.formStore.formData.tags.indexOf(tag);
      if (index > -1) {
        this.formStore.formData.tags.splice(index, 1);
      }
    },

    filterTags(): void {
      const input = this.tagInput.toLowerCase().trim();
      if (!input) {
        this.filteredTags = [];
        this.showTagSuggestions = false;
        return;
      }

      // Filter tags that start with input and are not already selected
      this.filteredTags = this.formStore.allTags
        .filter(tag =>
          tag.toLowerCase().startsWith(input) &&
          !this.formStore.formData.tags.includes(tag)
        )
        .slice(0, 8); // Limit to 8 suggestions

      this.showTagSuggestions = this.filteredTags.length > 0;
    },

    selectTagSuggestion(tag: string): void {
      this.addTag(tag);
    },

    hideTagSuggestions(): void {
      // Delay hiding to allow click on suggestion
      setTimeout(() => {
        this.showTagSuggestions = false;
      }, 200);
    },

    copyFromSimilar(term: SimilarTermForEdit): void {
      if (term.translation) {
        this.formStore.copyTranslationFromSimilar(term.translation);
      }
    },

    getStatusClass(status: number): string {
      return statusTagClass(status);
    },

    // Callbacks - can be set by parent component
    onSaved: undefined,
    onCancelled: undefined
  };
}

/**
 * Initialize the word edit form Alpine.js component.
 */
export function initWordEditFormAlpine(): void {
  Alpine.data('wordEditForm', wordEditFormData);
}

// Register the component immediately
initWordEditFormAlpine();

// Expose for global access
declare global {
  interface Window {
    wordEditFormData: typeof wordEditFormData;
  }
}

window.wordEditFormData = wordEditFormData;
