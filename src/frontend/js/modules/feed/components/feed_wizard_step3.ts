/**
 * Feed Wizard Step 3 Component - Filter Text.
 *
 * The mirror of step 2: same picking, but every click names something to drop
 * from the saved text rather than something to keep. Everything outside the
 * article section step 2 picked is dimmed, so only the article itself is
 * clickable.
 *
 * Like step 2, this used to read a PHP config blob rebuilt from `$_SESSION`
 * and navigate by submitting a form to `/feeds/wizard` (#262, #266).
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.0.0
 */

import Alpine from 'alpinejs';
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
 * Step 3 component data interface.
 */
export interface FeedWizardStep3Data {
  // UI state
  settingsOpen: boolean;
  loadingArticle: boolean;
  articleError: string;

  // Form data
  selectedFeedIndex: number;
  hostStatus: string;

  // Computed
  readonly store: FeedWizardStoreState;
  readonly rssUrl: string;
  readonly feedItems: FeedItem[];
  readonly feedText: string;
  readonly multipleHosts: boolean;
  readonly filterSelectors: Array<{ id: string; xpath: string; isHighlighted: boolean }>;
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
  filterSelection(): void;
  deleteSelector(id: string): void;
  toggleSelectorHighlight(id: string): void;
  changeSelectMode(): void;
  changeHideImages(): void;
  changeSelectedFeed(): void;
  changeHostStatus(): void;
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
 * Feed wizard step 3 component factory.
 */
export function feedWizardStep3Data(): FeedWizardStep3Data {
  const store = getFeedWizardStore();
  const highlightService = getHighlightService();
  let clickHandler: ((event: Event) => void) | null = null;

  return {
    // UI state
    settingsOpen: false,
    loadingArticle: false,
    articleError: '',

    // Form data
    selectedFeedIndex: store.selectedFeedIndex,
    hostStatus: '-',

    get store(): FeedWizardStoreState {
      return getFeedWizardStore();
    },

    get rssUrl(): string {
      return this.store.rssUrl;
    },

    get feedItems(): FeedItem[] {
      return this.store.feedItems;
    },

    get feedText(): string {
      return this.store.feedText;
    },

    get multipleHosts(): boolean {
      return new Set(this.store.feedItems.map(item => item.host)).size > 1;
    },

    get filterSelectors() {
      return this.store.filterSelectors;
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
     * Put the selected article on the page, dimmed outside the article section.
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

      // Check if clicking on filtered (dimmed) element from article section
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
      for (const selector of this.store.filterSelectors) {
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

    filterSelection(): void {
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

      // Add to filter list
      this.store.addSelector(xpath, 'filter');

      // Clear current selection state
      highlightService.clearMarking();

      // Update highlighting
      this.updateHighlighting();

      // Update margin
      highlightService.updateLastMargin();
    },

    deleteSelector(id: string): void {
      this.store.removeSelector(id, 'filter');
      this.updateHighlighting();
      highlightService.updateLastMargin();
    },

    toggleSelectorHighlight(id: string): void {
      const selector = this.store.filterSelectors.find(s => s.id === id);
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
      // Clear selection and highlight classes (keep filter classes)
      highlightService.clearSelections();
      highlightService.clearHighlighting();

      // Re-apply article section filtering
      highlightService.applyArticleSectionFilter(this.store.articleSelector);

      // Apply filter selections as selected (so they're visible)
      const xpaths = this.store.filterSelectors.map(s => s.xpath);
      highlightService.applySelections(xpaths);

      // Apply highlight for focused item
      const highlighted = this.store.filterSelectors.find(s => s.isHighlighted);
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

    changeSelectedFeed(): void {
      this.store.selectedFeedIndex = Number(this.selectedFeedIndex) || 0;
      this.showArticle();
    },

    changeHostStatus(): void {
      const item = this.store.feedItems[this.store.selectedFeedIndex];
      if (item) {
        this.store.setHostMark(item.host, this.hostStatus);
      }
    },

    toggleMinimize(): void {
      this.store.isMinimized = !this.store.isMinimized;
      highlightService.updateLastMargin();
    },

    goBack(): void {
      this.store.goToStep(2);
    },

    goNext(): void {
      this.store.goToStep(4);
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
        this.store.addSelector(xpath, 'filter');
        this.updateHighlighting();
      }

      this.store.closeAdvanced();
      highlightService.updateLastMargin();
    }
  };
}

/**
 * Initialize the step 3 Alpine component.
 */
export function initFeedWizardStep3Alpine(): void {
  Alpine.data('feedWizardStep3', feedWizardStep3Data);
}

// Register immediately
initFeedWizardStep3Alpine();

// Expose for global access
declare global {
  interface Window {
    feedWizardStep3Data: typeof feedWizardStep3Data;
  }
}

window.feedWizardStep3Data = feedWizardStep3Data;
