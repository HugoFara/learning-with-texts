/**
 * Word Store - Alpine.js store for text reading word state management.
 *
 * Provides centralized state management for all words in the text reading view.
 * Uses Map for O(1) lookups and supports reactive updates across all word instances.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.0.0
 */

import Alpine from 'alpinejs';
import { TermsApi } from '@modules/vocabulary/api/terms_api';
import { TextsApi, type TextWord, type TextReadingConfig, type DictLinks, type MultiWordRef, type ParseWarning } from '@modules/text/api/texts_api';
import { injectTextStyles, generateParagraphStyles } from '@modules/text/pages/reading/text_styles';
import { renderText, updateWordStatusInDOM, updateWordTranslationInDOM, type RenderSettings } from '@modules/text/pages/reading/text_renderer';

/**
 * Word data stored in the word store.
 */
export interface WordData {
  position: number;
  sentenceId: number;
  text: string;
  textLc: string;
  hex: string;
  isNotWord: boolean;
  wordCount: number;
  hidden: boolean;
  wordId: number | null;
  status: number;
  translation: string;
  romanization: string;
  notes?: string;
  tags?: string;
  // Multiword references (mw2, mw3, etc.) - full expression details
  [key: `mw${number}`]: MultiWordRef | undefined;
}

/**
 * Word store state interface.
 */
export interface WordStoreState {
  // Word data - keyed by hex for O(1) lookup
  // Multiple words can share the same hex (same word appearing multiple times)
  wordsByHex: Map<string, WordData[]>;

  // All words in order (for rendering)
  words: WordData[];

  // Configuration
  textId: number;
  langId: number;
  title: string;
  audioUri: string | null;
  sourceUri: string | null;
  audioPosition: number;
  rightToLeft: boolean;
  textSize: number;
  removeSpaces: boolean;
  parseWarning: ParseWarning | null;
  dictLinks: DictLinks;

  // Annotation/display settings
  showLearning: number;
  displayStatTrans: number;
  modeTrans: number;
  termDelimiter: string;
  annTextSize: number;

  // Reader layout
  readerWidth: number;

  // Generated paragraph styles
  paragraphStyles: string;

  // UI state
  selectedHex: string | null;
  selectedPosition: number | null;
  popoverTargetElement: HTMLElement | null;
  isPopoverOpen: boolean;
  isEditModalOpen: boolean;
  isLoading: boolean;
  isInitialized: boolean;

  // Display settings
  showAll: boolean;
  showTranslations: boolean;

  // Rendered HTML (computed from words)
  renderedHtml: string;

  // Methods
  getRenderedHtml(): string;
  setTextHtml(el: HTMLElement): void;
  loadText(textId: number): Promise<void>;
  initFromData(words: TextWord[], config: TextReadingConfig): void;
  selectWord(hex: string, position: number, targetElement?: HTMLElement): void;
  closePopover(): void;
  openEditModal(): void;
  closeEditModal(): void;
  getSelectedWord(): WordData | null;
  getWordsByHex(hex: string): WordData[];
  setStatus(hex: string, status: number): Promise<boolean>;
  createQuickWord(hex: string, position: number, status: 98 | 99): Promise<boolean>;
  deleteWord(hex: string): Promise<boolean>;
  getDictUrl(which: 'dict1' | 'dict2' | 'translator'): string;
  hasDictUrl(which: 'dict1' | 'dict2' | 'translator'): boolean;
  updateWordInStore(hex: string, updates: Partial<WordData>): void;
}

/**
 * Create the word store data object.
 */
