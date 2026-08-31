/**
 * Standalone term editor page.
 *
 * /word/edit, /word/edit-term and /words/{id}/edit used to render a PHP form
 * that posted back and returned a confirmation page. They now mount the same
 * editor the reading view opens in a modal, so there is one implementation and
 * the outcome is rendered from the API response.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.3.0
 */

import Alpine from 'alpinejs';
import { loadTermEditor, wireTermEditor } from '@modules/vocabulary/components/term_edit_modal';
import { t } from '@shared/i18n/translator';
import { hasPageConfig, readPageConfig } from '@shared/utils/page_config';

interface TermEditPageConfig {
  textId: number;
  position: number;
  wordId: number | null;
  returnUrl: string;
}

interface TermEditPageState {
  title: string;
  errorMessage: string;
  isLoading: boolean;
  returnUrl: string;
  hasError(): boolean;
  init(): void;
  leave(): void;
}

/**
 * Read the server-emitted config blob.
 *
 * @returns Parsed config, or null when the blob is missing or malformed
 */
function readConfig(): TermEditPageConfig | null {
  // Absence is meaningful here: it means this is not the term-edit page.
  if (!hasPageConfig('term-edit-page-config')) {
    return null;
  }
  return readPageConfig<TermEditPageConfig>('term-edit-page-config', {
    textId: 0,
    position: 0,
    wordId: null,
    returnUrl: ''
  });
}

/**
 * Where to send the user once they are done editing.
 *
 * Prefers the page they arrived from so the reading view and the term list
 * both feel like they were never left, and falls back to the server's default.
 *
 * @param fallback URL to use when there is no usable referrer
 *
 * @returns Destination URL
 */
function resolveReturnUrl(fallback: string): string {
  const referrer = document.referrer;
  if (referrer !== '') {
    try {
      const url = new URL(referrer, window.location.href);
      // Same-origin only, and never bounce back to this editor.
      if (url.origin === window.location.origin && !url.pathname.includes('/edit')) {
        return url.href;
      }
    } catch {
      // Malformed referrer — fall through to the default.
    }
  }
  return fallback;
}

/**
 * Alpine component backing the standalone editor page.
 *
 * @returns Component state and methods
 */
export function termEditPageData(): TermEditPageState {
  return {
    title: '',
    errorMessage: '',
    isLoading: true,
    returnUrl: '/words',

    /**
     * Whether loading the term failed.
     *
     * @returns True when an error should be shown instead of the form
     */
    hasError(): boolean {
      return this.errorMessage !== '';
    },

    /** Leave the editor. */
    leave(): void {
      window.location.href = this.returnUrl;
    },

    init(): void {
      const config = readConfig();
      if (!config) {
        this.isLoading = false;
        this.errorMessage = t('vocabulary.form.edit_term');
        return;
      }

      this.returnUrl = resolveReturnUrl(config.returnUrl);

      void loadTermEditor(
        config.textId,
        config.position,
        config.wordId ?? undefined
      ).then((result) => {
        this.isLoading = false;

        const container = document.getElementById('term-edit-page-form');
        if (!container) return;

        if (!result.ok) {
          this.errorMessage = result.error;
          return;
        }

        this.title = result.editor.title;
        container.innerHTML = result.editor.html;

        wireTermEditor({
          onSaved: () => this.leave(),
          onCancel: () => this.leave()
        });
      });
    }
  };
}

Alpine.data('termEditPage', termEditPageData);
