/**
 * Feed Wizard Step 4 Component - Edit Options.
 *
 * The last step names the feed, picks its language and sets how it behaves,
 * then saves through `POST /api/v1/feeds` (or `PUT` when reopening a saved
 * feed). What it saves comes from the store rather than from a PHP config
 * blob rebuilt from `$_SESSION` (#262, #266).
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.0.0
 */

import Alpine from 'alpinejs';
import { t } from '@shared/i18n/translator';
import type { FeedWizardStoreState } from '../types/feed_wizard_types';
import { getFeedWizardStore } from '../stores/feed_wizard_store';
import { saveFeed } from '../api/save_feed';
import { buildSectionTags } from '../utils/feed_selectors';
import { readWizardPageConfig } from '../pages/feed_wizard_config';
import { hydrateStepIcons } from '../services/step_icons';

/**
 * Step 4 component data interface.
 */
export interface FeedWizardStep4Data {
  languages: Array<{ id: number; name: string }>;
  currentLanguageName: string;

  // Form data
  languageId: string;
  feedName: string;
  sourceUri: string;
  articleSection: string;
  filterTags: string;

  // Options
  editText: boolean;
  autoUpdateEnabled: boolean;
  autoUpdateInterval: string;
  autoUpdateUnit: string;
  maxLinksEnabled: boolean;
  maxLinks: string;
  maxTextsEnabled: boolean;
  maxTexts: string;
  charsetEnabled: boolean;
  charset: string;
  tagEnabled: boolean;
  tag: string;

  // Save state
  saving: boolean;
  saveError: string;

  // Computed
  readonly store: FeedWizardStoreState;
  readonly isEditMode: boolean;
  readonly languageName: string;

  // Lifecycle
  init(): void;

  // Actions
  submitLabel(): string;
  buildOptionsString(): string;
  goBack(): void;
  hasSaveError(): boolean;
  handleSubmit(event: Event): void;
  cancel(): void;
}

/**
 * Feed wizard step 4 component factory.
 */
export function feedWizardStep4Data(): FeedWizardStep4Data {
  const store = getFeedWizardStore();
  const options = store.feedOptions;
  const config = readWizardPageConfig();

  return {
    languages: config.languages,
    currentLanguageName: config.currentLanguageName,

    // Form data — seeded from the store, which the earlier steps filled in.
    // The three selector fields stay editable: the wizard is a way to build
    // them by pointing, not the only way to write them.
    languageId: options.languageId !== null ? String(options.languageId) : '',
    feedName: store.feedTitle,
    sourceUri: store.rssUrl,
    articleSection: buildSectionTags(
      store.redirect,
      store.articleSelectors.map(s => s.xpath)
    ),
    filterTags: store.buildSelectorsString('filter'),

    // Options
    editText: options.editText,
    autoUpdateEnabled: options.autoUpdate.enabled,
    autoUpdateInterval: options.autoUpdate.interval !== null ? String(options.autoUpdate.interval) : '',
    autoUpdateUnit: options.autoUpdate.unit,
    maxLinksEnabled: options.maxLinks.enabled,
    maxLinks: options.maxLinks.value !== null ? String(options.maxLinks.value) : '',
    maxTextsEnabled: options.maxTexts.enabled,
    maxTexts: options.maxTexts.value !== null ? String(options.maxTexts.value) : '',
    charsetEnabled: options.charset.enabled,
    charset: options.charset.value,
    tagEnabled: options.tag.enabled,
    tag: options.tag.value,

    saving: false,
    saveError: '',

    get store(): FeedWizardStoreState {
      return getFeedWizardStore();
    },

    get isEditMode(): boolean {
      return this.store.editFeedId !== null;
    },

    /**
     * The language this feed will be saved under, for display.
     *
     * Named rather than picked: the navbar chose it, and a reopened feed
     * keeps whichever language it was saved with.
     */
    get languageName(): string {
      const id = Number(this.languageId);
      const match = this.languages.find(lang => lang.id === id);
      return match?.name ?? this.currentLanguageName;
    },

    init(): void {
      hydrateStepIcons();
    },

    submitLabel(): string {
      if (this.saving) {
        return t('feed.wizard.step4.saving');
      }
      return this.isEditMode ? t('feed.wizard.step4.update') : t('feed.wizard.step4.save');
    },

    buildOptionsString(): string {
      const parts: string[] = [];

      if (this.editText) {
        parts.push('edit_text=1');
      }

      if (this.autoUpdateEnabled && this.autoUpdateInterval) {
        parts.push(`autoupdate=${this.autoUpdateInterval}${this.autoUpdateUnit}`);
      }

      if (this.maxLinksEnabled && this.maxLinks) {
        parts.push(`max_links=${this.maxLinks}`);
      }

      if (this.maxTextsEnabled && this.maxTexts) {
        parts.push(`max_texts=${this.maxTexts}`);
      }

      if (this.charsetEnabled && this.charset) {
        parts.push(`charset=${this.charset}`);
      }

      if (this.tagEnabled && this.tag) {
        parts.push(`tag=${this.tag}`);
      }

      // Where the article body is read from, when not the linked page
      if (this.store.feedText) {
        parts.push(`article_source=${this.store.feedText}`);
      }

      return parts.join(',');
    },

    goBack(): void {
      this.store.feedTitle = this.feedName;
      this.store.feedOptions = {
        ...this.store.feedOptions,
        languageId: Number(this.languageId) || null
      };
      this.store.goToStep(3);
    },

    hasSaveError(): boolean {
      return this.saveError !== '';
    },

    /**
     * Save the finished feed through the API.
     *
     * This form used to post itself to /feeds/edit. That route stopped saving
     * anything when the server-rendered feeds list was retired in favour of
     * the manager SPA — it 302s to /feeds/manage — so finishing the wizard
     * silently discarded the feed. Saving through the API both fixes that and
     * puts the submitted language behind the ownership check the controller
     * never had (#262).
     *
     * @param event Submit event
     */
    handleSubmit(event: Event): void {
      event.preventDefault();
      if (this.saving) return;

      this.saveError = '';
      this.saving = true;

      const data = {
        langId: Number(this.languageId) || 0,
        name: this.feedName,
        sourceUri: this.sourceUri,
        articleSectionTags: this.articleSection,
        filterTags: this.filterTags,
        options: this.buildOptionsString()
      };

      void saveFeed(data, this.store.editFeedId).then((result) => {
        if (result.feedId === null) {
          this.saveError = result.error;
          this.saving = false;
          return;
        }
        this.store.reset();
        window.location.href = '/feeds/manage';
      });
    },

    cancel(): void {
      window.location.href = '/feeds/manage';
    }
  };
}

/**
 * Initialize the step 4 Alpine component.
 */
export function initFeedWizardStep4Alpine(): void {
  Alpine.data('feedWizardStep4', feedWizardStep4Data);
}

// Register immediately
initFeedWizardStep4Alpine();

// Expose for global access
declare global {
  interface Window {
    feedWizardStep4Data: typeof feedWizardStep4Data;
  }
}

window.feedWizardStep4Data = feedWizardStep4Data;
