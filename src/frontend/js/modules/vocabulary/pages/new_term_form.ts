/**
 * Standalone "new term" form — saves through `POST /api/v1/terms/for-language`.
 *
 * Replaces the same-origin POST to `/word/new` (issue #262). That route read
 * `WoLgID` straight from the request and fed it into the occurrence-linking
 * path; the endpoint this now posts to validates the language against the
 * caller's own before writing anything.
 *
 * Tags stay on the Tagify input the page already renders, so they are read
 * from the DOM at submit time rather than bound here.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.4.0
 */

import Alpine from 'alpinejs';
import { t } from '@shared/i18n/translator';
import { TermsApi } from '../api/terms_api';
import { readPageConfig } from '@shared/utils/page_config';

/** Config the scaffold ships. */
export interface NewTermFormConfig {
  languageId: number;
  textId: number;
}

/** Alpine component state. */
export interface NewTermFormData {
  languageId: number;
  textId: number;
  text: string;
  lemma: string;
  translation: string;
  romanization: string;
  sentence: string;
  notes: string;
  isSaving: boolean;
  error: string;
  init(): void;
  save(): Promise<void>;
  collectTags(): string[];
}

/** Read the scaffold's config blob. */
function readConfig(): NewTermFormConfig {
  // On a missing or malformed blob the language stays 0 and the save
  // reports it, rather than the form failing to start at all.
  return readPageConfig<NewTermFormConfig>('new-term-config', {
    languageId: 0,
    textId: 0
  });
}

/**
 * Build the new-term form component.
 *
 * @returns Alpine component data
 */
export function newTermFormData(): NewTermFormData {
  return {
    languageId: 0,
    textId: 0,
    text: '',
    lemma: '',
    translation: '',
    romanization: '',
    sentence: '',
    notes: '',
    isSaving: false,
    error: '',

    init(): void {
      const config = readConfig();
      this.languageId = config.languageId;
      this.textId = config.textId;
    },

    /**
     * Read the tags out of the Tagify input the page renders.
     *
     * Tagify replaces its original element, so the values live in the DOM
     * rather than in component state.
     */
    collectTags(): string[] {
      const input = document.querySelector<HTMLInputElement>('input[name="TermTags[TagList]"]');
      if (!input || input.value.trim() === '') {
        return [];
      }
      return input.value
        .split(',')
        .map(tag => tag.trim())
        .filter(tag => tag !== '');
    },

    async save(): Promise<void> {
      if (this.text.trim() === '') {
        this.error = t('vocabulary.flash.term_text_required');
        return;
      }

      this.isSaving = true;
      this.error = '';

      const response = await TermsApi.createForLanguage({
        language_id: this.languageId,
        text: this.text,
        lemma: this.lemma,
        translation: this.translation,
        romanization: this.romanization,
        sentence: this.sentence,
        notes: this.notes,
        status: 1,
        tags: this.collectTags()
      });

      this.isSaving = false;

      if (response.error || !response.data || !response.data.success) {
        const body = response.data;
        this.error = (body && body.error)
          ? body.error
          : (response.error || t('vocabulary.flash.term_save_failed'));
        return;
      }

      // The page opens from the term list and from a reading view; both expect
      // to land back where they came from.
      window.location.href = this.textId > 0
        ? `/text/${this.textId}/read`
        : '/words';
    }
  };
}

Alpine.data('newTermForm', newTermFormData);
