/**
 * Text Reader - Main Alpine.js component for text reading view.
 *
 * Handles initialization, rendering, and event coordination for the text reading interface.
 * Uses the word store for state and text renderer for display.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.0.0
 */

import Alpine from 'alpinejs';
import type { WordStoreState } from '@modules/vocabulary/stores/word_store';
import { renderText, updateWordStatusInDOM, renderParseWarning, type RenderSettings }
  from '../pages/reading/text_renderer';
import { setupMultiWordSelection } from '../pages/reading/text_multiword_selection';
import { TextsApi } from '@modules/text/api/texts_api';
import { SettingsApi } from '@modules/admin/api/settings_api';
import { renderBookNav } from '../pages/reading/book_nav_renderer';
import { initIcons } from '@shared/icons/lucide_icons';
import { rememberModalAnchor } from '@modules/vocabulary/components/modal_placement';

/**
 * Text reader Alpine.js component interface.
 */
export interface TextReaderData {
  // State
  isLoading: boolean;
  showAll: boolean;
  showTranslations: boolean;
  error: string | null;
  statusMessage: string | null;

  // Computed properties
  readonly store: WordStoreState;
  readonly textId: number;
  readonly title: string;
  readonly isInitialized: boolean;

  // Lifecycle methods
  init(): Promise<void>;

  // Rendering methods
  renderTextContent(): void;
  getRenderSettings(): RenderSettings;

  // Event handlers
  handleWordClick(event: MouseEvent): void;
  handleKeydown(event: KeyboardEvent): void;

  // Actions
  toggleShowAll(): void;
  toggleTranslations(): void;
  markAllWellKnown(): Promise<void>;
  markAllIgnored(): Promise<void>;
  goBack(): void;
  goNext(): void;

  // Reader layout
  readerWidth: number;
  readerTextSize: number;
  increaseTextSize(): void;
  decreaseTextSize(): void;
  onReaderWidthChange(): void;
  applyReaderLayout(): void;

  // Helpers
  getTextIdFromUrl(): number;
  updateWordDisplay(hex: string, status: number, wordId: number | null): void;
  setupEventListeners(): void;
  loadBookNav(textId: number): Promise<void>;
}

/** Debounce timer for persisting reader settings. */
let saveWidthTimer: ReturnType<typeof setTimeout> | null = null;
let saveTextSizeTimer: ReturnType<typeof setTimeout> | null = null;

/**
 * Debounced save of a setting (300ms).
 */
function debouncedSave(
  timer: ReturnType<typeof setTimeout> | null,
  key: string,
  value: string
): ReturnType<typeof setTimeout> {
  if (timer) clearTimeout(timer);
  return setTimeout(() => {
    SettingsApi.save(key, value);
  }, 300);
}

/**
 * Create the text reader Alpine.js component data.
 */
