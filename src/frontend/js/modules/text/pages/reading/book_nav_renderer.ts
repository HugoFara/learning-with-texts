/**
 * Book/chapter navigation renderer for the reading screen.
 *
 * Produces the prev/next + chapter-dropdown chrome that read_desktop.php used to
 * bake in server-side, now built client-side from GET /texts/{id}/book-context
 * so the reader is shell-free (and renders the same way in a bundled/offline
 * client). Returns an HTML string for innerHTML injection — the same pattern the
 * word grid uses — which keeps it CSP-safe (no inline Alpine expressions) and
 * unit-testable. Links are plain anchors: chapter switching is a full page load,
 * exactly as before.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.1.0
 */

import { escapeHtml } from '@shared/utils/html_utils';
import { t } from '@shared/i18n/translator';
import type { BookContext } from '@modules/text/api/texts_api';

/** Build the read URL for a chapter's text id. */
function readHref(textId: number): string {
  return `/text/${textId}/read`;
}

/** A small inline lucide icon placeholder (hydrated by initIcons after inject). */
function icon(name: string): string {
  return `<i data-lucide="${name}" style="width:14px;height:14px"></i>`;
}

/** Previous-chapter control: a link when a previous chapter exists, else a disabled button. */
function prevControl(ctx: BookContext): string {
  const label = escapeHtml(t('text.read.prev'));
  if (ctx.prevTextId !== null) {
    return `<a href="${readHref(ctx.prevTextId)}" class="button is-small" title="${escapeHtml(
      t('text.read.previous_chapter')
    )}"><span class="icon is-small">${icon('chevron-left')}</span><span>${label}</span></a>`;
  }
  return `<button class="button is-small" disabled title="${escapeHtml(
    t('text.read.no_previous_chapter')
  )}"><span class="icon is-small">${icon('chevron-left')}</span><span>${label}</span></button>`;
}

/** Next-chapter control: a link when a next chapter exists, else a disabled button. */
function nextControl(ctx: BookContext): string {
  const label = escapeHtml(t('text.read.next'));
  if (ctx.nextTextId !== null) {
    return `<a href="${readHref(ctx.nextTextId)}" class="button is-small" title="${escapeHtml(
      t('text.read.next_chapter')
    )}"><span>${label}</span><span class="icon is-small">${icon('chevron-right')}</span></a>`;
  }
  return `<button class="button is-small" disabled title="${escapeHtml(
    t('text.read.no_next_chapter')
  )}"><span>${label}</span><span class="icon is-small">${icon('chevron-right')}</span></button>`;
}

/** The hoverable chapter dropdown listing every chapter in the book. */
function chapterDropdown(ctx: BookContext): string {
  const items = ctx.chapters
    .map((ch) => {
      const active = ch.num === ctx.chapterNum ? ' is-active' : '';
      const title = ch.title !== '' ? ch.title : `Chapter ${ch.num}`;
      return `<a href="${readHref(ch.id)}" class="dropdown-item${active}">${ch.num}. ${escapeHtml(
        title
      )}</a>`;
    })
    .join('');

  return (
    '<div class="dropdown is-hoverable">'
    + '<div class="dropdown-trigger">'
    + `<button class="button is-small"><span>Ch. ${ctx.chapterNum}</span>`
    + `<span class="icon is-small">${icon('chevron-down')}</span></button>`
    + '</div>'
    + '<div class="dropdown-menu" style="max-height: 300px; overflow-y: auto;">'
    + `<div class="dropdown-content">${items}</div>`
    + '</div></div>'
  );
}

/**
 * Render the book-context navigation bar.
 *
 * @param ctx - book context from the API, or null for a standalone text
 * @returns HTML string (empty when the text is not part of a book)
 */
export function renderBookNav(ctx: BookContext | null): string {
  if (ctx === null) {
    return '';
  }

  const chapterTitle =
    ctx.chapterTitle !== null && ctx.chapterTitle !== ''
      ? ` <em class="ms-1">${escapeHtml(ctx.chapterTitle)}</em>`
      : '';

  return (
    '<div class="box py-2 px-4 mb-0" style="border-radius: 0; background: #f5f5f5;">'
    + '<div class="level is-mobile"><div class="level-left"><div class="level-item">'
    + `<a href="/book/${ctx.bookId}" class="has-text-grey-dark" title="${escapeHtml(
      t('text.read.view_book')
    )}"><span class="icon is-small me-1">${icon('book-open')}</span>`
    + `<strong>${escapeHtml(ctx.bookTitle)}</strong></a>`
    + `<span class="has-text-grey ms-2">— Ch. ${ctx.chapterNum}/${ctx.totalChapters}${chapterTitle}</span>`
    + '</div></div>'
    + '<div class="level-right"><div class="level-item">'
    + '<div class="buttons has-addons mb-0">'
    + prevControl(ctx)
    + chapterDropdown(ctx)
    + nextControl(ctx)
    + '</div></div></div></div></div>'
  );
}
