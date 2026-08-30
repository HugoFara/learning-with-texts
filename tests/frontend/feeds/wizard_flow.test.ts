/**
 * Tests for wizard_flow.ts - the two ways into the wizard's picking steps.
 *
 * The real store is used rather than a stub: the flow's whole job is what it
 * leaves in the store, so a stub would assert the test's own bookkeeping.
 */
import { describe, it, expect, beforeEach, vi } from 'vitest';

// A minimal Alpine that really keeps stores, so the wizard store under test
// is the real one rather than a stub of it.
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

import { apiGet, apiPost } from '../../../src/frontend/js/shared/api/client';
import { getFeedWizardStore } from '../../../src/frontend/js/modules/feed/stores/feed_wizard_store';
import { openFeed, resumeFeed, toStoreOptions } from '../../../src/frontend/js/modules/feed/services/wizard_flow';

/** A successful `POST /feeds/wizard/preview` answer. */
function previewOk(overrides: Record<string, unknown> = {}) {
  return {
    data: {
      success: true,
      title: 'Le Monde',
      articleSource: '',
      articleSources: ['description'],
      items: [
        { index: 0, title: 'One', link: 'https://a.example/1', host: 'a.example' },
        { index: 1, title: 'Two', link: 'https://b.example/2', host: 'b.example' }
      ],
      ...overrides
    },
    error: null
  };
}

describe('wizard_flow.ts', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    document.body.innerHTML = '<div id="lwt_article">stale</div>';
    getFeedWizardStore().reset();
  });

  describe('openFeed', () => {
    it('fills the store from the preview and moves to step 2', async () => {
      vi.mocked(apiPost).mockResolvedValue(previewOk() as never);
      const store = getFeedWizardStore();

      const error = await openFeed(store, 'https://example.com/feed.xml');

      expect(error).toBe('');
      expect(store.rssUrl).toBe('https://example.com/feed.xml');
      expect(store.feedTitle).toBe('Le Monde');
      expect(store.feedItems).toHaveLength(2);
      expect(store.articleSources).toEqual(['description']);
      expect(store.currentStep).toBe(2);
    });

    it('asks the preview endpoint for the typed URL', async () => {
      vi.mocked(apiPost).mockResolvedValue(previewOk() as never);

      await openFeed(getFeedWizardStore(), 'https://example.com/feed.xml');

      expect(apiPost).toHaveBeenCalledWith('/feeds/wizard/preview', {
        rss_url: 'https://example.com/feed.xml'
      });
    });

    it('takes the previous article off the page', async () => {
      vi.mocked(apiPost).mockResolvedValue(previewOk() as never);

      await openFeed(getFeedWizardStore(), 'https://example.com/feed.xml');

      expect(document.getElementById('lwt_article')?.innerHTML).toBe('');
    });

    it('reports what the API said and stays on step 1', async () => {
      vi.mocked(apiPost).mockResolvedValue({
        data: { success: false, error: 'Not a feed' },
        error: null
      } as never);
      const store = getFeedWizardStore();

      const error = await openFeed(store, 'https://example.com/nope');

      expect(error).toBe('Not a feed');
      expect(store.currentStep).toBe(1);
    });

    it('reports a transport failure', async () => {
      vi.mocked(apiPost).mockResolvedValue({ data: null, error: 'Network down' } as never);

      const error = await openFeed(getFeedWizardStore(), 'https://example.com/feed.xml');

      expect(error).toBe('Network down');
    });
  });

  describe('resumeFeed', () => {
    const savedFeed = {
      data: {
        id: 7,
        name: 'My Feed',
        sourceUri: 'https://example.com/feed.xml',
        langId: 3,
        articleSectionTags: 'redirect://a/@href | //article',
        filterTags: '//aside!?!//footer',
        options: {},
        optionsString: 'edit_text=1,max_links=20'
      },
      error: null
    };

    it('puts the saved feed back into the store', async () => {
      vi.mocked(apiGet).mockResolvedValue(savedFeed as never);
      vi.mocked(apiPost).mockResolvedValue(previewOk() as never);
      const store = getFeedWizardStore();

      const error = await resumeFeed(store, 7);

      expect(error).toBe('');
      expect(store.editFeedId).toBe(7);
      expect(store.feedTitle).toBe('My Feed');
      expect(store.redirect).toBe('redirect://a/@href');
      expect(store.articleSelectors.map(s => s.xpath)).toEqual(['//article']);
      expect(store.filterSelectors.map(s => s.xpath)).toEqual(['//aside', '//footer']);
      expect(store.feedOptions.languageId).toBe(3);
      expect(store.currentStep).toBe(2);
    });

    it('keeps the feed name rather than the one the RSS advertises', async () => {
      vi.mocked(apiGet).mockResolvedValue(savedFeed as never);
      vi.mocked(apiPost).mockResolvedValue(previewOk({ title: 'Le Monde' }) as never);

      await resumeFeed(getFeedWizardStore(), 7);

      expect(getFeedWizardStore().feedTitle).toBe('My Feed');
    });

    it('prefers the saved article source over what detection picks', async () => {
      vi.mocked(apiGet).mockResolvedValue({
        ...savedFeed,
        data: { ...savedFeed.data, options: { article_source: 'encoded' } }
      } as never);
      vi.mocked(apiPost).mockResolvedValue(previewOk({ articleSource: 'description' }) as never);

      await resumeFeed(getFeedWizardStore(), 7);

      expect(getFeedWizardStore().feedText).toBe('encoded');
    });

    it('reports a feed it could not read', async () => {
      vi.mocked(apiGet).mockResolvedValue({ data: null, error: 'Feed not found' } as never);

      const error = await resumeFeed(getFeedWizardStore(), 7);

      expect(error).toBe('Feed not found');
      expect(apiPost).not.toHaveBeenCalled();
    });

    it('reports a feed whose URL no longer parses', async () => {
      vi.mocked(apiGet).mockResolvedValue(savedFeed as never);
      vi.mocked(apiPost).mockResolvedValue({
        data: { success: false, error: 'Gone' },
        error: null
      } as never);
      const store = getFeedWizardStore();

      const error = await resumeFeed(store, 7);

      expect(error).toBe('Gone');
      expect(store.editFeedId).toBeNull();
    });
  });

  describe('toStoreOptions', () => {
    it('splits an auto-update interval into its number and unit', () => {
      const options = toStoreOptions('autoupdate=12d', 1);

      expect(options.autoUpdate).toEqual({ enabled: true, interval: 12, unit: 'd' });
    });

    it('falls back to hours for an unrecognised unit', () => {
      expect(toStoreOptions('autoupdate=6x', 1).autoUpdate.unit).toBe('h');
    });

    it('reads the numeric limits', () => {
      const options = toStoreOptions('max_links=20,max_texts=5', 1);

      expect(options.maxLinks).toEqual({ enabled: true, value: 20 });
      expect(options.maxTexts).toEqual({ enabled: true, value: 5 });
    });

    it('reads the text options', () => {
      const options = toStoreOptions('charset=UTF-8,tag=news,edit_text=1', 2);

      expect(options.charset).toEqual({ enabled: true, value: 'UTF-8' });
      expect(options.tag).toEqual({ enabled: true, value: 'news' });
      expect(options.editText).toBe(true);
      expect(options.languageId).toBe(2);
    });

    it('leaves everything off for an empty options string', () => {
      const options = toStoreOptions('', null);

      expect(options.editText).toBe(false);
      expect(options.autoUpdate.enabled).toBe(false);
      expect(options.maxLinks.enabled).toBe(false);
      expect(options.languageId).toBeNull();
    });
  });
});