export function textReaderData(): TextReaderData {
  return {
    isLoading: true,
    showAll: false,
    showTranslations: true,
    error: null,
    statusMessage: null,
    readerWidth: 100,
    readerTextSize: 0,

    get store(): WordStoreState {
      return Alpine.store('words') as WordStoreState;
    },

    get textId(): number {
      return this.store.textId;
    },

    get title(): string {
      return this.store.title;
    },

    get isInitialized(): boolean {
      return this.store.isInitialized;
    },

    async init(): Promise<void> {
      this.isLoading = true;
      this.error = null;

      try {
        const textId = this.getTextIdFromUrl();
        if (!textId || textId === 0) {
          // No text ID - we're not on a text reading page
          this.isLoading = false;
          return;
        }

        await this.store.loadText(textId);

        if (!this.store.isInitialized) {
          this.error = 'Failed to load text';
          this.isLoading = false;
          return;
        }

        // Initialize reader layout from store
        this.readerWidth = this.store.readerWidth;
        this.readerTextSize = this.store.textSize;

        // Render the text content
        this.renderTextContent();
        this.applyReaderLayout();

        // Set up event listeners
        this.setupEventListeners();

        this.isLoading = false;

        // Book/chapter nav is non-critical chrome — load it after the text is
        // readable so a slow/missing response never blocks reading.
        void this.loadBookNav(textId);
      } catch (err) {
        console.error('Error initializing text reader:', err);
        this.error = 'An error occurred while loading the text';
        this.isLoading = false;
      }
    },

    renderTextContent(): void {
      const container = document.getElementById('thetext');
      if (!container) {
        console.error('Text container not found');
        return;
      }

      const settings = this.getRenderSettings();
      const html = renderText(this.store.words, settings);
      container.innerHTML = renderParseWarning(this.store.parseWarning) + html;

      // Apply RTL styling if needed
      if (this.store.rightToLeft) {
        container.style.direction = 'rtl';
      }

      // Apply text size
      if (this.store.textSize !== 100) {
        container.style.fontSize = `${this.store.textSize}%`;
      }
    },

    getRenderSettings(): RenderSettings {
      return {
        showAll: this.showAll,
        showTranslations: this.showTranslations,
        rightToLeft: this.store.rightToLeft,
        textSize: this.store.textSize,
        // Annotation settings required for Markdown-rendered translations
        showLearning: this.store.showLearning,
        displayStatTrans: this.store.displayStatTrans,
        modeTrans: this.store.modeTrans,
        annTextSize: this.store.annTextSize
      };
    },

    setupEventListeners(): void {
      const container = document.getElementById('thetext');
      if (!container) return;

      // Use event delegation for word clicks
      container.addEventListener('click', (e) => this.handleWordClick(e));

      // Keyboard navigation
      document.addEventListener('keydown', (e) => this.handleKeydown(e));

      // Multi-word selection via native text selection
      // When user selects multiple words, the multi-word modal opens
      setupMultiWordSelection(container);
    },

    async loadBookNav(textId: number): Promise<void> {
      const host = document.getElementById('book-context-nav');
      if (!host) return;

      try {
        const res = await TextsApi.getBookContext(textId);
        const html = renderBookNav(res.data?.book ?? null);
        host.innerHTML = html;
        if (html !== '') {
          // Hydrate the lucide placeholders the renderer emitted.
          initIcons();
        }
      } catch (err) {
        // Non-critical chrome: a failure just leaves the nav empty.
        console.error('Failed to load book navigation:', err);
      }
    },

    handleWordClick(event: MouseEvent): void {
      const target = event.target as HTMLElement;

      // Find the word span (might be the target or a parent)
      const wordEl = target.closest('.word, .mword') as HTMLElement | null;
      if (!wordEl) return;

      event.preventDefault();
      event.stopPropagation();

      // Anchor for the term modals, which place themselves in the half of the
      // viewport this word is not in.
      rememberModalAnchor(wordEl);

      // Get word data from element (use getAttribute for underscore attributes)
      const hex = wordEl.getAttribute('data_hex') || '';
      const position = parseInt(wordEl.getAttribute('data_order') || wordEl.getAttribute('data_pos') || '0', 10);

      if (!hex) return;

      // Select the word (opens popover near the clicked element)
      this.store.selectWord(hex, position, wordEl);

      // NOTE: TTS integration disabled - requires speechDispatcher import and TTS settings check
      // speechDispatcher(wordEl.textContent || '', this.store.langId);
    },

    handleKeydown(): void {
      // Only handle if popover/modal is not open
      if (this.store.isPopoverOpen || this.store.isEditModalOpen) return;

      // NOTE: Keyboard navigation planned - arrow keys for word navigation, number keys for quick status
    },

    toggleShowAll(): void {
      this.showAll = !this.showAll;
      this.store.showAll = this.showAll;
      this.renderTextContent();
    },

    toggleTranslations(): void {
      this.showTranslations = !this.showTranslations;
      this.store.showTranslations = this.showTranslations;
      // Translations are typically shown via CSS, so we just toggle a class
      const container = document.getElementById('thetext');
      if (container) {
        container.classList.toggle('hide-translations', !this.showTranslations);
      }
    },

    increaseTextSize(): void {
      const next = Math.min(this.readerTextSize + 10, 300);
      this.readerTextSize = next;
      this.applyReaderLayout();
      saveTextSizeTimer = debouncedSave(
        saveTextSizeTimer, 'set-reader-text-size', String(next)
      );
    },

    decreaseTextSize(): void {
      const next = Math.max(this.readerTextSize - 10, 50);
      this.readerTextSize = next;
      this.applyReaderLayout();
      saveTextSizeTimer = debouncedSave(
        saveTextSizeTimer, 'set-reader-text-size', String(next)
      );
    },

    onReaderWidthChange(): void {
      this.applyReaderLayout();
      saveWidthTimer = debouncedSave(
        saveWidthTimer, 'set-reader-width', String(this.readerWidth)
      );
    },

    applyReaderLayout(): void {
      const content = document.querySelector(
        '.reading-content'
      ) as HTMLElement | null;
      if (content) {
        content.style.maxWidth = this.readerWidth < 100
          ? this.readerWidth + '%' : '';
      }
      const textEl = document.getElementById('thetext');
      if (textEl) {
        textEl.style.fontSize = this.readerTextSize + '%';
      }
    },

    async markAllWellKnown(): Promise<void> {
      if (!confirm('Mark all unknown words as Well Known?')) return;

      this.statusMessage = null;

      try {
        const response = await TextsApi.markAllWellKnown(this.store.textId);

        if (response.error) {
          console.error('Failed to mark all well-known:', response.error);
          this.statusMessage = 'Failed to mark words as well-known.';
          return;
        }

        // Update display for each affected word
        const words = response.data?.words ?? [];
        for (const word of words) {
          this.updateWordDisplay(word.hex, 99, word.wid);
          this.store.updateWordInStore(word.hex, {
            wordId: word.wid,
            status: 99
          });
        }

        this.statusMessage = `Marked ${words.length} word${words.length !== 1 ? 's' : ''} as Well Known.`;
      } catch (err) {
        console.error('Error marking all well-known:', err);
        this.statusMessage = 'Error marking words as well-known.';
      }
    },

    async markAllIgnored(): Promise<void> {
      if (!confirm('Mark all unknown words as Ignored?')) return;

      this.statusMessage = null;

      try {
        const response = await TextsApi.markAllIgnored(this.store.textId);

        if (response.error) {
          console.error('Failed to mark all ignored:', response.error);
          this.statusMessage = 'Failed to mark words as ignored.';
          return;
        }

        // Update display for each affected word
        const words = response.data?.words ?? [];
        for (const word of words) {
          this.updateWordDisplay(word.hex, 98, word.wid);
          this.store.updateWordInStore(word.hex, {
            wordId: word.wid,
            status: 98
          });
        }

        this.statusMessage = `Marked ${words.length} word${words.length !== 1 ? 's' : ''} as Ignored.`;
      } catch (err) {
        console.error('Error marking all ignored:', err);
        this.statusMessage = 'Error marking words as ignored.';
      }
    },

    goBack(): void {
      // Navigate to previous text or text list
      window.history.back();
    },

    goNext(): void {
      // NOTE: Next text navigation requires store.nextTextId from API
      // TextNavigationService provides server-rendered links in read_header.php
    },

    getTextIdFromUrl(): number {
      // Try to get from URL path: /text/123/read (RESTful) or /text/read/123 (legacy)
      const restfulMatch = window.location.pathname.match(/\/text\/(\d+)\/read/);
      if (restfulMatch) {
        return parseInt(restfulMatch[1], 10);
      }
      const legacyMatch = window.location.pathname.match(/\/text\/read\/(\d+)/);
      if (legacyMatch) {
        return parseInt(legacyMatch[1], 10);
      }

      // Try to get from query string: ?text=123 or ?tid=123 or ?start=123
      const params = new URLSearchParams(window.location.search);
      const textParam = params.get('text') || params.get('tid') || params.get('start');
      if (textParam) {
        return parseInt(textParam, 10);
      }

      return 0;
    },

    updateWordDisplay(hex: string, status: number, wordId: number | null): void {
      updateWordStatusInDOM(hex, status, wordId);
    }
  };
}

/**
 * Initialize the text reader Alpine.js component.
 */
export function initTextReaderAlpine(): void {
  Alpine.data('textReader', textReaderData);
}

// Register the component immediately
initTextReaderAlpine();

// Expose for global access
declare global {
  interface Window {
    textReaderData: typeof textReaderData;
  }
}

window.textReaderData = textReaderData;
