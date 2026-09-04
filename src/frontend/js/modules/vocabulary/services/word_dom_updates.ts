/**
 * Word DOM Updates - Functions to update word elements in the reading frame
 *
 * This module contains functions to update word status, translations, and
 * other attributes in the DOM when words are saved, updated, or deleted.
 * They are called from the reading view's keyboard shortcuts and popup actions
 * after an API call succeeds.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.0.0
 */

import { createWordTooltip } from '@modules/vocabulary/services/word_status';
import { TERM_STATUS_VALUES, statusCssClass } from '@shared/stores/statuses';

/**
 * Get the parent document context (for frame-based layouts).
 * Falls back to current document if parent is not accessible.
 */
export function getParentContext(): Document {
  try {
    return window.parent?.document ?? document;
  } catch {
    return document;
  }
}

/**
 * Get a specific frame element from the parent context.
 *
 * @param frameId The ID of the frame element (e.g., 'frame-l', 'frame-h')
 */
export function getFrameElement(frameId: string): HTMLElement | null {
  const context = getParentContext();
  return context.getElementById(frameId);
}

/**
 * Generate a native tooltip for a word.
 *
 * @param word The word text
 * @param translation The translation
 * @param romanization The romanization
 * @param status The word status
 * @returns The tooltip string
 */
export function generateTooltip(
  word: string,
  translation: string,
  romanization: string,
  status: number | string
): string {
  return createWordTooltip(word, translation, romanization, status);
}

export interface WordUpdateParams {
  wid: number;
  status: number | string;
  translation: string;
  romanization: string;
  text: string;
  hex?: string;
}

/**
 * Update an existing word in the DOM (word that was modified).
 * Updates elements with the word's ID class.
 *
 * @param params Word update parameters
 * @param oldStatus The previous status value
 */
export function updateExistingWordInDOM(params: WordUpdateParams, oldStatus: number | string): void {
  const { wid, status, translation, romanization, text } = params;
  const context = getParentContext();
  const title = generateTooltip(text, translation, romanization, status);

  context.querySelectorAll<HTMLElement>(`.word${wid}`).forEach(el => {
    el.classList.remove(`status${oldStatus}`);
    el.classList.add(`status${status}`);
    el.setAttribute('data_trans', translation);
    el.setAttribute('data_rom', romanization);
    el.setAttribute('data_status', String(status));
    el.title = title;
  });
}

/**
 * Update word status in the DOM without changing translation/romanization.
 *
 * @param wid Word ID
 * @param status New status
 * @param word Word text
 * @param translation Translation text
 * @param romanization Romanization text
 */
export function updateWordStatusInDOM(
  wid: number,
  status: number | string,
  word: string,
  translation: string,
  romanization: string
): void {
  const frameL = getFrameElement('frame-l');
  if (!frameL) return;

  const title = generateTooltip(word, translation, romanization, status);

  frameL.querySelectorAll<HTMLElement>(`.word${wid}`).forEach(el => {
    // Derived rather than listed: a hand-written list of every status class
    // silently stops clearing one the day a status is added.
    el.classList.remove(...TERM_STATUS_VALUES.map(statusCssClass));
    el.classList.add(statusCssClass(Number(status)));
    el.setAttribute('data_status', String(status));
    el.title = title;
  });
}

/**
 * Mark a word as well-known (status 99) in the DOM.
 *
 * @param wid Word ID
 * @param hex Hex class identifier for the term
 * @param term Term text
 */
export function markWordWellKnownInDOM(wid: number, hex: string, term: string): void {
  const frameL = getFrameElement('frame-l');
  if (!frameL) return;

  const title = createWordTooltip(term, '*', '', '99');

  frameL.querySelectorAll<HTMLElement>(`[data_hex="${hex}"]`).forEach(el => {
    el.classList.remove('status0');
    el.classList.add('status99', `word${wid}`);
    el.setAttribute('data_status', '99');
    el.setAttribute('data_wid', String(wid));
    el.title = title;
  });
}

/**
 * Mark a word as ignored (status 98) in the DOM.
 *
 * @param wid Word ID
 * @param hex Hex class identifier for the term
 * @param term Term text
 */
export function markWordIgnoredInDOM(wid: number, hex: string, term: string): void {
  const frameL = getFrameElement('frame-l');
  if (!frameL) return;

  const title = createWordTooltip(term, '*', '', '98');

  frameL.querySelectorAll<HTMLElement>(`[data_hex="${hex}"]`).forEach(el => {
    el.classList.remove('status0');
    el.classList.add('status98', `word${wid}`);
    el.setAttribute('data_status', '98');
    el.setAttribute('data_wid', String(wid));
    el.title = title;
  });
}
