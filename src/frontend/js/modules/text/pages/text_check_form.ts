/**
 * Text Check Form - check how a text will be parsed, without saving it.
 *
 * The form used to POST to /text/check, which answered with a whole
 * server-rendered report page and a "<< Back" button. It now asks
 * `POST /api/v1/texts/check` and renders the report in place (#262, #266).
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.5.1
 */

import Alpine from 'alpinejs';
import { t } from '@shared/i18n/translator';
import { checkTextForm } from './text_check_run';

/** Alpine component state for the check form. */
export interface TextCheckFormData {
  checking: boolean;
  error: string;
  hasReport: boolean;
  hasError(): boolean;
  submitLabel(): string;
  handleSubmit(event: Event): void;
}

/**
 * Build the check form component.
 *
 * @returns Alpine component state
 */
export function textCheckFormData(): TextCheckFormData {
  return {
    checking: false,
    error: '',
    hasReport: false,

    hasError(): boolean {
      return this.error !== '';
    },

    submitLabel(): string {
      return this.checking ? t('text.check.checking') : t('text.common.check');
    },

    /**
     * Ask the API what the parser makes of the text, and render the answer.
     *
     * @param event Submit event
     */
    handleSubmit(event: Event) {
      event.preventDefault();
      const form = event.target as HTMLFormElement | null;
      if (!form || this.checking) return;

      this.error = '';
      this.checking = true;

      void checkTextForm(form, 'check_text').then((error) => {
        this.checking = false;
        this.error = error;
        this.hasReport = error === '';
      });
    }
  };
}

Alpine.data('textCheckForm', textCheckFormData);