function createWordStore(): WordStoreState {
  return {
    // Word data
    wordsByHex: new Map(),
    words: [],

    // Configuration
    textId: 0,
    langId: 0,
    title: '',
    audioUri: null,
    sourceUri: null,
    audioPosition: 0,
    rightToLeft: false,
    textSize: 100,
    removeSpaces: false,
    parseWarning: null,
    dictLinks: {
      dict1: '',
      dict2: '',
      translator: ''
    },

    // Annotation/display settings
    showLearning: 1,
    displayStatTrans: 1,
    modeTrans: 2,
    termDelimiter: '',
    annTextSize: 50,

    // Reader layout
    readerWidth: 100,

    // Generated paragraph styles
    paragraphStyles: '',

    // UI state
    selectedHex: null,
    selectedPosition: null,
    popoverTargetElement: null,
    isPopoverOpen: false,
    isEditModalOpen: false,
    isLoading: false,
    isInitialized: false,

    // Display settings
    showAll: false,
    showTranslations: true,

    // Rendered HTML
    renderedHtml: '',

    /**
     * Get rendered HTML for the text.
     */
    getRenderedHtml(): string {
      if (this.words.length === 0) return '';

      const settings: RenderSettings = {
        showAll: this.showAll,
        showTranslations: this.showTranslations,
        rightToLeft: this.rightToLeft,
        textSize: this.textSize,
        // Annotation settings for Markdown-rendered annotations
        showLearning: this.showLearning,
        displayStatTrans: this.displayStatTrans,
        modeTrans: this.modeTrans,
        annTextSize: this.annTextSize
      };

      return renderText(this.words, settings);
    },

    /**
     * Set text element HTML (CSP-compatible - use with x-effect)
     */
    setTextHtml(el: HTMLElement): void {
      el.innerHTML = this.renderedHtml;
    },

    /**
     * Load words for a text from the API.
     * Note: Named 'loadText' instead of 'init' because Alpine auto-calls init() on stores.
     */
    async loadText(textId: number): Promise<void> {
      if (!textId || textId <= 0) {
        return;
      }

      this.isLoading = true;

      try {
        const response = await TextsApi.getWords(textId);

        if (response.error || !response.data) {
          console.error('Failed to load text words:', response.error);
          this.isLoading = false;
          return;
        }

        this.initFromData(response.data.words, response.data.config);
      } catch (error) {
        console.error('Error loading text words:', error);
      }

      this.isLoading = false;
    },

    /**
     * Initialize from pre-loaded data.
     */
    initFromData(words: TextWord[], config: TextReadingConfig): void {
      // Set configuration
      this.textId = config.textId;
      this.langId = config.langId;
      this.title = config.title;
      this.audioUri = config.audioUri;
      this.sourceUri = config.sourceUri;
      this.audioPosition = config.audioPosition;
      this.rightToLeft = config.rightToLeft;
      this.textSize = config.textSize;
      this.removeSpaces = config.removeSpaces ?? false;
      this.parseWarning = config.parseWarning ?? null;
      this.dictLinks = config.dictLinks;

      // Annotation/display settings
      this.showLearning = config.showLearning ?? 1;
      this.displayStatTrans = config.displayStatTrans ?? 1;
      this.modeTrans = config.modeTrans ?? 2;
      this.termDelimiter = config.termDelimiter ?? '';
      this.annTextSize = config.annTextSize ?? 50;
      this.readerWidth = config.readerWidth ?? 100;

      // Generate and inject dynamic styles
      injectTextStyles(config);
      this.paragraphStyles = generateParagraphStyles(config);

      // Build word data and index
      this.words = [];
      this.wordsByHex.clear();

      for (const word of words) {
        const wordData: WordData = {
          position: word.position,
          sentenceId: word.sentenceId,
          text: word.text,
          textLc: word.textLc,
          hex: word.hex,
          isNotWord: word.isNotWord,
          wordCount: word.wordCount,
          hidden: word.hidden,
          wordId: word.wordId ?? null,
          status: word.status ?? 0,
          translation: word.translation ?? '',
          romanization: word.romanization ?? '',
          notes: word.notes ?? '',
          tags: word.tags
        };

        // Copy multiword references (mw2, mw3, etc.)
        for (let i = 2; i <= 9; i++) {
          const mwKey = `mw${i}` as const;
          if (word[mwKey]) {
            wordData[mwKey] = word[mwKey];
          }
        }

        this.words.push(wordData);

        // Index by hex for quick lookup
        if (!word.isNotWord) {
          const existing = this.wordsByHex.get(word.hex) || [];
          existing.push(wordData);
          this.wordsByHex.set(word.hex, existing);
        }
      }

      this.isInitialized = true;

      // Generate rendered HTML
      this.renderedHtml = this.getRenderedHtml();
    },

    /**
     * Select a word and open the popover.
     */
    selectWord(hex: string, position: number, targetElement?: HTMLElement): void {
      this.selectedHex = hex;
      this.selectedPosition = position;
      this.popoverTargetElement = targetElement || null;
      this.isPopoverOpen = true;
    },

    /**
     * Close the popover.
     */
    closePopover(): void {
      this.isPopoverOpen = false;
      this.popoverTargetElement = null;
      this.selectedHex = null;
      this.selectedPosition = null;
    },

    /**
     * Open the edit modal (from popover).
     */
    openEditModal(): void {
      this.isPopoverOpen = false;
      this.isEditModalOpen = true;
    },

    /**
     * Close the edit modal.
     */
    closeEditModal(): void {
      this.isEditModalOpen = false;
      this.selectedHex = null;
      this.selectedPosition = null;
      this.popoverTargetElement = null;
    },

    /**
     * Get the currently selected word data.
     */
    getSelectedWord(): WordData | null {
      if (!this.selectedHex || this.selectedPosition === null) return null;

      const wordsWithHex = this.wordsByHex.get(this.selectedHex);
      if (!wordsWithHex) return null;

      // Find the specific word by position
      return wordsWithHex.find(w => w.position === this.selectedPosition) || wordsWithHex[0];
    },

    /**
     * Get all words with a given hex.
     */
    getWordsByHex(hex: string): WordData[] {
      return this.wordsByHex.get(hex) || [];
    },

    /**
     * Set status for all words with the given hex.
     */
    async setStatus(hex: string, status: number): Promise<boolean> {
      const words = this.wordsByHex.get(hex);
      if (!words || words.length === 0) return false;

      // Get the first word's ID (they all share the same word entry)
      const wordId = words[0].wordId;
      if (!wordId) {
        // Word doesn't exist yet - need to create it first
        return false;
      }

      this.isLoading = true;

      try {
        const response = await TermsApi.setStatus(wordId, status);

        if (response.error) {
          console.error('Failed to set status:', response.error);
          this.isLoading = false;
          return false;
        }

        // Update all words with this hex in store and DOM
        this.updateWordInStore(hex, { status });
        updateWordStatusInDOM(hex, status, wordId);

        this.isLoading = false;
        this.closePopover();
        return true;
      } catch (error) {
        console.error('Error setting status:', error);
        this.isLoading = false;
        return false;
      }
    },

    /**
     * Create a word quickly with well-known (99) or ignored (98) status.
     */
    async createQuickWord(hex: string, position: number, status: 98 | 99): Promise<boolean> {
      this.isLoading = true;

      try {
        const response = await TermsApi.createQuick(this.textId, position, status);

        if (response.error || !response.data?.term_id) {
          console.error('Failed to create word:', response.error);
          this.isLoading = false;
          return false;
        }

        const newWordId = response.data.term_id;

        // Update all words with this hex in store and DOM
        this.updateWordInStore(hex, {
          wordId: newWordId,
          status
        });
        updateWordStatusInDOM(hex, status, newWordId);

        this.isLoading = false;
        this.closePopover();
        return true;
      } catch (error) {
        console.error('Error creating word:', error);
        this.isLoading = false;
        return false;
      }
    },

    /**
     * Delete a word (reset to unknown status).
     */
    async deleteWord(hex: string): Promise<boolean> {
      const words = this.wordsByHex.get(hex);
      if (!words || words.length === 0) return false;

      const wordId = words[0].wordId;
      if (!wordId) return false;

      this.isLoading = true;

      try {
        const response = await TermsApi.delete(wordId);

        if (response.error) {
          console.error('Failed to delete word:', response.error);
          this.isLoading = false;
          return false;
        }

        // Reset all words with this hex to unknown in store and DOM
        this.updateWordInStore(hex, {
          wordId: null,
          status: 0,
          translation: '',
          romanization: '',
          tags: undefined
        });
        updateWordStatusInDOM(hex, 0, null);
        updateWordTranslationInDOM(hex, '', '');

        this.isLoading = false;
        this.closePopover();
        return true;
      } catch (error) {
        console.error('Error deleting word:', error);
        this.isLoading = false;
        return false;
      }
    },

    /**
     * Get dictionary URL for the selected word.
     */
    getDictUrl(which: 'dict1' | 'dict2' | 'translator'): string {
      const word = this.getSelectedWord();
      if (!word) return '#';

      const template = this.dictLinks[which];
      if (!template) return '#';

      return template.replace('lwt_term', encodeURIComponent(word.text));
    },

    /**
     * Check if a dictionary URL is configured.
     */
    hasDictUrl(which: 'dict1' | 'dict2' | 'translator'): boolean {
      return !!this.dictLinks[which];
    },

    /**
     * Update word data in the store (triggers reactivity).
     */
    updateWordInStore(hex: string, updates: Partial<WordData>): void {
      const words = this.wordsByHex.get(hex);
      if (!words) return;

      // Update each word with this hex
      for (const word of words) {
        Object.assign(word, updates);
      }

      // Force reactivity by creating new Map entry
      this.wordsByHex.set(hex, [...words]);
    }
  };
}

/**
 * Initialize the word store as an Alpine.js store.
 */
export function initWordStore(): void {
  Alpine.store('words', createWordStore());
}

/**
 * Get the word store instance.
 */
export function getWordStore(): WordStoreState {
  return Alpine.store('words') as WordStoreState;
}

// Register the store immediately
initWordStore();

// Expose for global access
declare global {
  interface Window {
    getWordStore: typeof getWordStore;
  }
}

window.getWordStore = getWordStore;
