/**
 * Tests for article_preview.ts - putting the selected article on the page.
 */
import { describe, it, expect, beforeEach, vi } from 'vitest';

const { stores } = vi.hoisted(() => ({ stores: {} as Record<string, unknown> }));

vi.mock('alpinejs', () => ({
  default: {
    data: vi.fn(),
    store: vi.fn((name: string, value?: unknown) => {
      if (value !== undefined) stores[name] = value;
      return stores[name];
    })
  }
}));

vi.mock('../../../src/frontend/js/shared/api/client', () => ({
  apiGet: vi.fn(),
  apiPost: vi.fn(),
  apiPut: vi.fn(),
  apiDelete: vi.fn()
}));

import { apiPost } from '../../../src/frontend/js/shared/api/client';
import { getFeedWizardStore } from '../../../src/frontend/js/modules/feed/stores/feed_wizard_store';
import {
  showSelectedArticle,
  clearArticle
} from '../../../src/frontend/js/modules/feed/services/article_preview';

describe('article_preview.ts', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    document.body.innerHTML = '<p id="lwt_last"></p><div id="lwt_article"></div>';
    const store = getFeedWizardStore();
    store.reset();
    store.rssUrl = 'https://example.com/feed.xml';
    store.feedItems = [
      { index: 0, title: 'One', link: 'https://a.example/1', host: 'a.example', hostStatus: '-', hasHtml: false }
    ];
  });

  it('renders the fetched article', async () => {
    vi.mocked(apiPost).mockResolvedValue({
      data: { success: true, html: '<p>Bonjour</p>' },
      error: null
    } as never);

    const error = await showSelectedArticle(getFeedWizardStore());

    expect(error).toBe('');
    expect(document.getElementById('lwt_article')?.innerHTML).toBe('<p>Bonjour</p>');
  });

  it('names the article by its position in the feed, not its URL', async () => {
    vi.mocked(apiPost).mockResolvedValue({
      data: { success: true, html: '' },
      error: null
    } as never);
    const store = getFeedWizardStore();
    store.feedText = 'description';

    await showSelectedArticle(store);

    expect(apiPost).toHaveBeenCalledWith('/feeds/wizard/article', {
      rss_url: 'https://example.com/feed.xml',
      index: 0,
      article_source: 'description',
      charset: '',
      redirect: ''
    });
  });

  it('passes an overridden charset along', async () => {
    vi.mocked(apiPost).mockResolvedValue({
      data: { success: true, html: '' },
      error: null
    } as never);
    const store = getFeedWizardStore();
    store.feedOptions.charset = { enabled: true, value: 'ISO-8859-1' };

    await showSelectedArticle(store);

    expect(vi.mocked(apiPost).mock.calls[0][1]).toMatchObject({ charset: 'ISO-8859-1' });
  });

  it('remembers an article rather than fetching it twice', async () => {
    vi.mocked(apiPost).mockResolvedValue({
      data: { success: true, html: '<p>Bonjour</p>' },
      error: null
    } as never);
    const store = getFeedWizardStore();

    await showSelectedArticle(store);
    await showSelectedArticle(store);

    expect(apiPost).toHaveBeenCalledTimes(1);
    expect(document.getElementById('lwt_article')?.innerHTML).toBe('<p>Bonjour</p>');
  });

  it('marks the article as fetched so the picker can show it', async () => {
    vi.mocked(apiPost).mockResolvedValue({
      data: { success: true, html: '<p>Bonjour</p>' },
      error: null
    } as never);
    const store = getFeedWizardStore();

    await showSelectedArticle(store);

    expect(store.feedItems[0].hasHtml).toBe(true);
  });

  it('reports a failure and leaves nothing on the page', async () => {
    document.getElementById('lwt_article')!.innerHTML = '<p>old</p>';
    vi.mocked(apiPost).mockResolvedValue({
      data: { success: false, error: 'Could not fetch' },
      error: null
    } as never);

    const error = await showSelectedArticle(getFeedWizardStore());

    expect(error).toBe('Could not fetch');
    expect(document.getElementById('lwt_article')?.innerHTML).toBe('');
  });

  it('clearArticle empties the container', () => {
    document.getElementById('lwt_article')!.innerHTML = '<p>old</p>';

    clearArticle();

    expect(document.getElementById('lwt_article')?.innerHTML).toBe('');
  });
});
