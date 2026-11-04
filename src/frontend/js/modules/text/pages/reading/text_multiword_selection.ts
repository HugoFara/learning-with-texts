/**
 * Multi-word selection for text reading.
 * Handles the creation of multi-word expressions using native text selection.
 *
 * Users can select text normally (click and drag), and if multiple words
 * are selected, the multi-word modal opens automatically.
 *
 * @license Unlicense <http://unlicense.org/>
 */

import { t } from '@shared/i18n/translator';
import { showError } from '@shared/components/confirm_dialog';
import Alpine from 'alpinejs';
import type { MultiWordFormStoreState } from '@modules/vocabulary/stores/multi_word_form_store';
import { getTextId as getTextIdFromConfig } from '@modules/text/stores/text_config';

/**
 * Get text ID from URL (fallback when config is not available).
 */
function getTextIdFromUrl(): number {
  // Try to get from URL path: /text/123/read (RESTful) or /text/read/123 (legacy)
  const restfulMatch = window.location.pathname.match(/\/text\/(\d+)\/read/);
  if (restfulMatch) {
    return parseInt(restfulMatch[1], 10);
  }
  const legacyMatch = window.location.pathname.match(/\/text\/read\/(\d+)/);
  if (legacyMatch) {
    return parseInt(legacyMatch[1], 10);
  }
  // Try to get from query string: ?text=123 or ?tid=123 or ?start=123
  const params = new URLSearchParams(window.location.search);
  const textParam = params.get('text') || params.get('tid') || params.get('start');
  if (textParam) {
    return parseInt(textParam, 10);
  }
  return 0;
}

/**
 * Get the text ID from config or URL.
 */
function getTextId(): number {
  const configId = getTextIdFromConfig();
  if (configId > 0) {
    return configId;
  }
  return getTextIdFromUrl();
}

/**
 * Find all word elements (.wsty) within a selection range.
 * Returns words in document order.
 */
function getSelectedWords(container: HTMLElement): HTMLElement[] {
  const selection = window.getSelection();
  if (!selection || selection.isCollapsed || selection.rangeCount === 0) {
    return [];
  }

  const range = selection.getRangeAt(0);
  const words: HTMLElement[] = [];

  // Get all word elements in the container
  const allWords = container.querySelectorAll<HTMLElement>('.wsty');

  for (const word of allWords) {
    // Check if this word intersects with the selection range
    if (range.intersectsNode(word)) {
      words.push(word);
    }
  }

  return words;
}

/**
 * Get the surface text of a single word span, ignoring any inline annotation
 * children (e.g. <span class="word-ann"> that renders the translation hint).
 *
 * Do NOT read `data_hex` here. It used to be a reversible hex encoding of the
 * surface, which made it a legitimate source; since #237 it is a SHA-256
 * derived identity token, so using it yielded terms literally named
 * "e6967a5826fe441a 75e3090289e2955d".
 *
 * `data_text` carries the surface for multi-word spans, whose own text content
 * is the word count in "show all" mode. Everything else reads its text nodes.
 */
function getWordSurface(el: HTMLElement): string {
  const dataText = el.getAttribute('data_text');
  if (dataText !== null && dataText !== '') return dataText;
  for (const node of Array.from(el.childNodes)) {
    if (node.nodeType === Node.TEXT_NODE) {
      const t = node.textContent;
      if (t && t.trim() !== '') return t;
    }
  }
  return el.textContent || '';
}

/**
 * Get the combined text from selected word elements.
 * Extends partial selections to complete words and includes spaces/punctuation between.
 *
 * @param words The selected word elements (.wsty)
 * @param container The sentence container element
 */
