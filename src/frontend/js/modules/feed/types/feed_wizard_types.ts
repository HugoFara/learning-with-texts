/**
 * Feed Wizard Types - Shared TypeScript interfaces for Alpine.js feed wizard.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.0.0
 */

/**
 * A single XPath selection item in the selection list.
 */
export interface SelectionItem {
  /** Unique ID for reactivity (timestamp-based) */
  id: string;
  /** The XPath expression */
  xpath: string;
  /** Whether this item is currently highlighted in the list */
  isHighlighted: boolean;
}

/**
 * An option in the mark_action dropdown.
 */
export interface XPathOption {
  /** XPath expression value */
  value: string;
  /** Display label (e.g., "<p class='article'>") */
  label: string;
  /** Original tag name of the element */
  tagName: string;
}

/**
 * An option in the advanced XPath modal.
 */
export interface AdvancedXPathOption {
  /** Display label */
  label: string;
  /** XPath expression */
  xpath: string;
  /** Type of XPath option */
  type: 'id' | 'class' | 'parent-id' | 'parent-class' | 'all' | 'custom';
}

/**
 * Feed item metadata from parsed RSS/Atom feed.
 */
export interface FeedItem {
  /** Index in the feed array */
  index: number;
  /** Article title */
  title: string;
  /** Article link URL */
  link: string;
  /** Host domain from the link */
  host: string;
  /** Host status indicator ('-', '★', '☆') */
  hostStatus: string;
  /** Whether HTML has been fetched for this item */
  hasHtml: boolean;
}

/**
 * Wizard settings for selection mode and display.
 */
export interface WizardSettings {
  /** Selection mode: 'smart' (0), 'all', 'adv' */
  selectionMode: 'smart' | 'all' | 'adv';
  /** Whether to hide images in the feed content */
  hideImages: boolean;
  /** Whether the wizard controls are minimized */
  isMinimized: boolean;
}

/**
 * Feed options for step 4.
 */
export interface FeedOptions {
  /** Selected language ID */
  languageId: number | null;
  /** Whether to show edit form before saving each text */
  editText: boolean;
  /** Auto-update configuration */
  autoUpdate: {
    enabled: boolean;
    interval: number | null;
    unit: 'h' | 'd' | 'w';
  };
  /** Maximum links to keep */
  maxLinks: {
    enabled: boolean;
    value: number | null;
  };
  /** Maximum texts to keep when archiving */
  maxTexts: {
    enabled: boolean;
    value: number | null;
  };
  /** Custom charset */
  charset: {
    enabled: boolean;
    value: string;
  };
  /** Automatic tag for imported texts */
  tag: {
    enabled: boolean;
    value: string;
  };
}

/**
 * A feed's articles and how its body is carried, as the preview reports them.
 */
export interface FeedPreview {
  /** Feed title */
  title: string;
  /** Feed element carrying the body inline, or '' for the linked page */
  articleSource: string;
  /** Inline sources this feed offers */
  articleSources: string[];
  /** The feed's articles */
  items: Array<{ index: number; title: string; link: string; host: string }>;
}

/**
 * Configuration passed from PHP to Alpine components via JSON script tag.
 */
export interface WizardConfig {
  /** Current wizard step (1-4) */
  step: 1 | 2 | 3 | 4;
  /** RSS/Atom feed URL */
  rssUrl: string;
  /** Feed title */
  feedTitle: string;
  /** Detected feed format (description, encoded, content, or empty for webpage) */
  detectedFeed: string;
  /** Feed text source (description, encoded, content, or empty) */
  feedText: string;
  /** Parsed feed items */
  feedItems: FeedItem[];
  /** Currently selected feed item index */
  selectedFeedIndex: number;
  /** Existing article selectors (from session) */
  articleSelectors: string[];
  /** Existing filter selectors (from session) */
  filterSelectors: string[];
  /** Combined article selector string (for step 3) */
  articleSelector: string;
  /** Wizard settings */
  settings: WizardSettings;
  /** Feed options (step 4) */
  options: FeedOptions;
  /** Feed ID when editing an existing feed */
  editFeedId: number | null;
  /** Available languages (step 4) */
  languages: Array<{ id: number; name: string }>;
  /** Whether feed has multiple hosts */
  multipleHosts: boolean;
  /** Available article sources (description, encoded, content) */
  articleSources: string[];
}

/**
 * Selection UI state within the store.
 */
