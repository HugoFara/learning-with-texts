/**
 * Feed Wizard Store - Alpine.js store for feed wizard state management.
 *
 * Provides centralized state management for all wizard steps.
 * Manages XPath selections, settings, and feed options.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.0.0
 */

import Alpine from 'alpinejs';
import type {
  FeedWizardStoreState,
  WizardConfig,
  FeedPreview,
  SelectionItem,
  XPathOption,
  AdvancedXPathOption,
  FeedOptions
} from '../types/feed_wizard_types';
import { isValidXPath, xpathQuery } from '../utils/xpath_utils';

/**
 * Create the initial feed options state.
 */
function createDefaultFeedOptions(): FeedOptions {
  return {
    languageId: null,
    editText: false,
    autoUpdate: {
      enabled: false,
      interval: null,
      unit: 'h'
    },
    maxLinks: {
      enabled: false,
      value: null
    },
    maxTexts: {
      enabled: false,
      value: null
    },
    charset: {
      enabled: false,
      value: ''
    },
    tag: {
      enabled: false,
      value: ''
    }
  };
}

/**
 * Generate a unique ID for selection items.
 */
function generateId(): string {
  return `sel_${Date.now()}_${Math.random().toString(36).substring(2, 9)}`;
}

/**
 * Create the feed wizard store data object.
 */
