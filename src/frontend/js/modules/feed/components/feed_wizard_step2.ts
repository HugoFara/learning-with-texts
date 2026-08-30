/**
 * Feed Wizard Step 2 Component - Select Article Text.
 *
 * The user clicks the article to say which part of it is the text worth
 * reading; each click becomes an XPath the feed stores. The picking itself is
 * unchanged — what changed is where the state comes from and where it goes:
 * the step used to read a PHP config blob rebuilt from `$_SESSION` and to
 * navigate by submitting a form to `/feeds/wizard`. It reads the store and
 * moves the wizard on in place now (#262, #266).
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.0.0
 */

import Alpine from 'alpinejs';
import { t } from '@shared/i18n/translator';
import type { FeedWizardStoreState, FeedItem, XPathOption } from '../types/feed_wizard_types';
import { getFeedWizardStore } from '../stores/feed_wizard_store';
import { getHighlightService, initHighlightService } from '../services/highlight_service';
import { showSelectedArticle, ARTICLE_CONTAINER_ID } from '../services/article_preview';
import {
  xpathQuery,
  generateMarkActionOptions,
  generateAdvancedXPathOptions,
  getAncestorsAndSelf
} from '../utils/xpath_utils';

/**
 * Step 2 component data interface.
 */
export interface FeedWizardStep2Data {
  // UI state
  settingsOpen: boolean;
  loadingArticle: boolean;
  articleError: string;

  // Form data
  feedName: string;
  articleSource: string;
  selectedFeedIndex: number;
  hostStatus: string;

  // Computed
  readonly store: FeedWizardStoreState;
  readonly canProceed: boolean;
  readonly rssUrl: string;
  readonly feedItems: FeedItem[];
  readonly articleSources: string[];
  readonly detectedFeed: string;
  readonly multipleHosts: boolean;
  readonly articleSelectors: Array<{ id: string; xpath: string; isHighlighted: boolean }>;
  readonly markActionOptions: XPathOption[];
  readonly currentXPath: string;
  readonly isMinimized: boolean;
  readonly selectionMode: string;
  readonly hideImages: boolean;

  // Lifecycle
  init(): void;
  destroy(): void;

  // Event handlers
  handleContentClick(event: MouseEvent): void;
  handleMarkActionChange(event: Event): void;

  // Actions
  hasArticleError(): boolean;
  getSelection(): void;
  deleteSelector(id: string): void;
  toggleSelectorHighlight(id: string): void;
  changeSelectMode(): void;
  changeHideImages(): void;
  changeSelectedFeed(): void;
  changeHostStatus(): void;
  changeArticleSection(): void;
  toggleMinimize(): void;
  goBack(): void;
  goNext(): void;
  cancel(): void;

  // Advanced mode
  openAdvancedMode(element: HTMLElement): void;
  selectAdvancedOption(xpath: string): void;
  cancelAdvanced(): void;
  getAdvanced(): void;

  // Internal methods
  showArticle(): void;
  bindContentClickHandler(): void;
  handleSelectedClick(target: HTMLElement): void;
  generateOptionsForElement(element: HTMLElement): void;
  updateHighlighting(): void;
}

/**
 * Feed wizard step 2 component factory.
 */
