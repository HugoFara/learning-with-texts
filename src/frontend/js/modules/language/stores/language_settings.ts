/**
 * Language settings utilities.
 *
 * @author  andreask7 <andreasks7@users.noreply.github.com>
 * @license Unlicense <http://unlicense.org/>
 * @since   2.10.0-fork Extracted from legacy/pgm.ts
 */

import { t as translate } from '@shared/i18n/translator';
import { confirmAction } from '@shared/components/confirm_dialog';
import { onDomReady } from '@shared/utils/dom_ready';
import { apiPut, getCsrfToken } from '@shared/api/client';

/**
 * Statistics for a text showing word status counts.
 */
export interface TextStats {
  unknown: number;
  s1: number;
  s2: number;
  s3: number;
  s4: number;
  s5: number;
  s98: number;
  s99: number;
  total: number;
}

/**
 * Response from the settings API when changing language.
 */
export interface LanguageChangeResponse {
  message?: string;
  error?: string;
  text_count?: number;
  last_text?: {
    id: number;
    title: string;
    language_id: number;
    language_name: string;
    annotated: boolean;
    stats?: TextStats;
  } | null;
}

/**
 * Custom event dispatched when language changes via AJAX.
 */
export interface LanguageChangedEvent extends CustomEvent {
  detail: {
    languageId: string;
    languageName: string;
    response: LanguageChangeResponse;
  };
}

/**
 * Set the current language.
 *
 * @param ctl Current language selector element
 * @param url URL to redirect to
 */
export async function setLang(ctl: HTMLSelectElement, url: string): Promise<void> {
  const languageId = ctl.options[ctl.selectedIndex].value;
  await setLangAsync(languageId);
  location.href = url;
}

/**
 * Set the current language via AJAX (no page refresh).
 *
 * @param languageId The language ID to set
 * @returns Promise with the API response
 */
export async function setLangAsync(languageId: string): Promise<LanguageChangeResponse> {
  const headers: Record<string, string> = { 'Content-Type': 'application/json' };
  const csrf = getCsrfToken();
  if (csrf) {
    headers['X-CSRF-TOKEN'] = csrf;
  }
  const response = await fetch('/api/v1/settings', {
    method: 'POST',
    headers,
    body: JSON.stringify({
      key: 'currentlanguage',
      value: languageId
    })
  });

  if (!response.ok) {
    throw new Error(`HTTP error! status: ${response.status}`);
  }

  return response.json();
}

/**
 * Reset the current language setting via AJAX (no page refresh).
 *
 * @returns Promise with the API response
 */
export async function resetAllAsync(): Promise<LanguageChangeResponse> {
  const headers: Record<string, string> = { 'Content-Type': 'application/json' };
  const csrf = getCsrfToken();
  if (csrf) {
    headers['X-CSRF-TOKEN'] = csrf;
  }
  const response = await fetch('/api/v1/settings', {
    method: 'POST',
    headers,
    body: JSON.stringify({
      key: 'currentlanguage',
      value: ''
    })
  });

  if (!response.ok) {
    throw new Error(`HTTP error! status: ${response.status}`);
  }

  return response.json();
}

/**
 * Initialize event delegation for language setting elements.
 *
 * Handles elements with data-action="set-lang".
 * Uses AJAX when data-ajax="true" is present.
 */
function initSetLangEventDelegation(): void {
  document.addEventListener('change', async function (e) {
    const target = e.target as HTMLElement;
    if (target.matches('[data-action="set-lang"]')) {
      const selectEl = target as HTMLSelectElement;
      const useAjax = selectEl.dataset.ajax === 'true';
      const redirectUrl = selectEl.dataset.redirect || '/';
      const languageId = selectEl.options[selectEl.selectedIndex].value;
      const languageName = selectEl.options[selectEl.selectedIndex].text;

      if (useAjax) {
        try {
          const response = await setLangAsync(languageId);

          // Dispatch custom event for components to react to
          const event = new CustomEvent('lwt:languageChanged', {
            detail: {
              languageId,
              languageName,
              response
            }
          }) as LanguageChangedEvent;
          document.dispatchEvent(event);
        } catch (error) {
          console.error('Failed to change language:', error);
        }
      } else {
        await setLang(selectEl, redirectUrl);
      }
    }
  });
}

// Auto-initialize when DOM is ready
onDomReady(() => {
  initSetLangEventDelegation();
});

/**
 * Reset current language to default.
 *
 * @param url URL to redirect to
 */
export async function resetAll(url: string): Promise<void> {
  await resetAllAsync();
  location.href = url;
}

/**
 * Mark all unknown words in a text as well-known.
 *
 * @param t Text ID
 */
export async function iknowall(t: string | number): Promise<void> {
  if (!await confirmAction(translate('common.dialog.mark_all_known'))) return;

  const textId = typeof t === 'string' ? parseInt(t, 10) : t;

  try {
    await apiPut(`/texts/${textId}/mark-all-wellknown`, {});
    // Reload page to show updated word statuses
    window.location.reload();
  } catch (error) {
    console.error('Failed to mark all words as well-known:', error);
    alert('Failed to mark words. Please try again.');
  }
}

/**
 * Check is the table prefix is a valid alphanumeric character.
 * Create an alert if not.
 *
 * @param p Table prefix
 * @returns true is the prefix is valid
 */
export function validateTablePrefix(p: string): boolean {
  const re = /^[_a-zA-Z0-9]*$/;
  const r = p.length <= 20 && p.length > 0 && re.test(p);
  if (!r) {
    alert(
      'Table Set Name (= Table Prefix) must' +
      '\ncontain 1 to 20 characters (only 0-9, a-z, A-Z and _).' +
      '\nPlease correct your input.'
    );
  }
  return r;
}
