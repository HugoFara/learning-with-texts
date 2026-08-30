/**
 * Tests for feed_wizard.ts - the page holding the four steps together.
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

import { apiGet, apiPost } from '../../../src/frontend/js/shared/api/client';
import { getFeedWizardStore } from '../../../src/frontend/js/modules/feed/stores/feed_wizard_store';
import { feedWizardData } from '../../../src/frontend/js/modules/feed/pages/feed_wizard';
import { readWizardPageConfig } from '../../../src/frontend/js/modules/feed/pages/feed_wizard_config';

/** Put a page config on the page. */
function withConfig(config: Record<string, unknown>): void {
  document.body.innerHTML =
    `<script type="application/json" id="feed-wizard-config">${JSON.stringify(config)}</script>` +
    '<p id="lwt_last"></p><div id="lwt_article"></div>';
}

describe('feed_wizard.ts', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    document.body.innerHTML = '<p id="lwt_last"></p><div id="lwt_article"></div>';
    getFeedWizardStore().reset();
  });

  describe('readWizardPageConfig', () => {
    it('answers with defaults when the page carries no config', () => {
      const config = readWizardPageConfig();

      expect(config.languages).toEqual([]);
      expect(config.curatedFeeds).toEqual([]);
      expect(config.editFeedId).toBeNull();
    });

    it('reads what PHP put on the page', () => {
      withConfig({
        languages: [{ id: 1, name: 'French' }],
        currentLanguageId: 1,
        currentLanguageName: 'French',
        editFeedId: 9
      });

      const config = readWizardPageConfig();

      expect(config.languages).toEqual([{ id: 1, name: 'French' }]);
      expect(config.currentLanguageId).toBe(1);
      expect(config.editFeedId).toBe(9);
    });

    it('falls back to defaults for a config that will not parse', () => {
      document.body.innerHTML =
        '<script type="application/json" id="feed-wizard-config">{ nope</script>';
      vi.spyOn(console, 'error').mockImplementation(() => undefined);

      expect(readWizardPageConfig().languages).toEqual([]);
    });
  });

  describe('step visibility', () => {
    it('starts on step 1', () => {
      const component = feedWizardData();
      component.init();

      expect(component.isStep1).toBe(true);
      expect(component.isStep2).toBe(false);
      expect(component.isPicking).toBe(false);
    });

    it('follows the store from step to step', () => {
      const component = feedWizardData();
      component.init();

      getFeedWizardStore().goToStep(3);

      expect(component.isStep1).toBe(false);
      expect(component.isStep3).toBe(true);
      expect(component.isPicking).toBe(true);
    });

    it('shows the article on both picking steps and neither other', () => {
      const component = feedWizardData();
      const store = getFeedWizardStore();

      store.goToStep(2);
      expect(component.isPicking).toBe(true);
      store.goToStep(4);
      expect(component.isPicking).toBe(false);
    });
  });

  describe('init', () => {
    it('starts a fresh wizard when no feed is being reopened', () => {
      const store = getFeedWizardStore();
      store.rssUrl = 'https://stale.example/feed.xml';

      const component = feedWizardData();
      component.init();

      expect(store.rssUrl).toBe('');
      expect(apiGet).not.toHaveBeenCalled();
    });

    it('reopens the feed the page names', async () => {
      withConfig({ editFeedId: 7 });
      vi.mocked(apiGet).mockResolvedValue({
        data: {
          id: 7,
          name: 'My Feed',
          sourceUri: 'https://example.com/feed.xml',
          langId: 3,
          articleSectionTags: '//article',
          filterTags: '',
          options: {},
          optionsString: ''
        },
        error: null
      } as never);
      vi.mocked(apiPost).mockResolvedValue({
        data: { success: true, title: 'F', articleSource: '', articleSources: [], items: [] },
        error: null
      } as never);

      const component = feedWizardData();
      component.init();
      await vi.waitFor(() => expect(component.loading).toBe(false));

      expect(apiGet).toHaveBeenCalledWith('/feeds/7');
      expect(component.hasError()).toBe(false);
      expect(getFeedWizardStore().editFeedId).toBe(7);
    });

    it('reports a feed it could not reopen', async () => {
      withConfig({ editFeedId: 7 });
      vi.mocked(apiGet).mockResolvedValue({ data: null, error: 'Feed not found' } as never);

      const component = feedWizardData();
      component.init();
      await vi.waitFor(() => expect(component.loading).toBe(false));

      expect(component.hasError()).toBe(true);
      expect(component.error).toBe('Feed not found');
    });
  });
});
