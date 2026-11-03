/**
 * Tests for the reading-screen book/chapter nav renderer
 * (modules/text/pages/reading/book_nav_renderer.ts), which replaces the
 * server-rendered chapter chrome with a client-built HTML string sourced from
 * GET /texts/{id}/book-context.
 */
import { describe, it, expect } from 'vitest';
import { renderBookNav } from '../../../src/frontend/js/modules/text/pages/reading/book_nav_renderer';
import type { BookContext } from '../../../src/frontend/js/modules/text/api/texts_api';

function ctx(overrides: Partial<BookContext> = {}): BookContext {
  return {
    bookId: 7,
    bookTitle: 'My Book',
    chapterNum: 2,
    chapterTitle: 'The Second',
    totalChapters: 3,
    prevTextId: 41,
    nextTextId: 43,
    chapters: [
      { id: 40, num: 1, title: 'Intro' },
      { id: 42, num: 2, title: 'The Second' },
      { id: 44, num: 3, title: '' },
    ],
    ...overrides,
  };
}

describe('renderBookNav', () => {
  it('returns an empty string for a standalone text (null context)', () => {
    expect(renderBookNav(null)).toBe('');
  });

  it('links to the book and shows the chapter position', () => {
    const html = renderBookNav(ctx());
    expect(html).toContain('href="/book/7"');
    expect(html).toContain('<strong>My Book</strong>');
    expect(html).toContain('Ch. 2/3');
    expect(html).toContain('<em class="ms-1">The Second</em>');
  });

  it('renders prev/next as links when adjacent chapters exist', () => {
    const html = renderBookNav(ctx());
    expect(html).toContain('href="/text/41/read"');
    expect(html).toContain('href="/text/43/read"');
  });

  it('disables the prev control at the first chapter', () => {
    const html = renderBookNav(ctx({ prevTextId: null }));
    expect(html).not.toContain('href="/text/41/read"');
    expect(html).toContain('disabled');
    // Next still navigable.
    expect(html).toContain('href="/text/43/read"');
  });

  it('disables the next control at the last chapter', () => {
    const html = renderBookNav(ctx({ nextTextId: null }));
    expect(html).not.toContain('href="/text/43/read"');
    expect(html).toContain('disabled');
  });

  it('lists every chapter and marks the current one active', () => {
    const html = renderBookNav(ctx());
    expect(html).toContain('href="/text/40/read"');
    expect(html).toContain('href="/text/42/read"');
    expect(html).toContain('href="/text/44/read"');
    // The current chapter (num 2 -> text 42) carries is-active.
    expect(html).toMatch(/href="\/text\/42\/read" class="dropdown-item is-active"/);
  });

  it('falls back to "Chapter N" when a chapter has no title', () => {
    const html = renderBookNav(ctx());
    expect(html).toContain('3. Chapter 3');
  });

  it('omits the chapter-title emphasis when there is none', () => {
    const html = renderBookNav(ctx({ chapterTitle: null }));
    expect(html).not.toContain('<em class="ms-1">');
  });

  it('escapes book and chapter titles to prevent HTML injection', () => {
    const html = renderBookNav(
      ctx({ bookTitle: '<script>x</script>', chapterTitle: '<b>c</b>' })
    );
    expect(html).not.toContain('<script>x</script>');
    expect(html).toContain('&lt;script&gt;');
    expect(html).not.toContain('<b>c</b>');
  });
});
