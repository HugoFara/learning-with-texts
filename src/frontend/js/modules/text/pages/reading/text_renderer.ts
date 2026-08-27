/**
 * Text Renderer - Client-side rendering of text words.
 *
 * Renders word tokens as HTML for the text reading view.
 * Generates spans with appropriate classes and data attributes for Alpine.js bindings.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.0.0
 */

import type { WordData } from '@modules/vocabulary/stores/word_store';
import type { MultiWordRef, ParseWarning } from '@modules/text/api/texts_api';
import { parseInlineMarkdown } from '@shared/utils/inline_markdown';

/**
 * Render settings for text display.
 */
export interface RenderSettings {
  showAll: boolean;
  showTranslations: boolean;
  rightToLeft: boolean;
  textSize: number;
  // Annotation settings
  showLearning?: number;
  displayStatTrans?: number;
  modeTrans?: number;
  annTextSize?: number;
}

/**
 * Escape HTML special characters.
 */
function escapeHtml(text: string): string {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

/**
 * Check if a status should display translations based on displayStatTrans setting.
 *
 * @param status Status value (1-5, 98, 99)
 * @param displayStatTrans Setting value for which statuses show translations
 * @returns Whether translation should be shown for this status
 */
function shouldShowTransForStatus(status: number, displayStatTrans: number): boolean {
  // displayStatTrans is a bitmap: 1=status1, 2=status2, 4=status3, 8=status4, 16=status5, 32=ignored, 64=wellknown
  switch (status) {
    case 1: return (displayStatTrans & 1) !== 0;
    case 2: return (displayStatTrans & 2) !== 0;
    case 3: return (displayStatTrans & 4) !== 0;
    case 4: return (displayStatTrans & 8) !== 0;
    case 5: return (displayStatTrans & 16) !== 0;
    case 98: return (displayStatTrans & 32) !== 0;
    case 99: return (displayStatTrans & 64) !== 0;
    default: return false;
  }
}

/**
 * Build annotation span HTML for a word.
 *
 * @param word Word data
 * @param settings Render settings
 * @returns Annotation span HTML or empty string
 */
function buildAnnotationSpan(word: WordData, settings: RenderSettings): string {
  const { showLearning, displayStatTrans } = settings;

  // Check if annotations should be shown for this word
  if (!showLearning || !displayStatTrans) return '';
  if (!word.translation) return '';
  if (!shouldShowTransForStatus(word.status, displayStatTrans)) return '';

  // Parse Markdown and render annotation
  const annotationHtml = parseInlineMarkdown(word.translation);
  return `<span class="word-ann">${annotationHtml}</span>`;
}

/**
 * Build CSS classes for a word span.
 */
function buildWordClasses(word: WordData, showAll: boolean): string {
  const classes: string[] = [];

  // Hidden class
  if (word.hidden) {
    classes.push('hide');
  }

  // Click handler class
  classes.push('click');

  // Word type class
  if (word.wordCount > 1) {
    classes.push('mword');
    classes.push(showAll ? 'mwsty' : 'wsty');
  } else {
    classes.push('word');
    classes.push('wsty');
  }

  // Order class
  classes.push(`order${word.position}`);

  // Word ID class (if word exists)
  if (word.wordId) {
    classes.push(`word${word.wordId}`);
  }

  // Status class
  classes.push(`status${word.status}`);

  return classes.join(' ');
}

/**
 * Build data attributes for a word span.
 */
function buildWordDataAttributes(word: WordData): Record<string, string> {
  // Use underscore attributes to match PHP backend (TextReadingService)
  // The multi-word selection code and other JS expects these underscore attributes
  const attrs: Record<string, string> = {
    'data_pos': String(word.position),
    'data_order': String(word.position),
    'data_status': String(word.status),
    'data_wid': word.wordId ? String(word.wordId) : '',
    'data_hex': word.hex
  };

  if (word.translation) {
    attrs['data_trans'] = word.translation;
  }

  if (word.romanization) {
    attrs['data_rom'] = word.romanization;
  }

  if (word.wordCount > 1) {
    attrs['data_code'] = String(word.wordCount);
    attrs['data_text'] = word.text;
  }

  return attrs;
}

/**
 * Check if text is pure whitespace (spaces, tabs, etc. but NOT paragraph markers).
 */
function isWhitespace(text: string): boolean {
  // Paragraph markers (¶) are NOT whitespace - they become <br />
  return /^[\s]+$/.test(text) && !text.includes('¶');
}

/**
 * Render a single word as HTML.
 */
export function renderWord(word: WordData, settings: RenderSettings): string {
  const spanId = `ID-${word.position}-${word.wordCount}`;

  if (word.isNotWord) {
    // Punctuation or whitespace
    const hiddenClass = word.hidden ? 'hide' : '';
    // Escape HTML first, then replace ¶ with <br /> to preserve line breaks
    const text = escapeHtml(word.text).replace(/¶/g, '<br />');
    // Add 'punc' class for punctuation (non-whitespace non-words)
    // This allows CSS to control line-breaking behavior
    const puncClass = !isWhitespace(word.text) ? 'punc' : '';
    const classes = [hiddenClass, puncClass].filter(Boolean).join(' ');
    return `<span id="${spanId}" class="${classes}">${text}</span>`;
  }

  // Build classes
  const classes = buildWordClasses(word, settings.showAll);

  // Build data attributes
  const dataAttrs = buildWordDataAttributes(word);
  const dataAttrString = Object.entries(dataAttrs)
    .map(([key, val]) => `${key}="${escapeHtml(val)}"`)
    .join(' ');

  // Text content
  let wordText: string;
  if (settings.showAll && word.wordCount > 1) {
    // In "show all" mode, multiwords display their word count
    wordText = String(word.wordCount);
  } else {
    wordText = escapeHtml(word.text);
  }

  // Build annotation span (with Markdown support)
  const annotationSpan = buildAnnotationSpan(word, settings);

  // Position annotation based on modeTrans (1=after, 2=ruby above, 3=before, 4=ruby below)
  // For modes 3 and 4, annotation comes before text; for 1 and 2, after
  const modeTrans = settings.modeTrans ?? 1;
  const annotationBefore = modeTrans >= 3;

  let content: string;
  if (annotationBefore) {
    content = annotationSpan + wordText;
  } else {
    content = wordText + annotationSpan;
  }

  return `<span id="${spanId}" class="${classes}" ${dataAttrString}>${content}</span>`;
}

/**
 * Check if a word item is trailing punctuation (should stick to preceding word).
 * Trailing punctuation includes: . , ; : ! ? ) ] } » " ' etc.
 */
function isTrailingPunctuation(word: WordData): boolean {
  if (!word.isNotWord) return false;
  const text = word.text.trim();
  if (!text || isWhitespace(word.text)) return false;
  // Check if starts with common trailing punctuation
  const trailingPunc = /^[.,;:!?\])}\u00BB\u201D\u2019\u203A\u300B\u3009\u3011\u3015\u3017\u3019\u301B'"\u2026\u2014\u2013]/;
  return trailingPunc.test(text);
}