function getSelectedText(words: HTMLElement[], container: HTMLElement): string {
  if (words.length === 0) return '';
  if (words.length === 1) return getWordSurface(words[0]);

  const firstWord = words[0];
  const lastWord = words[words.length - 1];

  // Get positions
  const firstPos = parseInt(firstWord.getAttribute('data_order') || '0', 10);
  const lastPos = parseInt(lastWord.getAttribute('data_order') || '0', 10);
  // For multi-words, the end position includes all component words
  const lastWordCount = parseInt(lastWord.getAttribute('data_code') || '1', 10);

  // Find the sentence container that holds both words
  const sentence = firstWord.closest('[id^="sent_"]') || container;

  // Collect all text from first word to last word, including punctuation
  // Since spaces are not explicitly stored in the DOM, we need to add them
  // between word elements that don't have punctuation between them.
  let text = '';
  const allElements = sentence.querySelectorAll<HTMLElement>('[id^="ID-"]');

  let collecting = false;
  let lastWasWord = false;

  for (const el of allElements) {
    const elId = el.id;
    const match = elId.match(/^ID-(\d+)-(\d+)$/);
    if (!match) continue;

    const elPos = parseInt(match[1], 10);
    const elCount = parseInt(match[2], 10);

    // Start collecting when we reach the first word's position
    if (elPos === firstPos && elCount === 1) {
      collecting = true;
    }

    if (collecting) {
      const isWord = el.classList.contains('wsty');
      const elContent = isWord ? getWordSurface(el as HTMLElement) : (el.textContent || '');

      // Add a space before this word if the previous element was also a word
      // (meaning there's no punctuation/space element between them)
      if (isWord && lastWasWord && elContent) {
        text += ' ';
      }

      text += elContent;
      lastWasWord = isWord && elContent.length > 0;
    }

    // Stop after we've collected the last word
    // For multi-words, we need to account for the word count
    const lastEndPos = lastWordCount > 1 ? lastPos + (lastWordCount * 2 - 2) : lastPos;
    if (elPos >= lastEndPos && el.classList.contains('wsty')) {
      break;
    }
  }

  return text;
}

/**
 * Handle text selection for multi-word creation.
 * Called on mouseup to check if user selected multiple words.
 *
 * @param container The text container element (#thetext)
 */
export function handleTextSelection(container: HTMLElement): void {
  const selectedWords = getSelectedWords(container);

  // Clear selection after processing
  const clearSelection = () => {
    window.getSelection()?.removeAllRanges();
  };

  // Need at least 2 words for multi-word
  if (selectedWords.length < 2) {
    return;
  }

  // Get the complete text from selected words (extends to full words, includes spaces)
  const text = getSelectedText(selectedWords, container);

  if (text.length > 250) {
    void showError(t('common.dialog.selection_too_long'));
    clearSelection();
    return;
  }

  // Get the first word's position
  const firstWord = selectedWords[0];
  const position = parseInt(firstWord.getAttribute('data_order') || '0', 10);

  // Get text ID
  const textId = getTextId();

  // Open multi-word modal via Alpine.js store
  const store = Alpine.store('multiWordForm') as MultiWordFormStoreState;
  if (store && typeof store.loadForEdit === 'function') {
    store.loadForEdit(textId, position, text, selectedWords.length);
  } else {
    // Fallback to page navigation
    const params = new URLSearchParams({
      tid: String(textId),
      ord: String(position),
      txt: text
    });
    window.location.href = '/word/edit?' + params.toString();
  }

  clearSelection();
}

/**
 * Set up multi-word selection on a container.
 * Listens for mouseup and touchend events and checks for text selection.
 *
 * @param container The text container element (#thetext)
 */
export function setupMultiWordSelection(container: HTMLElement): void {
  const handler = () => {
    // Small delay to ensure selection is complete
    setTimeout(() => handleTextSelection(container), 10);
  };

  // Desktop: mouseup fires after click-and-drag selection
  container.addEventListener('mouseup', handler);

  // Mobile: touchend fires after touch selection
  container.addEventListener('touchend', handler);
}

// Legacy exports for backwards compatibility
// These are no longer used but kept to avoid breaking imports
export const mwordDragNDrop = {
  context: undefined as HTMLElement | undefined,
  stopInteraction: () => {}
};

export function multiWordDragDropSelect(): void {
  // No longer used - selection is handled via native text selection
}

export function multiWordTouchSelect(): void {
  // No longer used - selection is handled via native text selection
}