export interface SelectionState {
  /** Current mode: idle, selecting, or advanced modal open */
  mode: 'idle' | 'selecting' | 'advanced';
  /** Currently marked elements (preview) */
  markedElements: HTMLElement[];
  /** Current dropdown options */
  currentOptions: XPathOption[];
  /** Selected option value from dropdown */
  selectedOption: string | null;
  /** Advanced modal XPath options */
  advancedOptions: AdvancedXPathOption[];
  /** Custom XPath input value */
  customXPath: string;
  /** Whether custom XPath is valid */
  customXPathValid: boolean;
}

/**
 * Feed wizard Alpine store state interface.
 */
export interface FeedWizardStoreState {
  // === Wizard State ===
  /** Current step (1-4) */
  currentStep: 1 | 2 | 3 | 4;
  /** Loading indicator */
  isLoading: boolean;
  /** Whether store has been initialized */
  isInitialized: boolean;

  // === Feed Data (from PHP) ===
  /** RSS/Atom feed URL */
  rssUrl: string;
  /** Feed title */
  feedTitle: string;
  /** Detected feed format */
  detectedFeed: string;
  /** Feed text source */
  feedText: string;
  /** Parsed feed items */
  feedItems: FeedItem[];
  /** Currently selected feed item index */
  selectedFeedIndex: number;
  /** Feed ID when editing existing feed */
  editFeedId: number | null;
  /** Inline article sources this feed offers */
  articleSources: string[];
  /** `redirect:<xpath>` hop selector, for feeds whose links are indirect */
  redirect: string;
  /** Fetched article HTML, keyed by the article's index in the feed */
  articleHtml: Record<number, string>;

  // === XPath Selections ===
  /** Article section selectors (step 2) */
  articleSelectors: SelectionItem[];
  /** Filter selectors (step 3) */
  filterSelectors: SelectionItem[];
  /** Combined article selector (for step 3 filtering) */
  articleSelector: string;

  // === Selection UI State ===
  /** Current XPath value from mark_action dropdown */
  currentXPath: string;
  /** Options for mark_action dropdown */
  markActionOptions: XPathOption[];
  /** Whether advanced modal is open */
  isAdvancedOpen: boolean;
  /** Advanced modal XPath options */
  advancedOptions: AdvancedXPathOption[];
  /** Custom XPath input in advanced modal */
  customXPath: string;
  /** Whether custom XPath is valid */
  customXPathValid: boolean;

  // === Settings ===
  /** Selection mode */
  selectionMode: 'smart' | 'all' | 'adv';
  /** Whether to hide images */
  hideImages: boolean;
  /** Whether controls are minimized */
  isMinimized: boolean;

  // === Step 4 Options ===
  /** Feed configuration options */
  feedOptions: FeedOptions;

  // === Methods ===
  /** Configure store from wizard config */
  configure(config: Partial<WizardConfig>): void;
  /** Add a selector to article or filter list */
  addSelector(xpath: string, type: 'article' | 'filter'): void;
  /** Remove a selector by ID */
  removeSelector(id: string, type: 'article' | 'filter'): void;
  /** Highlight a selector in the list */
  highlightSelector(id: string): void;
  /** Clear all highlighting */
  clearHighlight(): void;
  /** Set current XPath from dropdown */
  setCurrentXPath(xpath: string): void;
  /** Set mark action options */
  setMarkActionOptions(options: XPathOption[]): void;
  /** Open advanced modal with options */
  openAdvanced(options: AdvancedXPathOption[]): void;
  /** Close advanced modal */
  closeAdvanced(): void;
  /** Validate custom XPath */
  validateCustomXPath(xpath: string): boolean;
  /** Build combined XPath string for form submission */
  buildSelectorsString(type: 'article' | 'filter'): string;
  /** Build options string for step 4 form submission */
  buildOptionsString(): string;
  /** Move to another step of the wizard */
  goToStep(step: 1 | 2 | 3 | 4): void;
  /** Take on a feed the preview endpoint just described */
  setFeedPreview(preview: FeedPreview): void;
  /** Remember an article's HTML so re-entering a step does not refetch it */
  setArticleHtml(index: number, html: string): void;
  /** Mark a host with '-', '☆' or '★' */
  setHostMark(host: string, mark: string): void;
  /** Check if wizard can proceed to next step */
  canProceed(): boolean;
  /** Reset store to initial state */
  reset(): void;
}

/**
 * Default feed options configuration.
 */
export const DEFAULT_FEED_OPTIONS: FeedOptions = {
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

/**
 * Generate a unique ID for selection items.
 */
export function generateSelectionId(): string {
  return `sel_${Date.now()}_${Math.random().toString(36).substring(2, 9)}`;
}