export function feedWizardStep2Data(): FeedWizardStep2Data {
  const store = getFeedWizardStore();
  const highlightService = getHighlightService();
  let clickHandler: ((event: Event) => void) | null = null;

  return {
    // UI state
    settingsOpen: false,
    loadingArticle: false,
    articleError: '',

    // Form data — seeded from the store, so stepping back into this step
    // finds the fields as they were left.
    feedName: store.feedTitle,
    articleSource: store.feedText,
    selectedFeedIndex: store.selectedFeedIndex,
    hostStatus: '-',

    get store(): FeedWizardStoreState {
      return getFeedWizardStore();
    },

    get canProceed(): boolean {
      return this.store.articleSelectors.length > 0 && this.feedName.trim() !== '';
    },

    get rssUrl(): string {
      return this.store.rssUrl;
    },

    get feedItems(): FeedItem[] {
      return this.store.feedItems;
    },

    get articleSources(): string[] {
      return this.store.articleSources;
    },

    get detectedFeed(): string {
      return this.store.detectedFeed;
    },

    get multipleHosts(): boolean {
      return new Set(this.store.feedItems.map(item => item.host)).size > 1;
    },

    get articleSelectors() {
      return this.store.articleSelectors;
    },

    get markActionOptions(): XPathOption[] {
      return this.store.markActionOptions;
    },

    get currentXPath(): string {
      return this.store.currentXPath;
    },

    get isMinimized(): boolean {
      return this.store.isMinimized;
    },

    get selectionMode(): string {
      return this.store.selectionMode;
    },

    get hideImages(): boolean {
      return this.store.hideImages;
    },

    init(): void {
      initHighlightService();
      this.bindContentClickHandler();
      this.showArticle();
    },

    destroy(): void {
      highlightService.clearAll();

      const host = document.getElementById(ARTICLE_CONTAINER_ID);
      if (host && clickHandler) {
        host.removeEventListener('click', clickHandler);
      }
      clickHandler = null;
    },

    hasArticleError(): boolean {
      return this.articleError !== '';
    },

    /**
     * Put the selected article on the page and highlight what is already picked.
     */
    showArticle(): void {
      if (this.loadingArticle) return;

      this.articleError = '';
      this.loadingArticle = true;

      void showSelectedArticle(this.store).then((error) => {
        this.loadingArticle = false;
        this.articleError = error;
        if (error !== '') return;

        this.updateHighlighting();
        highlightService.toggleImages(this.store.hideImages);
        highlightService.updateLastMargin();
      });
    },

    /**
     * Listen for clicks anywhere in the article.
     *
     * The listener sits on the container rather than on the article's own top
     * level elements, so swapping to another article does not need rebinding.
     */
    bindContentClickHandler(): void {
      const host = document.getElementById(ARTICLE_CONTAINER_ID);
      if (!host) return;

      clickHandler = (event: Event) => {
        this.handleContentClick(event as MouseEvent);
      };
      host.addEventListener('click', clickHandler);
    },

    handleContentClick(event: MouseEvent): void {
      const target = event.target as HTMLElement;
      if (!target) return;

      // Check if clicking on already selected element
      if (target.classList.contains('lwt_selected_text')) {
        this.handleSelectedClick(target);
        return;
      }

      // Check if clicking on filtered element
      if (target.classList.contains('lwt_filtered_text')) {
        event.preventDefault();
        return;
      }

      // Check if clicking on marked (preview) element - clear it
      if (target.classList.contains('lwt_marked_text')) {
        this.store.setCurrentXPath('');
        this.store.setMarkActionOptions([]);
        highlightService.clearMarking();
        return;
      }

      // Generate options for this element
      this.generateOptionsForElement(target);
    },

    handleSelectedClick(target: HTMLElement): void {
      // Find which selector contains this element
      for (const selector of this.store.articleSelectors) {
        const elements = xpathQuery(selector.xpath);
        const containsTarget = elements.some(el =>
          el.contains(target) || target.contains(el) || el === target
        );

        if (containsTarget) {
          if (selector.isHighlighted) {
            this.store.clearHighlight();
          } else {
            this.store.highlightSelector(selector.id);
          }
          this.updateHighlighting();
          break;
        }
      }
    },

    /**
     * Generate mark action options for a clicked element.
     */
    generateOptionsForElement(element: HTMLElement): void {
      // Clear previous
      highlightService.clearMarking();

      // Get ancestors and self for option generation
      const ancestors = getAncestorsAndSelf(element);

      const allOptions: XPathOption[] = [];

      // Generate options for the element and its ancestors
      for (const el of ancestors) {
        if (el.classList.contains('lwt_filtered_text')) continue;

        const options = generateMarkActionOptions(el, this.store.selectionMode);
        allOptions.push(...options);
      }

      if (allOptions.length > 0) {
        this.store.setMarkActionOptions(allOptions);

        // Apply preview highlighting for the first option
        highlightService.markElements(allOptions[0].value);
      }
    },

    handleMarkActionChange(event: Event): void {
      const select = event.target as HTMLSelectElement;
      const xpath = select.value;

      this.store.setCurrentXPath(xpath);

      if (xpath) {
        highlightService.markElements(xpath);
      } else {
        highlightService.clearMarking();
      }
    },

    getSelection(): void {
      const xpath = this.store.currentXPath;
      if (!xpath) return;

      // Check if advanced mode
      if (this.store.selectionMode === 'adv') {
        // Open advanced modal
        const elements = xpathQuery(xpath);
        if (elements.length > 0) {
          this.openAdvancedMode(elements[0]);
        }
        return;
      }

      // Add to selection list
      this.store.addSelector(xpath, 'article');

      // Clear current selection state
      highlightService.clearMarking();

      // Update highlighting
      this.updateHighlighting();

      // Update margin
      highlightService.updateLastMargin();
    },

    deleteSelector(id: string): void {
      this.store.removeSelector(id, 'article');
      this.updateHighlighting();
      highlightService.updateLastMargin();
    },

    toggleSelectorHighlight(id: string): void {
      const selector = this.store.articleSelectors.find(s => s.id === id);
      if (selector?.isHighlighted) {
        this.store.clearHighlight();
      } else {
        this.store.highlightSelector(id);
      }
      this.updateHighlighting();
    },

    /**
     * Update DOM highlighting based on current store state.
     */
    updateHighlighting(): void {
      // Clear all highlighting
      highlightService.clearAll();

      // Apply selections
      const xpaths = this.store.articleSelectors.map(s => s.xpath);
      highlightService.applySelections(xpaths);

      // Apply highlight for focused item
      const highlighted = this.store.articleSelectors.find(s => s.isHighlighted);
      if (highlighted) {
        highlightService.highlightListItem(highlighted.xpath);
      }
    },

    changeSelectMode(): void {
      // Clear any current marking when mode changes
      highlightService.clearMarking();
      this.store.setCurrentXPath('');
      this.store.setMarkActionOptions([]);
    },

    changeHideImages(): void {
      highlightService.toggleImages(this.store.hideImages);
    },

    /**
     * Show another article of the same feed.
     *
     * This used to submit the wizard form so the server could fetch the
     * article and re-render the page around it.
     */
    changeSelectedFeed(): void {
      this.store.selectedFeedIndex = Number(this.selectedFeedIndex) || 0;
      this.showArticle();
    },

    /**
     * Note this host as one to come back to, or not.
     */
    changeHostStatus(): void {
      const item = this.store.feedItems[this.store.selectedFeedIndex];
      if (item) {
        this.store.setHostMark(item.host, this.hostStatus);
      }
    },

    /**
     * Read the article out of the feed entry instead of its linked page.
     *
     * Changing where the body comes from invalidates every article already
     * fetched, so they are dropped rather than shown for the wrong source.
     */
    changeArticleSection(): void {
      this.store.feedText = this.articleSource;
      this.store.detectedFeed = this.articleSource || t('feed.wizard.common.webpage_link');
      this.store.articleHtml = {};
      this.store.feedItems = this.store.feedItems.map(item => ({ ...item, hasHtml: false }));
      this.showArticle();
    },

    toggleMinimize(): void {
      this.store.isMinimized = !this.store.isMinimized;
      highlightService.updateLastMargin();
    },

    goBack(): void {
      this.store.goToStep(1);
    },

    /**
     * Carry the picked article section into step 3.
     */
    goNext(): void {
      if (!this.canProceed) return;

      this.store.feedTitle = this.feedName;
      this.store.articleSelector = this.store.buildSelectorsString('article');
      this.store.goToStep(3);
    },

    cancel(): void {
      window.location.href = '/feeds/manage';
    },

    // Advanced mode methods
    openAdvancedMode(element: HTMLElement): void {
      const options = generateAdvancedXPathOptions(
        element,
        this.store.markActionOptions[0]?.tagName?.toLowerCase()
      );

      this.store.openAdvanced(options);
      highlightService.clearMarking();
      highlightService.updateLastMargin();
    },

    selectAdvancedOption(xpath: string): void {
      this.store.customXPath = xpath;
    },

    cancelAdvanced(): void {
      this.store.closeAdvanced();
      highlightService.updateLastMargin();
    },

    getAdvanced(): void {
      // Get selected option from radio buttons or custom input
      const advEl = document.getElementById('adv');
      const checkedRadio = advEl?.querySelector<HTMLInputElement>('input[type="radio"]:checked');

      let xpath = '';
      if (checkedRadio) {
        xpath = checkedRadio.value;
        // If custom, use the custom input value
        if (!xpath && this.store.customXPathValid) {
          xpath = this.store.customXPath;
        }
      }

      if (xpath) {
        this.store.addSelector(xpath, 'article');
        this.updateHighlighting();
      }

      this.store.closeAdvanced();
      highlightService.updateLastMargin();
    }
  };
}

/**
 * Initialize the step 2 Alpine component.
 */
export function initFeedWizardStep2Alpine(): void {
  Alpine.data('feedWizardStep2', feedWizardStep2Data);
}

// Register immediately
initFeedWizardStep2Alpine();

// Expose for global access
declare global {
  interface Window {
    feedWizardStep2Data: typeof feedWizardStep2Data;
  }
}

window.feedWizardStep2Data = feedWizardStep2Data;
