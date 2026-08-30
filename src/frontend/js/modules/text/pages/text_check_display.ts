/**
 * Text Check Display - render the parse report from `POST /texts/check`.
 *
 * The report used to arrive as HTML echoed mid-request, with the word lists
 * hydrated from two JSON blobs the server printed alongside it. It is one
 * payload now (#262, #266), and it is rendered here.
 *
 * Everything is built as DOM nodes rather than an HTML string: the payload
 * carries raw term text, so a term containing markup has to land in a text
 * node to stay inert.
 *
 * @license unlicense
 * @since   3.0.0
 */

import { t } from '@shared/i18n/translator';
import type {
  TextCheckNonWordEntry,
  TextCheckReport,
  TextCheckWordEntry
} from '../api/texts_api';

/** Paragraph breaks survive parsing as this marker. */
const PARAGRAPH_MARKER = '¶';

/**
 * Build an element, optionally with text and a class.
 */
function el(tag: string, text?: string, className?: string): HTMLElement {
  const node = document.createElement(tag);
  if (text !== undefined) node.textContent = text;
  if (className !== undefined) node.className = className;
  return node;
}

/**
 * Render the preview paragraph, splitting it back into paragraphs.
 *
 * @param preview   Preview text, paragraph breaks marked
 * @param rtlScript Whether the language is right-to-left
 */
function previewSection(preview: string, rtlScript: boolean): HTMLElement {
  const section = document.createElement('div');
  section.appendChild(el('h4', t('text.check.heading_text')));

  const paragraph = el('p', undefined);
  if (rtlScript) paragraph.setAttribute('dir', 'rtl');

  preview.split(PARAGRAPH_MARKER).forEach((part, index) => {
    if (index > 0) {
      paragraph.appendChild(document.createElement('br'));
      paragraph.appendChild(document.createElement('br'));
    }
    paragraph.appendChild(document.createTextNode(part));
  });

  section.appendChild(paragraph);
  return section;
}

/**
 * Render the sentence split.
 */
function sentenceSection(sentences: string[], rtlScript: boolean): HTMLElement {
  const section = document.createElement('div');
  section.appendChild(el('h4', t('text.check.heading_sentences')));

  const list = document.createElement('ol');
  sentences.forEach((sentence) => {
    const item = el('li', sentence);
    if (rtlScript) item.setAttribute('dir', 'rtl');
    list.appendChild(item);
  });

  section.appendChild(list);
  return section;
}

/**
 * Render the parse-coverage warning, when the verdict is one.
 *
 * @param verdict ParseCoverage verdict
 * @returns The banner, or null when the parse looks fine
 */
function warningSection(verdict: string): HTMLElement | null {
  if (verdict !== 'no_words' && verdict !== 'almost_no_words') {
    return null;
  }

  const banner = el('div', undefined, 'notification is-warning is-light');
  banner.appendChild(el('strong', t(`text.parse_warning.${verdict}`)));
  banner.appendChild(document.createTextNode(' ' + t('text.parse_warning.check_language')));
  return banner;
}

/**
 * Render one tallied list plus its total.
 *
 * A term that is already saved as a vocabulary entry carries a translation,
 * and is called out the way the server-rendered report called it out.
 *
 * @param heading   Section heading
 * @param entries   Terms to list
 * @param listClass Class for the <ul>
 * @param note      Optional note appended to the heading
 * @param rtlScript Whether the language is right-to-left
 */
function tallySection(
  heading: string,
  entries: (TextCheckWordEntry | TextCheckNonWordEntry)[],
  listClass: string,
  note: string | null,
  rtlScript: boolean
): HTMLElement {
  const section = document.createElement('div');

  const title = el('h4', heading + ' ');
  if (note !== null) {
    title.appendChild(el('span', note, 'has-text-danger has-text-weight-bold'));
  }
  section.appendChild(title);

  const list = el('ul', undefined, listClass);
  entries.forEach((entry) => {
    const translation = entry.length > 2 ? String(entry[2]) : '';
    const item = document.createElement('li');
    if (rtlScript) item.setAttribute('dir', 'rtl');

    const span = el('span', `[${String(entry[0])}] — ${String(entry[1])}`);
    if (translation !== '') {
      span.textContent += ` — ${translation}`;
      span.className = 'has-text-danger has-text-weight-bold';
    }

    item.appendChild(span);
    list.appendChild(item);
  });
  section.appendChild(list);

  section.appendChild(el('p', `TOTAL: ${entries.length}`));
  return section;
}

/**
 * Render a whole parse report into a container, replacing what it held.
 *
 * @param report    The report from `POST /texts/check`
 * @param container Element to render into
 */
export function renderCheckReport(report: TextCheckReport, container: HTMLElement): void {
  container.textContent = '';

  const warning = warningSection(report.warning);
  if (warning !== null) container.appendChild(warning);

  container.appendChild(previewSection(report.preview, report.rtlScript));
  container.appendChild(sentenceSection(report.sentences, report.rtlScript));
  container.appendChild(
    tallySection(
      t('text.check.heading_words'),
      report.words,
      'wordlist',
      t('text.check.already_saved'),
      report.rtlScript
    )
  );
  container.appendChild(
    tallySection(
      t('text.check.heading_expressions'),
      report.multiWords,
      'expressionlist',
      null,
      report.rtlScript
    )
  );
  container.appendChild(
    tallySection(
      t('text.check.heading_non_words'),
      report.nonWords,
      'nonwordlist',
      null,
      report.rtlScript
    )
  );
}
