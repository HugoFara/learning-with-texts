/**
 * Tests for feed_wizard_step1.ts - the three ways into the feed wizard.
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

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

import Alpine from 'alpinejs';
import { apiPost } from '../../../src/frontend/js/shared/api/client';
import { getFeedWizardStore } from '../../../src/frontend/js/modules/feed/stores/feed_wizard_store';
import {
  feedWizardStep1Data,
  initFeedWizardStep1Alpine
} from '../../../src/frontend/js/modules/feed/components/feed_wizard_step1';

const CURATED = [
  {
    language: 'fr',
    languageName: 'French',
    sources: [
      {
        name: 'Le Monde',
        url: 'https://lemonde.fr/rss',
        articleSectionTags: '//article',
        filterTags: '',
        options: 'edit_text=1',
        category: 'News',
        level: 'B2'
      }
    ]
  },
  {
    language: 'de',
    languageName: 'German',
    sources: [
      {
        name: 'Tagesschau',
        url: 'https://tagesschau.de/rss',
        articleSectionTags: '//main',
        filterTags: '',
        options: '',
        category: 'News',
        level: 'B1'
      }
    ]
  }
];

/** Put a page config on the page. */
function withConfig(config: Record<string, unknown>): void {
  document.body.innerHTML =
    `<script type="application/json" id="feed-wizard-config">${JSON.stringify(config)}</script>` +
    '<p id="lwt_last"></p><div id="lwt_article"></div>';
}

describe('feed_wizard_step1.ts', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    document.body.innerHTML = '<p id="lwt_last"></p><div id="lwt_article"></div>';
    getFeedWizardStore().reset();
    Object.defineProperty(window, 'location', {
      value: { href: '' },
      writable: true
    });
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  describe('tabs', () => {
    it('opens on the curated registry when there is one', () => {
      withConfig({ curatedFeeds: CURATED });

      expect(feedWizardStep1Data().activeTab).toBe('browse');
    });

    it('opens on the URL tab when the registry is empty', () => {
      withConfig({ curatedFeeds: [] });

      expect(feedWizardStep1Data().activeTab).toBe('wizard');
    });

    it('preselects the language filter matching the navbar language', () => {
      withConfig({ curatedFeeds: CURATED, currentLanguageName: 'French' });

      expect(feedWizardStep1Data().browseLanguageFilter).toBe('fr');
    });
  });

  describe('isValidUrl', () => {
    it.each([
      ['', false],
      ['http://example.com/feed', true],
      ['https://example.com/feed.xml', true],
      ['not a url', false],
      ['example.com/feed.xml', false]
    ])('judges %s as %s', (url, expected) => {
      const component = feedWizardStep1Data();
      component.rssUrl = url;

      expect(component.isValidUrl).toBe(expected);
    });
  });

  describe('filteredCuratedFeeds', () => {
    it('keeps only the filtered language', () => {
      withConfig({ curatedFeeds: CURATED });
      const component = feedWizardStep1Data();
      component.browseLanguageFilter = 'de';

      expect(component.filteredCuratedFeeds.map(g => g.language)).toEqual(['de']);
    });

    it('searches names, categories and URLs', () => {
      withConfig({ curatedFeeds: CURATED });
      const component = feedWizardStep1Data();
      component.browseLanguageFilter = '';
      component.browseSearch = 'tagesschau';

      expect(component.filteredCuratedFeeds).toHaveLength(1);
      expect(component.filteredCuratedFeeds[0].sources[0].name).toBe('Tagesschau');
    });

    it('drops groups that no longer match', () => {
      withConfig({ curatedFeeds: CURATED });
      const component = feedWizardStep1Data();
      component.browseLanguageFilter = '';
      component.browseSearch = 'nothing here';

      expect(component.filteredCuratedFeeds).toEqual([]);
    });
  });

  describe('openWizard', () => {
    it('reads the feed and hands over to step 2', async () => {
      withConfig({});
      vi.mocked(apiPost).mockResolvedValue({
        data: {
          success: true,
          title: 'Le Monde',
          articleSource: '',
          articleSources: [],
          items: [{ index: 0, title: 'One', link: 'https://a.example/1', host: 'a.example' }]
        },
        error: null
      } as never);

      const component = feedWizardStep1Data();
      component.rssUrl = 'https://example.com/feed.xml';
      component.openWizard();
      await vi.waitFor(() => expect(component.saving).toBe(false));

      expect(apiPost).toHaveBeenCalledWith('/feeds/wizard/preview', {
        rss_url: 'https://example.com/feed.xml'
      });
      expect(getFeedWizardStore().currentStep).toBe(2);
    });

    it('shows what went wrong and stays put', async () => {
      withConfig({});
      vi.mocked(apiPost).mockResolvedValue({
        data: { success: false, error: 'Not a feed' },
        error: null
      } as never);

      const component = feedWizardStep1Data();
      component.rssUrl = 'https://example.com/nope';
      component.openWizard();
      await vi.waitFor(() => expect(component.saving).toBe(false));

      expect(component.hasSaveError()).toBe(true);
      expect(component.saveError).toBe('Not a feed');
      expect(getFeedWizardStore().currentStep).toBe(1);
    });

    it('does nothing for a URL that will not parse', () => {
      withConfig({});
      const component = feedWizardStep1Data();
      component.rssUrl = 'not a url';

      component.openWizard();

      expect(apiPost).not.toHaveBeenCalled();
    });

    it('does nothing while a read is already in flight', () => {
      withConfig({});
      const component = feedWizardStep1Data();
      component.rssUrl = 'https://example.com/feed.xml';
      component.saving = true;

      component.openWizard();

      expect(apiPost).not.toHaveBeenCalled();
    });
  });

  describe('addCuratedFeed', () => {
    beforeEach(() => {
      withConfig({ curatedFeeds: CURATED, currentLanguageId: 4 });
    });

    it('creates the feed through the API', async () => {
      vi.mocked(apiPost).mockResolvedValue({
        data: { success: true, feed: { id: 42 } },
        error: null
      } as never);

      const component = feedWizardStep1Data();
      component.selectedUrls = ['https://lemonde.fr/rss'];
      component.addSelectedFeeds();
      await vi.waitFor(() => expect(window.location.href).toBe('/feeds/42/edit'));

      expect(apiPost).toHaveBeenCalledWith('/feeds', expect.objectContaining({
        langId: 4,
        name: 'Le Monde',
        sourceUri: 'https://lemonde.fr/rss',
        articleSectionTags: '//article',
        options: 'edit_text=1'
      }));
    });

    it('shows the API error instead of navigating', async () => {
      vi.mocked(apiPost).mockResolvedValue({
        data: { success: false, error: 'Language is required' },
        error: null
      } as never);

      const component = feedWizardStep1Data();
      component.selectedUrls = ['https://lemonde.fr/rss'];
      component.addSelectedFeeds();
      await vi.waitFor(() => expect(component.saving).toBe(false));

      expect(component.saveError).toBe('Language is required');
      expect(window.location.href).toBe('');
    });

    it('does nothing when nothing is selected', () => {
      feedWizardStep1Data().addSelectedFeeds();

      expect(apiPost).not.toHaveBeenCalled();
    });
  });

  it('cancel returns to the feed manager', () => {
    feedWizardStep1Data().cancel();

    expect(window.location.href).toBe('/feeds/manage');
  });

  it('registers itself with Alpine', () => {
    initFeedWizardStep1Alpine();

    expect(Alpine.data).toHaveBeenCalledWith('feedWizardStep1', feedWizardStep1Data);
  });
});