function createFeedWizardStore(): FeedWizardStoreState {
  return {
    // === Wizard State ===
    currentStep: 1,
    isLoading: false,
    isInitialized: false,

    // === Feed Data ===
    rssUrl: '',
    feedTitle: '',
    detectedFeed: '',
    feedText: '',
    feedItems: [],
    selectedFeedIndex: 0,
    editFeedId: null,
    articleSources: [],
    redirect: '',
    articleHtml: {},

    // === XPath Selections ===
    articleSelectors: [],
    filterSelectors: [],
    articleSelector: '',

    // === Selection UI State ===
    currentXPath: '',
    markActionOptions: [],
    isAdvancedOpen: false,
    advancedOptions: [],
    customXPath: '',
    customXPathValid: false,

    // === Settings ===
    selectionMode: 'smart',
    hideImages: true,
    isMinimized: false,

    // === Step 4 Options ===
    feedOptions: createDefaultFeedOptions(),

    /**
     * Configure the store from wizard configuration.
     */
    configure(config: Partial<WizardConfig>): void {
      if (config.step !== undefined) this.currentStep = config.step;
      if (config.rssUrl !== undefined) this.rssUrl = config.rssUrl;
      if (config.feedTitle !== undefined) this.feedTitle = config.feedTitle;
      if (config.detectedFeed !== undefined) this.detectedFeed = config.detectedFeed;
      if (config.feedText !== undefined) this.feedText = config.feedText;
      if (config.feedItems !== undefined) this.feedItems = config.feedItems;
      if (config.selectedFeedIndex !== undefined) this.selectedFeedIndex = config.selectedFeedIndex;
      if (config.editFeedId !== undefined) this.editFeedId = config.editFeedId;
      if (config.articleSelector !== undefined) this.articleSelector = config.articleSelector;
      if (config.articleSources !== undefined) this.articleSources = config.articleSources;

      // Settings
      if (config.settings) {
        if (config.settings.selectionMode !== undefined) {
          this.selectionMode = config.settings.selectionMode;
        }
        if (config.settings.hideImages !== undefined) {
          this.hideImages = config.settings.hideImages;
        }
        if (config.settings.isMinimized !== undefined) {
          this.isMinimized = config.settings.isMinimized;
        }
      }

      // Feed options
      if (config.options) {
        this.feedOptions = { ...this.feedOptions, ...config.options };
      }

      // Parse existing selectors
      if (config.articleSelectors && config.articleSelectors.length > 0) {
        this.articleSelectors = config.articleSelectors.map(xpath => ({
          id: generateId(),
          xpath,
          isHighlighted: false
        }));
      }

      if (config.filterSelectors && config.filterSelectors.length > 0) {
        this.filterSelectors = config.filterSelectors.map(xpath => ({
          id: generateId(),
          xpath,
          isHighlighted: false
        }));
      }

      this.isInitialized = true;
    },

    /**
     * Add a selector to the article or filter list.
     */
    addSelector(xpath: string, type: 'article' | 'filter'): void {
      if (!xpath.trim()) return;

      const newItem: SelectionItem = {
        id: generateId(),
        xpath: xpath.trim(),
        isHighlighted: false
      };

      if (type === 'article') {
        this.articleSelectors = [...this.articleSelectors, newItem];
      } else {
        this.filterSelectors = [...this.filterSelectors, newItem];
      }

      // Clear current selection state
      this.currentXPath = '';
      this.markActionOptions = [];
    },

    /**
     * Remove a selector by ID.
     */
    removeSelector(id: string, type: 'article' | 'filter'): void {
      if (type === 'article') {
        this.articleSelectors = this.articleSelectors.filter(s => s.id !== id);
      } else {
        this.filterSelectors = this.filterSelectors.filter(s => s.id !== id);
      }
    },

    /**
     * Highlight a specific selector in the list.
     */
    highlightSelector(id: string): void {
      // Clear all highlights first
      this.articleSelectors = this.articleSelectors.map(s => ({
        ...s,
        isHighlighted: s.id === id
      }));
      this.filterSelectors = this.filterSelectors.map(s => ({
        ...s,
        isHighlighted: s.id === id
      }));
    },

    /**
     * Clear all highlighting.
     */
    clearHighlight(): void {
      this.articleSelectors = this.articleSelectors.map(s => ({
        ...s,
        isHighlighted: false
      }));
      this.filterSelectors = this.filterSelectors.map(s => ({
        ...s,
        isHighlighted: false
      }));
    },

    /**
     * Set the current XPath from the dropdown.
     */
    setCurrentXPath(xpath: string): void {
      this.currentXPath = xpath;
    },

    /**
     * Set the mark action options.
     */
    setMarkActionOptions(options: XPathOption[]): void {
      this.markActionOptions = options;
      if (options.length > 0) {
        this.currentXPath = options[0].value;
      }
    },

    /**
     * Open the advanced modal with options.
     */
    openAdvanced(options: AdvancedXPathOption[]): void {
      this.advancedOptions = options;
      this.customXPath = '';
      this.customXPathValid = false;
      this.isAdvancedOpen = true;
    },

    /**
     * Close the advanced modal.
     */
    closeAdvanced(): void {
      this.isAdvancedOpen = false;
      this.advancedOptions = [];
      this.customXPath = '';
      this.customXPathValid = false;
    },

    /**
     * Validate a custom XPath expression.
     */
    validateCustomXPath(xpath: string): boolean {
      if (!xpath.trim()) {
        this.customXPathValid = false;
        return false;
      }

      const valid = isValidXPath(xpath) && xpathQuery(xpath).length > 0;
      this.customXPathValid = valid;
      return valid;
    },

    /**
     * Build combined XPath string for form submission.
     */
    buildSelectorsString(type: 'article' | 'filter'): string {
      const selectors = type === 'article' ? this.articleSelectors : this.filterSelectors;
      return selectors.map(s => s.xpath).join(' | ');
    },

    /**
     * Build the options string for step 4 form submission.
     */
    buildOptionsString(): string {
      const parts: string[] = [];

      if (this.feedOptions.editText) {
        parts.push('edit_text=1');
      }

      if (this.feedOptions.autoUpdate.enabled && this.feedOptions.autoUpdate.interval) {
        parts.push(`autoupdate=${this.feedOptions.autoUpdate.interval}${this.feedOptions.autoUpdate.unit}`);
      }

      if (this.feedOptions.maxLinks.enabled && this.feedOptions.maxLinks.value !== null) {
        parts.push(`max_links=${this.feedOptions.maxLinks.value}`);
      }

      if (this.feedOptions.maxTexts.enabled && this.feedOptions.maxTexts.value !== null) {
        parts.push(`max_texts=${this.feedOptions.maxTexts.value}`);
      }

      if (this.feedOptions.charset.enabled && this.feedOptions.charset.value) {
        parts.push(`charset=${this.feedOptions.charset.value}`);
      }

      if (this.feedOptions.tag.enabled && this.feedOptions.tag.value) {
        parts.push(`tag=${this.feedOptions.tag.value}`);
      }

      // Add article source if set
      if (this.feedText) {
        parts.push(`article_source=${this.feedText}`);
      }

      return parts.join(',');
    },

    /**
     * Move to another step of the wizard.
     *
     * The steps were four page loads until #262; they are four panels of one
     * page now, so moving between them is a state change rather than a POST.
     */
    goToStep(step: 1 | 2 | 3 | 4): void {
      this.currentStep = step;
    },

    /**
     * Take on a feed the preview endpoint just described.
     *
     * Anything derived from the previous feed goes: the selectors point at
     * elements of an article that is no longer on screen, and the cached
     * article HTML belongs to the old feed's indices.
     */
    setFeedPreview(preview: FeedPreview): void {
      this.feedTitle = preview.title;
      this.feedText = preview.articleSource;
      this.detectedFeed = preview.articleSource || 'Webpage Link';
      this.articleSources = preview.articleSources;
      this.feedItems = preview.items.map(item => ({
        ...item,
        hostStatus: '-',
        hasHtml: false
      }));
      this.selectedFeedIndex = 0;
      this.articleHtml = {};
      this.articleSelectors = [];
      this.filterSelectors = [];
      this.articleSelector = '';
      this.currentXPath = '';
      this.markActionOptions = [];
    },

    /**
     * Remember an article's HTML so re-entering a step does not refetch it.
     */
    setArticleHtml(index: number, html: string): void {
      this.articleHtml = { ...this.articleHtml, [index]: html };
      this.feedItems = this.feedItems.map(item =>
        item.index === index ? { ...item, hasHtml: true } : item
      );
    },

    /**
     * Mark a host with '-', '☆' or '★'.
     *
     * The mark is the user's own note about which sources are worth reading;
     * it is never saved, so it lives no longer than the wizard does.
     */
    setHostMark(host: string, mark: string): void {
      if (!host) return;
      this.feedItems = this.feedItems.map(item =>
        item.host === host ? { ...item, hostStatus: mark } : item
      );
    },

    /**
     * Check if wizard can proceed to next step.
     */
    canProceed(): boolean {
      switch (this.currentStep) {
        case 1:
          return !!this.rssUrl.trim();
        case 2:
          return this.articleSelectors.length > 0;
        case 3:
          // Filter is optional, can always proceed
          return true;
        case 4:
          return !!this.feedOptions.languageId && !!this.feedTitle.trim();
        default:
          return false;
      }
    },

    /**
     * Reset the store to initial state.
     */
    reset(): void {
      this.currentStep = 1;
      this.isLoading = false;
      this.isInitialized = false;
      this.rssUrl = '';
      this.feedTitle = '';
      this.detectedFeed = '';
      this.feedText = '';
      this.feedItems = [];
      this.selectedFeedIndex = 0;
      this.editFeedId = null;
      this.articleSources = [];
      this.redirect = '';
      this.articleHtml = {};
      this.articleSelectors = [];
      this.filterSelectors = [];
      this.articleSelector = '';
      this.currentXPath = '';
      this.markActionOptions = [];
      this.isAdvancedOpen = false;
      this.advancedOptions = [];
      this.customXPath = '';
      this.customXPathValid = false;
      this.selectionMode = 'smart';
      this.hideImages = true;
      this.isMinimized = false;
      this.feedOptions = createDefaultFeedOptions();
    }
  };
}

/**
 * Initialize the feed wizard store as an Alpine.js store.
 */
export function initFeedWizardStore(): void {
  Alpine.store('feedWizard', createFeedWizardStore());
}

/**
 * Get the feed wizard store instance.
 */
export function getFeedWizardStore(): FeedWizardStoreState {
  return Alpine.store('feedWizard') as FeedWizardStoreState;
}

// Register the store immediately when this module is imported
initFeedWizardStore();

// Expose for global access (debugging)
declare global {
  interface Window {
    getFeedWizardStore: typeof getFeedWizardStore;
  }
}

window.getFeedWizardStore = getFeedWizardStore;
