/**
 * Feed Wizard Step 1 Component - Choose How to Add a Feed.
 *
 * Three ways in: pick from the curated registry, type a feed URL and let the
 * wizard walk the article, or fill every field by hand. The first and third
 * save straight through `POST /api/v1/feeds`; the second used to post to
 * `/feeds/wizard` and now reads the feed through the API and moves the wizard
 * on to step 2 in place (#262, #266).
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.0.0
 */

import Alpine from 'alpinejs';
import { t } from '@shared/i18n/translator';
import type { FeedWizardStoreState } from '../types/feed_wizard_types';
import { getFeedWizardStore } from '../stores/feed_wizard_store';
import { saveFeed } from '../api/save_feed';
import { openFeed } from '../services/wizard_flow';
import { hydrateStepIcons } from '../services/step_icons';
import {
  readWizardPageConfig,
  type CuratedFeedGroup,
  type CuratedSource,
  type FeedWizardPageConfig
} from '../pages/feed_wizard_config';

export type { CuratedFeedGroup, CuratedSource };

/**
 * Step 1 component data interface.
 */
export interface FeedWizardStep1Data {
  config: FeedWizardPageConfig;

  // Tab state
  activeTab: 'browse' | 'wizard' | 'manual';

  // Wizard tab
  rssUrl: string;
  readonly store: FeedWizardStoreState;
  readonly isValidUrl: boolean;

  // Browse tab
  browseLanguageFilter: string;
  browseSearch: string;
  currentLanguageId: number;
  selectedUrls: string[];
  languages: Array<{ id: number; name: string }>;
  curatedFeeds: CuratedFeedGroup[];
  readonly filteredCuratedFeeds: CuratedFeedGroup[];

  // Busy state, shared by both the curated add and the URL read
  saving: boolean;
  saveError: string;

  // Lifecycle
  init(): void;

  // Actions
  cancel(): void;
  hasSaveError(): boolean;
  nextLabel(): string;
  openWizard(): void;
  addSelectedFeeds(): void;
  addCuratedFeed(source: CuratedSource): void;
}

/**
 * Feed wizard step 1 component factory.
 */
export function feedWizardStep1Data(): FeedWizardStep1Data {
  const config = readWizardPageConfig();

  return {
    config,

    // Tab state — default to browse if curated feeds exist, else wizard
    activeTab: config.curatedFeeds.length > 0 ? 'browse' : 'wizard',

    // Wizard tab
    rssUrl: '',

    get store(): FeedWizardStoreState {
      return getFeedWizardStore();
    },

    get isValidUrl(): boolean {
      if (!this.rssUrl) return false;
      try {
        new URL(this.rssUrl);
        return true;
      } catch {
        return false;
      }
    },

    // Browse tab — auto-preselect language filter based on current navbar language
    browseLanguageFilter: (() => {
      const name = config.currentLanguageName.toLowerCase();
      if (!name) return '';
      const match = config.curatedFeeds.find(
        g => name.includes(g.languageName.toLowerCase())
          || g.languageName.toLowerCase().includes(name)
      );
      return match?.language ?? '';
    })(),
    browseSearch: '',
    currentLanguageId: config.currentLanguageId,
    selectedUrls: [] as string[],
    languages: config.languages,
    curatedFeeds: config.curatedFeeds,

    get filteredCuratedFeeds(): CuratedFeedGroup[] {
      let groups = this.curatedFeeds;

      // Filter by language
      if (this.browseLanguageFilter) {
        groups = groups.filter(g => g.language === this.browseLanguageFilter);
      }

      // Filter by search term
      const search = this.browseSearch.toLowerCase().trim();
      if (search) {
        groups = groups
          .map(g => ({
            ...g,
            sources: g.sources.filter(
              s =>
                s.name.toLowerCase().includes(search) ||
                s.category.toLowerCase().includes(search) ||
                s.url.toLowerCase().includes(search)
            )
          }))
          .filter(g => g.sources.length > 0);
      }

      return groups;
    },

    saving: false,
    saveError: '',

    init(): void {
      hydrateStepIcons();
    },

    cancel(): void {
      window.location.href = '/feeds/manage';
    },

    hasSaveError(): boolean {
      return this.saveError !== '';
    },

    nextLabel(): string {
      return this.saving ? t('feed.wizard.reading_feed') : t('feed.wizard.step1.next');
    },

    /**
     * Read the typed feed and hand the wizard over to step 2.
     *
     * The button used to submit a form to `/feeds/wizard`, which parsed the
     * feed into the session and re-rendered the whole page as step 2.
     */
    openWizard(): void {
      if (this.saving || !this.isValidUrl) return;

      this.saveError = '';
      this.saving = true;

      void openFeed(this.store, this.rssUrl).then((error) => {
        this.saving = false;
        this.saveError = error;
      });
    },

    addSelectedFeeds(): void {
      if (this.selectedUrls.length === 0) return;

      // Find the first selected source object by URL
      for (const group of this.curatedFeeds) {
        const source = group.sources.find(s => this.selectedUrls.includes(s.url));
        if (source) {
          this.addCuratedFeed(source);
          return;
        }
      }
    },

    /**
     * Add a curated source as a feed.
     *
     * This used to fill a hidden form and submit it to /feeds/new, which meant
     * waiting a tick for Alpine to write the inputs before posting. Calling the
     * API directly removes both the hidden form and that timing dependency
     * (#262). The language is the navbar's current selection, as before.
     */
    addCuratedFeed(source: CuratedSource): void {
      if (this.saving) return;

      this.saveError = '';
      this.saving = true;

      void saveFeed({
        langId: this.currentLanguageId,
        name: source.name,
        sourceUri: source.url,
        articleSectionTags: source.articleSectionTags,
        filterTags: source.filterTags,
        options: source.options
      }, null).then((result) => {
        if (result.feedId === null) {
          this.saveError = result.error;
          this.saving = false;
          return;
        }
        window.location.href = `/feeds/${result.feedId}/edit`;
      });
    }
  } as FeedWizardStep1Data;
}

/**
 * Initialize the step 1 Alpine component.
 */
export function initFeedWizardStep1Alpine(): void {
  Alpine.data('feedWizardStep1', feedWizardStep1Data);
}

// Register immediately
initFeedWizardStep1Alpine();

// Expose for global access
declare global {
  interface Window {
    feedWizardStep1Data: typeof feedWizardStep1Data;
  }
}

window.feedWizardStep1Data = feedWizardStep1Data;
