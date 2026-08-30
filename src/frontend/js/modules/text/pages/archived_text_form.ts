/**
 * Archived Text Form - saves the archived text editor through the API.
 *
 * The editor used to post back to /text/archived/{id}/edit, which re-rendered
 * the page with an "Updated: n" banner. Saving through
 * `PUT /api/v1/texts/archived/{id}` instead means the editor works against a
 * configurable API base URL rather than the page origin (#262).
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.5.1
 */

import Alpine from 'alpinejs';
import { saveArchivedTextForm } from './text_form_save';

/** Alpine component state for the archived text editor. */
export interface ArchivedTextFormData {
  textId: number;
  saving: boolean;
  saveError: string;
  init(): void;
  hasSaveError(): boolean;
  handleSubmit(event: Event): void;
}

/**
 * Build the archived text editor component.
 *
 * @returns Alpine component state
 */
export function archivedTextFormData(): ArchivedTextFormData {
  return {
    textId: 0,
    saving: false,
    saveError: '',

    init() {
      const configEl = document.getElementById('archived-text-config');
      if (!configEl?.textContent) return;
      try {
        const config = JSON.parse(configEl.textContent) as { textId?: number };
        this.textId = config.textId ?? 0;
      } catch {
        this.textId = 0;
      }
    },

    hasSaveError(): boolean {
      return this.saveError !== '';
    },

    /**
     * Save through PUT /api/v1/texts/archived/{id} instead of posting.
     *
     * @param event Submit event
     */
    handleSubmit(event: Event) {
      event.preventDefault();
      const form = event.target as HTMLFormElement | null;
      if (!form || this.saving) return;

      this.saveError = '';
      this.saving = true;

      void saveArchivedTextForm(form, this.textId).then((result) => {
        if (result.textId === null) {
          this.saveError = result.error;
          this.saving = false;
          return;
        }
        window.location.href = `/text/archived#rec${result.textId}`;
      });
    }
  };
}

Alpine.data('archivedTextForm', archivedTextFormData);
