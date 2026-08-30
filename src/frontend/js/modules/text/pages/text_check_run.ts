/**
 * Text Check Run - ask the API how a text parses and render the answer.
 *
 * Shared by the two places that offer a parse check: the standalone check
 * page and the "Check" button on the text editor. Both used to post to a
 * server-rendered report page (#262, #266).
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.5.1
 */

import { t } from '@shared/i18n/translator';
import { TextsApi } from '../api/texts_api';
import { renderCheckReport } from './text_check_display';

/**
 * Read one field's value out of a form.
 */
function fieldValue(form: HTMLFormElement, name: string): string {
  const el = form.querySelector<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>(
    `[name="${name}"]`
  );
  return el?.value ?? '';
}

/**
 * Check the text in a form and render the report into a container.
 *
 * @param form        The form holding TxText and TxLgID
 * @param containerId Element to render the report into
 * @returns Empty string on success, otherwise a message to show
 */
export async function checkTextForm(
  form: HTMLFormElement,
  containerId: string
): Promise<string> {
  const text = fieldValue(form, 'TxText');
  const langId = Number(fieldValue(form, 'TxLgID')) || 0;

  if (text.trim() === '' || langId <= 0) {
    return t('text.check.failed');
  }

  const response = await TextsApi.check(langId, text);
  const container = document.getElementById(containerId);

  if (response.error || !response.data || !container) {
    return response.error || t('text.check.failed');
  }

  renderCheckReport(response.data, container);
  container.scrollIntoView({ behavior: 'smooth', block: 'start' });
  return '';
}
