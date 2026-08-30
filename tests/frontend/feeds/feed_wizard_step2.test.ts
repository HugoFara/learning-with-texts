/**
 * Tests for feed_wizard_step2.ts - picking the article section.
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
  feedWizardStep2Data,
  initFeedWizardStep2Alpine
} from '../../../src/frontend/js/modules/feed/components/feed_wizard_step2';

/** Put a two-article feed in the store, as step 1 would have. */
function seedFeed(): void {
  const store = getFeedWizardStore();
  store.reset();
  store.rssUrl = 'https://example.com/feed.xml';
  store.setFeedPreview({
    title: 'Le Monde',
    articleSource: '',
    articleSources: ['description', 'encoded'],
    items: [
      { index: 0, title: 'One', link: 'https://a.example/1', host: 'a.example' },
      { index: 1, title: 'Two', link: 'https://b.example/2', host: 'b.example' }
    ]
  });
  store.goToStep(2);
}

/** Answer every article fetch with the given HTML. */
function articleResponds(html: string): void {
  vi.mocked(apiPost).mockResolvedValue({
    data: { success: true, html },
    error: null
  } as never);
}

describe('feed_wizard_step2.ts', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    document.body.innerHTML = '<p id="lwt_last"></p><div id="lwt_article"></div>';
    seedFeed();
    Object.defineProperty(window, 'location', { value: { href: '' }, writable: true });
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  describe('seeding from the store', () => {
    it('starts with the feed step 1 read', () => {
      const component = feedWizardStep2Data();

      expect(component.feedName).toBe('Le Monde');
      expect(component.rssUrl).toBe('https://example.com/feed.xml');
      expect(component.feedItems).toHaveLength(2);
      expect(component.articleSources).toEqual(['description', 'encoded']);
    });

    it('notices a feed whose articles come from more than one host', () => {
      expect(feedWizardStep2Data().multipleHosts).toBe(true);
    });

    it('reports a single-host feed as such', () => {
      const store = getFeedWizardStore();
      store.feedItems = store.feedItems.filter(item => item.index === 0);

      expect(feedWizardStep2Data().multipleHosts).toBe(false);
    });
  });

  describe('init', () => {
    it('puts the article on the page', async () => {
      articleResponds('<p>Bonjour</p>');
      const component = feedWizardStep2Data();

      component.init();
      await vi.waitFor(() => expect(component.loadingArticle).toBe(false));

      expect(document.getElementById('lwt_article')?.innerHTML).toBe('<p>Bonjour</p>');
      expect(highlight.toggleImages).toHaveBeenCalledWith(true);
    });

    it('reports an article it could not fetch', async () => {
      vi.mocked(apiPost).mockResolvedValue({
        data: { success: false, error: 'Fetch failed' },
        error: null
      } as never);
      const component = feedWizardStep2Data();

      component.init();
      await vi.waitFor(() => expect(component.loadingArticle).toBe(false));

      expect(component.hasArticleError()).toBe(true);
      expect(component.articleError).toBe('Fetch failed');
    });
  });

  describe('changing what is shown', () => {
    it('fetches the newly selected article', async () => {
      articleResponds('<p>Two</p>');
      const component = feedWizardStep2Data();
      component.selectedFeedIndex = 1;

      component.changeSelectedFeed();
      await vi.waitFor(() => expect(component.loadingArticle).toBe(false));

      expect(getFeedWizardStore().selectedFeedIndex).toBe(1);
      expect(vi.mocked(apiPost).mock.calls[0][1]).toMatchObject({ index: 1 });
    });

    it('drops every cached article when the body source changes', async () => {
      articleResponds('<p>Bonjour</p>');
      const component = feedWizardStep2Data();
      component.init();
      await vi.waitFor(() => expect(component.loadingArticle).toBe(false));

      component.articleSource = 'description';
      component.changeArticleSection();
      await vi.waitFor(() => expect(component.loadingArticle).toBe(false));

      expect(getFeedWizardStore().feedText).toBe('description');
      expect(apiPost).toHaveBeenCalledTimes(2);
      expect(vi.mocked(apiPost).mock.calls[1][1]).toMatchObject({ article_source: 'description' });
    });

    it('marks every article of the same host', () => {
      const component = feedWizardStep2Data();
      component.hostStatus = '★';

      component.changeHostStatus();

      expect(getFeedWizardStore().feedItems[0].hostStatus).toBe('★');
      expect(getFeedWizardStore().feedItems[1].hostStatus).toBe('-');
    });
  });

  describe('navigation', () => {
    it('refuses to go on before anything is picked', () => {
      const component = feedWizardStep2Data();

      expect(component.canProceed).toBe(false);
      component.goNext();

      expect(getFeedWizardStore().currentStep).toBe(2);
    });

    it('refuses to go on without a name', () => {
      getFeedWizardStore().addSelector('//article', 'article');
      const component = feedWizardStep2Data();
      component.feedName = '  ';

      expect(component.canProceed).toBe(false);
    });

    it('carries the picked section into step 3', () => {
      getFeedWizardStore().addSelector('//article', 'article');
      getFeedWizardStore().addSelector('//main', 'article');
      const component = feedWizardStep2Data();
      component.feedName = 'Renamed';

      component.goNext();

      const store = getFeedWizardStore();
      expect(store.currentStep).toBe(3);
      expect(store.feedTitle).toBe('Renamed');
      expect(store.articleSelector).toBe('//article | //main');
    });

    it('goes back to step 1', () => {
      feedWizardStep2Data().goBack();

      expect(getFeedWizardStore().currentStep).toBe(1);
    });

    it('cancels to the feed manager', () => {
      feedWizardStep2Data().cancel();

      expect(window.location.href).toBe('/feeds/manage');
    });
  });

  describe('picking', () => {
    it('adds the current XPath to the article list', () => {
      const component = feedWizardStep2Data();
      getFeedWizardStore().setCurrentXPath('//article');

      component.getSelection();

      expect(getFeedWizardStore().articleSelectors.map(s => s.xpath)).toEqual(['//article']);
    });

    it('ignores a Get with nothing marked', () => {
      feedWizardStep2Data().getSelection();

      expect(getFeedWizardStore().articleSelectors).toEqual([]);
    });

    it('removes a picked selector', () => {
      const store = getFeedWizardStore();
      store.addSelector('//article', 'article');
      const id = store.articleSelectors[0].id;

      feedWizardStep2Data().deleteSelector(id);

      expect(store.articleSelectors).toEqual([]);
    });

    it('marks the element the dropdown names', () => {
      const component = feedWizardStep2Data();
      const select = document.createElement('select');
      const option = document.createElement('option');
      option.value = '//main';
      select.appendChild(option);
      select.value = '//main';

      component.handleMarkActionChange({ target: select } as unknown as Event);

      expect(getFeedWizardStore().currentXPath).toBe('//main');
      expect(highlight.markElements).toHaveBeenCalledWith('//main');
    });
  });

  it('unbinds its article listener when the step is left', async () => {
    articleResponds('<p>Bonjour</p>');
    const component = feedWizardStep2Data();
    component.init();
    await vi.waitFor(() => expect(component.loadingArticle).toBe(false));
    const spy = vi.spyOn(component, 'handleContentClick');

    component.destroy();
    document.getElementById('lwt_article')?.dispatchEvent(new MouseEvent('click'));

    expect(spy).not.toHaveBeenCalled();
    expect(highlight.clearAll).toHaveBeenCalled();
  });

  it('registers itself with Alpine', () => {
    initFeedWizardStep2Alpine();

    expect(Alpine.data).toHaveBeenCalledWith('feedWizardStep2', feedWizardStep2Data);
  });
});
