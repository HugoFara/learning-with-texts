/**
 * Tests for feed_wizard_step3.ts - picking what to filter out of the article.
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

const { stores, highlight } = vi.hoisted(() => ({
  stores: {} as Record<string, unknown>,
  highlight: {
    clearAll: vi.fn(),
    clearMarking: vi.fn(),
    clearSelections: vi.fn(),
    clearHighlighting: vi.fn(),
    applySelections: vi.fn(),
    applyArticleSectionFilter: vi.fn(),
    highlightListItem: vi.fn(),
    markElements: vi.fn(),
    toggleImages: vi.fn(),
    updateLastMargin: vi.fn()
  }
}));

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

vi.mock('../../../src/frontend/js/modules/feed/services/highlight_service', () => ({
  getHighlightService: vi.fn(() => highlight),
  initHighlightService: vi.fn()
}));

import Alpine from 'alpinejs';
import { apiPost } from '../../../src/frontend/js/shared/api/client';
import { getFeedWizardStore } from '../../../src/frontend/js/modules/feed/stores/feed_wizard_store';
import {
  feedWizardStep3Data,
  initFeedWizardStep3Alpine
} from '../../../src/frontend/js/modules/feed/components/feed_wizard_step3';

/** Put the store where step 2 would have left it. */
function seedFeed(): void {
  const store = getFeedWizardStore();
  store.reset();
  store.rssUrl = 'https://example.com/feed.xml';
  store.setFeedPreview({
    title: 'Le Monde',
    articleSource: 'description',
    articleSources: ['description'],
    items: [
      { index: 0, title: 'One', link: 'https://a.example/1', host: 'a.example' },
      { index: 1, title: 'Two', link: 'https://a.example/2', host: 'a.example' }
    ]
  });
  store.articleSelector = '//article';
  store.goToStep(3);
}

describe('feed_wizard_step3.ts', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    document.body.innerHTML = '<p id="lwt_last"></p><div id="lwt_article"></div>';
    seedFeed();
    Object.defineProperty(window, 'location', { value: { href: '' }, writable: true });
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('reads the feed out of the store', () => {
    const component = feedWizardStep3Data();

    expect(component.rssUrl).toBe('https://example.com/feed.xml');
    expect(component.feedText).toBe('description');
    expect(component.feedItems).toHaveLength(2);
    expect(component.multipleHosts).toBe(false);
  });

  it('dims everything outside the article section step 2 picked', async () => {
    vi.mocked(apiPost).mockResolvedValue({
      data: { success: true, html: '<p>Bonjour</p>' },
      error: null
    } as never);
    const component = feedWizardStep3Data();

    component.init();
    await vi.waitFor(() => expect(component.loadingArticle).toBe(false));

    expect(highlight.applyArticleSectionFilter).toHaveBeenCalledWith('//article');
  });

  it('reuses the article step 2 already fetched', async () => {
    getFeedWizardStore().setArticleHtml(0, '<p>Cached</p>');
    const component = feedWizardStep3Data();

    component.init();
    await vi.waitFor(() => expect(component.loadingArticle).toBe(false));

    expect(apiPost).not.toHaveBeenCalled();
    expect(document.getElementById('lwt_article')?.innerHTML).toBe('<p>Cached</p>');
  });

  describe('filtering', () => {
    it('adds the current XPath to the filter list', () => {
      const component = feedWizardStep3Data();
      getFeedWizardStore().setCurrentXPath('//aside');

      component.filterSelection();

      expect(getFeedWizardStore().filterSelectors.map(s => s.xpath)).toEqual(['//aside']);
      expect(getFeedWizardStore().articleSelectors).toEqual([]);
    });

    it('ignores a Filter with nothing marked', () => {
      feedWizardStep3Data().filterSelection();

      expect(getFeedWizardStore().filterSelectors).toEqual([]);
    });

    it('removes a filter', () => {
      const store = getFeedWizardStore();
      store.addSelector('//aside', 'filter');

      feedWizardStep3Data().deleteSelector(store.filterSelectors[0].id);

      expect(store.filterSelectors).toEqual([]);
    });
  });

  describe('navigation', () => {
    it('goes back to step 2', () => {
      feedWizardStep3Data().goBack();

      expect(getFeedWizardStore().currentStep).toBe(2);
    });

    it('goes on to step 4 even with nothing filtered', () => {
      feedWizardStep3Data().goNext();

      expect(getFeedWizardStore().currentStep).toBe(4);
    });

    it('cancels to the feed manager', () => {
      feedWizardStep3Data().cancel();

      expect(window.location.href).toBe('/feeds/manage');
    });
  });

  it('registers itself with Alpine', () => {
    initFeedWizardStep3Alpine();

    expect(Alpine.data).toHaveBeenCalledWith('feedWizardStep3', feedWizardStep3Data);
  });
});