/**
 * Check if a word item is leading punctuation (should stick to following word).
 * Leading punctuation includes: ( [ { « " ' etc.
 */
function isLeadingPunctuation(word: WordData): boolean {
  if (!word.isNotWord) return false;
  const text = word.text.trim();
  if (!text || isWhitespace(word.text)) return false;
  // Check if starts with common leading punctuation
  const leadingPunc = /^[([{\u00AB\u201C\u2018\u2039\u300A\u3008\u3010\u3014\u3016\u3018\u301A]/;
  return leadingPunc.test(text);
}

/**
 * Find the best multi-word reference for a word that starts at its position.
 * Returns the longest (highest word count) multi-word expression that starts at this word's position.
 */
function findStartingMultiWord(word: WordData): MultiWordRef | null {
  if (word.isNotWord) return null;

  let bestMw: MultiWordRef | null = null;
  let bestCode = 0;

  // Check mw2 through mw9 for multi-word references
  for (let i = 2; i <= 9; i++) {
    const mwKey = `mw${i}` as const;
    const mwRef = word[mwKey];
    if (mwRef && mwRef.startPos === word.position && i > bestCode) {
      bestMw = mwRef;
      bestCode = i;
    }
  }

  return bestMw;
}

/**
 * Escape HTML special characters for use in attributes.
 */
function escapeAttr(text: string): string {
  return text
    .replace(/&/g, '&amp;')
    .replace(/"/g, '&quot;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');
}

/**
 * Render all words as HTML, grouped by sentences.
 * Words and adjacent punctuation are wrapped together to prevent line breaks.
 * Multi-word expressions are wrapped in mw-group spans with connected underlines.
 */
/**
 * Banner shown above a text that parsed into (almost) nothing.
 *
 * Such a text still displays every character, so without this it reads as an
 * ordinary text that has inexplicably stopped responding to clicks.
 *
 * Built through the DOM rather than by concatenation: escapeHtml() leaves
 * quotes alone, which is fine for text but would let an attribute value break
 * out of the attribute it sits in.
 *
 * @param warning What the server found wrong, or null when the parse was fine
 * @returns HTML for the banner, or an empty string
 */
export function renderParseWarning(warning: ParseWarning | null): string {
  if (!warning) return '';

  const box = document.createElement('div');
  box.className = 'notification is-warning is-light';

  const message = document.createElement('p');
  const headline = document.createElement('strong');
  headline.textContent = warning.headline;
  message.append(headline, ` ${warning.detail}`);

  const linkLine = document.createElement('p');
  const link = document.createElement('a');
  // setAttribute, not .href, so the path stays relative in the markup
  link.setAttribute('href', warning.linkHref);
  link.textContent = warning.linkLabel;
  linkLine.append(link);

  box.append(message, linkLine);
  return box.outerHTML;
}

export function renderText(words: WordData[], settings: RenderSettings): string {
  if (words.length === 0) return '';

  const parts: string[] = [];
  let currentSentenceId = -1;
  let sentenceOpen = false;
  let i = 0;

  // Track active multi-word expression
  let activeMw: MultiWordRef | null = null;
  let mwGroupOpen = false;

  while (i < words.length) {
    const word = words[i];

    // Handle sentence boundaries
    if (word.sentenceId !== currentSentenceId) {
      // Close multi-word group if open (shouldn't span sentences, but handle gracefully)
      if (mwGroupOpen) {
        parts.push('</span>');
        mwGroupOpen = false;
        activeMw = null;
      }
      if (sentenceOpen) {
        parts.push('</span>');
      }
      currentSentenceId = word.sentenceId;
      parts.push(`<span id="sent_${currentSentenceId}">`);
      sentenceOpen = true;
    }

    // Check if this word starts a new multi-word expression
    if (!word.isNotWord && !mwGroupOpen) {
      const startingMw = findStartingMultiWord(word);
      if (startingMw) {
        activeMw = startingMw;
        const mwText = escapeAttr(startingMw.text);
        const mwTrans = escapeAttr(startingMw.translation);
        parts.push(`<span class="mw-group mw-status${startingMw.status}" data-mw-text="${mwText}" data-mw-trans="${mwTrans}" data-mw-status="${startingMw.status}">`);
        mwGroupOpen = true;
      }
    }

    // Check if this is a word (not punctuation/whitespace)
    if (!word.isNotWord) {
      // Collect leading punctuation (already rendered), the word, and trailing punctuation
      const group: string[] = [];

      // Check for leading punctuation that was already added
      // (We handle this by looking ahead from leading punctuation instead)

      // Add the word
      group.push(renderWord(word, settings));
      const renderedWordPosition = word.position;
      i++;

      // Collect trailing punctuation
      while (i < words.length && words[i].sentenceId === currentSentenceId && isTrailingPunctuation(words[i])) {
        group.push(renderWord(words[i], settings));
        i++;
      }

      // Wrap in a non-breaking group if we have trailing punctuation
      if (group.length > 1) {
        parts.push(`<span class="word-group">${group.join('')}</span>`);
      } else {
        parts.push(group[0]);
      }

      // Check if we should close the multi-word group
      if (mwGroupOpen && activeMw && renderedWordPosition >= activeMw.endPos) {
        parts.push('</span>');
        mwGroupOpen = false;
        activeMw = null;
      }
    } else if (isLeadingPunctuation(word)) {
      // Leading punctuation - collect it with the following word
      const group: string[] = [];
      group.push(renderWord(word, settings));
      i++;

      // Get the following word if it exists and is in the same sentence
      let renderedWordPosition = -1;
      if (i < words.length && !words[i].isNotWord && words[i].sentenceId === currentSentenceId) {
        // Check if this word starts a multi-word (before rendering it)
        if (!mwGroupOpen) {
          const startingMw = findStartingMultiWord(words[i]);
          if (startingMw) {
            // Close the current group first, open mw-group, then re-open word-group
            // Actually, we need to handle this more carefully - put the leading punc outside the mw-group
            // For simplicity, just render the leading punctuation outside and start fresh
            parts.push(group[0]); // Add the leading punctuation
            group.length = 0; // Clear the group

            activeMw = startingMw;
            const mwText = escapeAttr(startingMw.text);
            const mwTrans = escapeAttr(startingMw.translation);
            parts.push(`<span class="mw-group mw-status${startingMw.status}" data-mw-text="${mwText}" data-mw-trans="${mwTrans}" data-mw-status="${startingMw.status}">`);
            mwGroupOpen = true;
          }
        }

        group.push(renderWord(words[i], settings));
        renderedWordPosition = words[i].position;
        i++;

        // Also collect any trailing punctuation after the word
        while (i < words.length && words[i].sentenceId === currentSentenceId && isTrailingPunctuation(words[i])) {
          group.push(renderWord(words[i], settings));
          i++;
        }
      }

      // Wrap in a non-breaking group
      if (group.length > 1) {
        parts.push(`<span class="word-group">${group.join('')}</span>`);
      } else if (group.length === 1) {
        parts.push(group[0]);
      }

      // Check if we should close the multi-word group
      if (mwGroupOpen && activeMw && renderedWordPosition >= activeMw.endPos) {
        parts.push('</span>');
        mwGroupOpen = false;
        activeMw = null;
      }
    } else {
      // Regular non-word (whitespace or other punctuation)
      parts.push(renderWord(word, settings));
      i++;
    }
  }

  // Close any open multi-word group
  if (mwGroupOpen) {
    parts.push('</span>');
  }

  // Close last sentence
  if (sentenceOpen) {
    parts.push('</span>');
  }

  return parts.join('');
}

/**
 * Update the status class on all word elements with a given hex.
 */
export function updateWordStatusInDOM(
  hex: string,
  newStatus: number,
  newWordId: number | null = null,
  container: Element = document.body
): void {
  const selector = `[data_hex="${hex}"]`;
  const elements = container.querySelectorAll<HTMLElement>(selector);

  elements.forEach(el => {
    // Update status class
    el.className = el.className.replace(/status\d+/g, `status${newStatus}`);

    // Update data attribute (use underscore to match PHP backend)
    el.setAttribute('data_status', String(newStatus));

    // Update word ID if provided
    if (newWordId !== null) {
      // Add/update word ID class
      el.className = el.className.replace(/word\d+/g, '');
      if (newWordId > 0) {
        el.classList.add(`word${newWordId}`);
        el.setAttribute('data_wid', String(newWordId));
      } else {
        el.removeAttribute('data_wid');
      }
    }
  });
}

/**
 * Update translation/romanization on word elements.
 */
export function updateWordTranslationInDOM(
  hex: string,
  translation: string,
  romanization: string,
  container: Element = document.body
): void {
  const selector = `[data_hex="${hex}"]`;
  const elements = container.querySelectorAll<HTMLElement>(selector);

  elements.forEach(el => {
    if (translation) {
      el.setAttribute('data_trans', translation);
    } else {
      el.removeAttribute('data_trans');
    }

    if (romanization) {
      el.setAttribute('data_rom', romanization);
    } else {
      el.removeAttribute('data_rom');
    }

    // Update annotation span content (with Markdown support)
    const annSpan = el.querySelector('.word-ann');
    if (annSpan) {
      if (translation) {
        annSpan.innerHTML = parseInlineMarkdown(translation);
      } else {
        annSpan.remove();
      }
    }
  });
}

/**
 * Calculate total character count (for annotation display).
 */
export function calculateCharCount(words: WordData[]): number {
  let count = 0;
  for (const word of words) {
    if (!word.isNotWord && word.wordCount === 1) {
      count += word.text.length;
    }
  }
  return count;
}
