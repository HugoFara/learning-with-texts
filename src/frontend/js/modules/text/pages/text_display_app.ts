/**
 * Text Display App - renders the annotated ("improved") text view.
 *
 * Replaces the server-rendered display_text.php: the page now ships a shell
 * and fetches the annotation from /api/v1/texts/{id}/annotation.
 *
 * The markup produced here is deliberately identical to what PHP emitted —
 * `.anntermruby` for the term and `.anntransruby2` for its translation, inside
 * a <ruby> — because annotation_toggle.ts drives the show/hide buttons by
 * querying those classes at click time.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.6.1
 */

import Alpine from 'alpinejs';
import { readPageConfig } from '@shared/utils/page_config';
import { escapeHtml } from '@shared/utils/html_utils';
import { TextsApi, type AnnotationItem } from '@modules/text/api/texts_api';

/**
 * Paragraph marker in the stored annotation.
 */
const PARAGRAPH_MARKER = '¶';

/**
 * Page configuration emitted by display_alpine.php.
 */
interface TextDisplayConfig {
  textId: number;
}

/**
 * Alpine component state for the annotated display page.
 */
export interface TextDisplayData {
  loading: boolean;
  error: string;
  textId: number;
  init(): Promise<void>;
  isReady(): boolean;
  render(items: AnnotationItem[], textSize: number, rtlScript: boolean): void;
}

/**
 * Build the opening tag of a paragraph at the configured text size.
 *
 * @param textSize Percentage font size from the language settings
 * @param leading  line-height for this paragraph
 *
 * @returns The opening <p> tag
 */
function paragraphTag(textSize: number, leading: string): string {
  return `<p style="font-size:${textSize}%;line-height: ${leading}; margin-bottom: 10px;">`;
}

/**
 * Render one annotated term as ruby markup.
 *
 * @param item Annotation item for a word
 *
 * @returns HTML for the term and its translation
 */
function renderTerm(item: AnnotationItem): string {
  const rom = item.romanization ?? '';
  const romTitle = rom === '' ? '' : ` title="${escapeHtml(rom)}"`;
  return (
    ' <ruby>' +
    '<rb>' +
    `<span class="click anntermruby" style="color:black;"${romTitle}>` +
    escapeHtml(item.text) +
    '</span>' +
    '</rb>' +
    '<rt>' +
    `<span class="click anntransruby2">${escapeHtml(item.translation)}</span>` +
    '</rt>' +
    '</ruby> '
  );
}

/**
 * Render a non-word item: punctuation, whitespace, or a paragraph break.
 *
 * A paragraph marker closes the current <p> and opens the next one, which is
 * how the stored annotation encodes line structure.
 *
 * @param item     Annotation item that is not a word
 * @param textSize Percentage font size from the language settings
 *
 * @returns HTML for the item
 */
function renderNonWord(item: AnnotationItem, textSize: number): string {
  const escaped = ' ' + escapeHtml(item.text);
  return escaped.split(PARAGRAPH_MARKER).join(
    '</p>' + paragraphTag(textSize, '1.3')
  );
}

/**
 * Create the annotated display component.
 *
 * @returns Alpine component data
 */
export function textDisplayData(): TextDisplayData {
  const config = readPageConfig<TextDisplayConfig>('text-display-config', {
    textId: 0
  });

  return {
    loading: true,
    error: '',
    textId: config.textId,

    async init(): Promise<void> {
      if (this.textId === 0) {
        this.loading = false;
        this.error = 'No text selected.';
        return;
      }

      const response = await TextsApi.getAnnotation(this.textId);
      const items = response.data?.items;

      if (!response.data || !items) {
        this.loading = false;
        this.error = response.error || 'Could not load the annotated text.';
        return;
      }

      this.render(
        items,
        response.data.config.textSize,
        response.data.config.rtlScript
      );
      this.loading = false;
    },

    isReady(): boolean {
      return !this.loading && this.error === '';
    },

    render(items: AnnotationItem[], textSize: number, rtlScript: boolean): void {
      const container = document.getElementById('print');
      if (!container) {
        return;
      }

      if (rtlScript) {
        container.setAttribute('dir', 'rtl');
      } else {
        container.removeAttribute('dir');
      }

      const parts = [paragraphTag(textSize, '1.35')];
      for (const item of items) {
        parts.push(
          item.isWord ? renderTerm(item) : renderNonWord(item, textSize)
        );
      }
      parts.push('</p>');

      container.innerHTML = parts.join('');
    }
  };
}

/**
 * Register the component with Alpine.
 */
export function initTextDisplayAlpine(): void {
  Alpine.data('textDisplay', textDisplayData);
}

initTextDisplayAlpine();
