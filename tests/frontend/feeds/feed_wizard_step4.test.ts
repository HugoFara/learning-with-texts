/**
 * Tests for feed_wizard_step4.ts - naming the feed and saving it.
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
import { apiPost, apiPut } from '../../../src/frontend/js/shared/api/client';
import { getFeedWizardStore } from '../../../src/frontend/js/modules/feed/stores/feed_wizard_store';
import {
  feedWizardStep4Data,
  initFeedWizardStep4Alpine
} from '../../../src/frontend/js/modules/feed/components/feed_wizard_step4';

/** Put the store where step 3 would have left it. */
function seedFeed(): void {
  const store = getFeedWizardStore();
  store.reset();
  store.rssUrl = 'https://example.com/feed.xml';
  store.feedTitle = 'Le Monde';
  store.feedText = 'description';
  store.configure({
    articleSelectors: ['//article'],
    filterSelectors: ['//aside', '//footer']
  });
  store.feedOptions = {
    ...store.feedOptions,
    languageId: 3,
    editText: true,
    maxLinks: { enabled: true, value: 20 }
  };
  store.goToStep(4);
}

/** Put the page config on the page. */
function withLanguages(): void {
  document.body.innerHTML =
    '<script type="application/json" id="feed-wizard-config">' +
    JSON.stringify({
      languages: [{ id: 3, name: 'French' }],
      currentLanguageId: 3,
      currentLanguageName: 'French'
    }) +
    '</script>';
}

describe('feed_wizard_step4.ts', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    withLanguages();
    seedFeed();
    Object.defineProperty(window, 'location', { value: { href: '' }, writable: true });
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  describe('seeding from the store', () => {
    it('starts with what the earlier steps picked', () => {
      const component = feedWizardStep4Data();

      expect(component.feedName).toBe('Le Monde');
      expect(component.sourceUri).toBe('https://example.com/feed.xml');
      expect(component.articleSection).toBe('//article');
      expect(component.filterTags).toBe('//aside | //footer');
      expect(component.languageId).toBe('3');
      expect(component.editText).toBe(true);
      expect(component.maxLinks).toBe('20');
    });

    it('puts the redirect hop back at the head of the article section', () => {
      getFeedWizardStore().redirect = 'redirect://a/@href';

      expect(feedWizardStep4Data().articleSection).toBe('redirect://a/@href | //article');
    });

    it('names the language rather than asking for one', () => {
      expect(feedWizardStep4Data().languageName).toBe('French');
    });

    it('names the navbar language for a feed with none of its own', () => {
      getFeedWizardStore().feedOptions = {
        ...getFeedWizardStore().feedOptions,
        languageId: null
      };

      expect(feedWizardStep4Data().languageName).toBe('French');
    });
  });

  describe('buildOptionsString', () => {
    it('writes only the options that are on', () => {
      const component = feedWizardStep4Data();

      expect(component.buildOptionsString())
        .toBe('edit_text=1,max_links=20,article_source=description');
    });

    it('writes an auto-update interval with its unit', () => {
      const component = feedWizardStep4Data();
      component.autoUpdateEnabled = true;
      component.autoUpdateInterval = '12';
      component.autoUpdateUnit = 'd';

      expect(component.buildOptionsString()).toContain('autoupdate=12d');
    });

    it('leaves out an enabled option with no value', () => {
      const component = feedWizardStep4Data();
      component.charsetEnabled = true;
      component.charset = '';

      expect(component.buildOptionsString()).not.toContain('charset');
    });
  });

  describe('handleSubmit', () => {
    const event = { preventDefault: vi.fn() } as unknown as Event;

    it('creates a new feed through the API', async () => {
      vi.mocked(apiPost).mockResolvedValue({
        data: { success: true, feed: { id: 11 } },
        error: null
      } as never);
      const component = feedWizardStep4Data();

      component.handleSubmit(event);
      await vi.waitFor(() => expect(window.location.href).toBe('/feeds/manage'));

      expect(apiPost).toHaveBeenCalledWith('/feeds', {
        langId: 3,
        name: 'Le Monde',
        sourceUri: 'https://example.com/feed.xml',
        articleSectionTags: '//article',
        filterTags: '//aside | //footer',
        options: 'edit_text=1,max_links=20,article_source=description'
      });
    });

    it('updates the feed it reopened', async () => {
      getFeedWizardStore().editFeedId = 11;
      vi.mocked(apiPut).mockResolvedValue({
        data: { success: true, feed: { id: 11 } },
        error: null
      } as never);
      const component = feedWizardStep4Data();

      component.handleSubmit(event);
      await vi.waitFor(() => expect(window.location.href).toBe('/feeds/manage'));

      expect(apiPut).toHaveBeenCalledWith('/feeds/11', expect.objectContaining({ langId: 3 }));
      expect(apiPost).not.toHaveBeenCalled();
    });

    it('shows the API error instead of navigating', async () => {
      vi.mocked(apiPost).mockResolvedValue({
        data: { success: false, error: 'Language is required' },
        error: null
      } as never);
      const component = feedWizardStep4Data();

      component.handleSubmit(event);
      await vi.waitFor(() => expect(component.saving).toBe(false));

      expect(component.hasSaveError()).toBe(true);
      expect(component.saveError).toBe('Language is required');
      expect(window.location.href).toBe('');
    });

    it('refuses a feed with no language chosen', async () => {
      const component = feedWizardStep4Data();
      component.languageId = '';

      component.handleSubmit(event);
      await vi.waitFor(() => expect(component.saving).toBe(false));

      expect(apiPost).not.toHaveBeenCalled();
      expect(component.hasSaveError()).toBe(true);
    });

    it('does nothing while a save is already in flight', () => {
      const component = feedWizardStep4Data();
      component.saving = true;

      component.handleSubmit(event);

      expect(apiPost).not.toHaveBeenCalled();
    });
  });

  describe('navigation', () => {
    it('carries the edited name and language back to step 3', () => {
      const component = feedWizardStep4Data();
      component.feedName = 'Renamed';
      component.languageId = '5';

      component.goBack();

      const store = getFeedWizardStore();
      expect(store.currentStep).toBe(3);
      expect(store.feedTitle).toBe('Renamed');
      expect(store.feedOptions.languageId).toBe(5);
    });

    it('cancels to the feed manager', () => {
      feedWizardStep4Data().cancel();

      expect(window.location.href).toBe('/feeds/manage');
    });
  });

  describe('submitLabel', () => {
    it('offers to save a new feed', () => {
      expect(feedWizardStep4Data().submitLabel()).toBe('Save');
    });

    it('offers to update a reopened one', () => {
      getFeedWizardStore().editFeedId = 11;

      expect(feedWizardStep4Data().submitLabel()).toBe('Update');
    });

    it('says so while saving', () => {
      const component = feedWizardStep4Data();
      component.saving = true;

      expect(component.submitLabel()).toBe('Saving…');
    });
  });

  it('registers itself with Alpine', () => {
    initFeedWizardStep4Alpine();

    expect(Alpine.data).toHaveBeenCalledWith('feedWizardStep4', feedWizardStep4Data);
  });
});
