/**
 * Text Form Save - Send the text editor's fields to /api/v1/texts.
 *
 * The editor used to be a plain form POST to /texts/new or /texts. Saving
 * through the API instead means the editor works against a configurable API
 * base URL rather than the page origin, which a bundled client needs (#262).
 *
 * The form markup is unchanged: the fields keep their `TxTitle` / `TxText` /
 * `TxLgID` names so the tag widget and the file-import helpers, which write
 * into inputs by name, keep working untouched.
 *
 * @license unlicense
 * @since   3.4.2
 */

import { TextsApi, type TextCreateRequest } from '../api/texts_api';

/**
 * Where a save ended up.
 *
 * `bookId` is set when the text was long enough that the server split it into
 * a book of chapters; `textId` is then the first chapter.
 */
export interface TextSaveOutcome {
  textId: number | null;
  bookId: number | null;
  error: string;
}

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
 * Read the tag widget's selection.
 *
 * The widget posts under `TextTags[TagList][]` and may put several
 * comma-separated names in one value, so split the same way the server's
 * flattenTagList() does.
 *
 * @param form The text editor form
 * @returns Tag names, without blanks
 */
export function readTagNames(form: HTMLFormElement): string[] {
  const inputs = form.querySelectorAll<HTMLInputElement | HTMLSelectElement>(
    '[name="TextTags[TagList][]"]'
  );

  const names: string[] = [];
  inputs.forEach((input) => {
    if (input instanceof HTMLSelectElement) {
      Array.from(input.selectedOptions).forEach((option) => {
        names.push(...splitTagValue(option.value));
      });
      return;
    }
    names.push(...splitTagValue(input.value));
  });

  return names;
}

/**
 * Split one widget value into individual tag names.
 */
function splitTagValue(value: string): string[] {
  return value
    .split(',')
    .map((part) => part.trim())
    .filter((part) => part !== '');
}

/**
 * Collect the editor's fields into an API payload.
 *
 * @param form The text editor form
 * @returns The payload TextsApi.create/update expects
 */
export function readTextForm(form: HTMLFormElement): TextCreateRequest {
  return {
    title: fieldValue(form, 'TxTitle').trim(),
    langId: Number(fieldValue(form, 'TxLgID')) || 0,
    text: fieldValue(form, 'TxText'),
    sourceUri: fieldValue(form, 'TxSourceURI'),
    audioUri: fieldValue(form, 'TxAudioURI'),
    tags: readTagNames(form),
  };
}

/**
 * Save the editor's form through the API.
 *
 * @param form   The text editor form
 * @param textId Text ID to update, or 0 to create
 * @returns Where the save ended up, or a message to show
 */
export async function saveTextForm(
  form: HTMLFormElement,
  textId: number
): Promise<TextSaveOutcome> {
  const payload = readTextForm(form);

  const response =
    textId > 0
      ? await TextsApi.update(textId, payload)
      : await TextsApi.create(payload);

  if (response.error || !response.data) {
    return {
      textId: null,
      bookId: null,
      error: response.error ?? 'Could not save the text.',
    };
  }

  return {
    textId: response.data.textId,
    bookId: response.data.bookId,
    error: '',
  };
}

/**
 * Save the archived text editor's form through the API.
 *
 * The archived editor shares the active editor's field names, so it shares
 * the payload reader; only the endpoint differs, because an archived text is
 * saved without being reparsed.
 *
 * @param form   The archived text editor form
 * @param textId Archived text ID to update
 * @returns Where the save ended up, or a message to show
 */
export async function saveArchivedTextForm(
  form: HTMLFormElement,
  textId: number
): Promise<TextSaveOutcome> {
  const response = await TextsApi.updateArchived(textId, readTextForm(form));

  if (response.error || !response.data) {
    return {
      textId: null,
      bookId: null,
      error: response.error ?? 'Could not save the text.',
    };
  }

  return {
    textId: response.data.textId,
    bookId: null,
    error: '',
  };
}
