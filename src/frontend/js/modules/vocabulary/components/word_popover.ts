/**
 * Word Popover - Alpine.js component for non-blocking word info display.
 *
 * Displays word information in a positioned popover near the clicked word,
 * allowing users to continue reading while viewing word details.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.0.0
 */

import Alpine from 'alpinejs';
import type { WordStoreState, WordData } from '../stores/word_store';
import { speechDispatcher } from '@shared/utils/user_interactions';
import { initIcons } from '@shared/icons/lucide_icons';
import { announce } from '@shared/accessibility/aria_live';
import {
  TERM_STATUS_VALUES,
  statusOptions,
  type StatusInfo
} from '@shared/stores/statuses';

/**
 * Popover position configuration.
 */
const POPOVER_CONFIG = {
  offsetY: 8,
  minWidth: 280,
  maxWidth: 350
};

/**
 * Popover position.
 */
interface PopoverPosition {
  top: number;
  left: number;
  placement: 'above' | 'below';
}

/**
 * Word popover Alpine.js component interface.
 */
export interface WordPopoverData {
  // Computed properties
  readonly store: WordStoreState;
  readonly word: WordData | null;
  readonly isOpen: boolean;
  readonly isLoading: boolean;
  readonly isUnknown: boolean;
  readonly statuses: StatusInfo[];

  // CSP-safe proxy properties (null-safe access to word.*)
  readonly wordText: string;
  readonly wordTranslation: string;
  readonly wordRomanization: string;
  readonly hasTranslation: boolean;
  readonly hasRomanization: boolean;
  readonly hasWordId: boolean;
  readonly wordLabel: string;

  // Position state
  position: PopoverPosition;
  popoverEl: HTMLElement | null;

  // Lifecycle
  init(): void;

  // Position methods
  calculatePosition(): void;
  getPositionStyle(): string;

  // Methods
  close(): void;
  speakWord(): void;
  setStatus(status: number): Promise<void>;
  markWellKnown(): Promise<void>;
  markIgnored(): Promise<void>;
  deleteWord(): Promise<void>;
  openEditForm(): void;
  getDictUrl(which: 'dict1' | 'dict2' | 'translator'): string;
  hasDictUrl(which: 'dict1' | 'dict2' | 'translator'): boolean;
  isCurrentStatus(status: number): boolean;
  getStatusButtonClass(status: number): string;

  // Event handlers
  handleClickOutside(event: MouseEvent): void;
  handleEscape(event: KeyboardEvent): void;
}

/**
 * Create the word popover Alpine.js component data.
 */
export function wordPopoverData(): WordPopoverData {
  return {
    // Position state
    position: { top: 0, left: 0, placement: 'below' },
    popoverEl: null,

    init(): void {
      // Watch for popover open state changes and target element changes
      Alpine.effect(() => {
        // Track both isPopoverOpen and popoverTargetElement to trigger on word changes
        const isOpen = this.store.isPopoverOpen;
        const targetEl = this.store.popoverTargetElement;

        if (isOpen && targetEl) {
          // Use requestAnimationFrame to wait for DOM update
          requestAnimationFrame(() => {
            this.calculatePosition();
            initIcons();
            // Announce word details for screen readers
            const word = this.word;
            if (word) {
              const statusInfo = statusOptions(TERM_STATUS_VALUES)
                .find(s => s.value === word.status);
              const statusLabel = statusInfo?.label || 'Unknown';
              announce(`${word.text}, ${statusLabel}`);
            }
          });
        }
      });

      // Set up click outside handler
      document.addEventListener('click', (e) => this.handleClickOutside(e));
      document.addEventListener('keydown', (e) => this.handleEscape(e));
    },

    get store(): WordStoreState {
      return Alpine.store('words') as WordStoreState;
    },

    get word(): WordData | null {
      return this.store.getSelectedWord();
    },

    get isOpen(): boolean {
      return this.store.isPopoverOpen;
    },

    get isLoading(): boolean {
      return this.store.isLoading;
    },

    get isUnknown(): boolean {
      const word = this.word;
      return !word || word.status === 0;
    },

    get statuses(): StatusInfo[] {
      return statusOptions(TERM_STATUS_VALUES);
    },

    // CSP-safe null-safe proxy properties for word.* access.
    // During batched reactive updates, word can become null (selectedHex cleared)
    // while word.* bindings are still dirty, causing MemberExpression on null.
    get wordText(): string {
      return this.word?.text ?? '';
    },

    get wordTranslation(): string {
      return this.word?.translation ?? '';
    },

    get wordRomanization(): string {
      return this.word?.romanization ?? '';
    },

    get hasTranslation(): boolean {
      const word = this.word;
      return !!word && !this.isUnknown && !!word.translation;
    },

    get hasRomanization(): boolean {
      return !!this.word?.romanization;
    },

    get hasWordId(): boolean {
      return !!this.word?.wordId;
    },

    get wordLabel(): string {
      return this.isUnknown ? 'Add' : 'Edit';
    },

    calculatePosition(): void {
      const targetEl = this.store.popoverTargetElement;
      if (!targetEl) return;

      // Get popover element dimensions (use estimated if not yet rendered)
      const popoverEl = document.querySelector('.word-popover') as HTMLElement | null;
      const popoverWidth = popoverEl?.offsetWidth || POPOVER_CONFIG.minWidth;
      const popoverHeight = popoverEl?.offsetHeight || 200;

      const targetRect = targetEl.getBoundingClientRect();

      // Default: position below the word
      let top = targetRect.bottom + POPOVER_CONFIG.offsetY + window.scrollY;
      let left = targetRect.left + window.scrollX;
      let placement: 'above' | 'below' = 'below';

      // Check if popover would overflow bottom of viewport
      if (targetRect.bottom + POPOVER_CONFIG.offsetY + popoverHeight > window.innerHeight) {
        // Position above the word instead
        top = targetRect.top - popoverHeight - POPOVER_CONFIG.offsetY + window.scrollY;
        placement = 'above';
      }

      // Ensure popover doesn't overflow top of viewport
      if (top < window.scrollY + 10) {
        top = window.scrollY + 10;
      }

      // Adjust horizontal position to stay within viewport
      if (left + popoverWidth > window.innerWidth - 10) {
        left = window.innerWidth - popoverWidth - 10;
      }
      if (left < 10) {
        left = 10;
      }

      this.position = { top, left, placement };
    },

    getPositionStyle(): string {
      return `top: ${this.position.top}px; left: ${this.position.left}px;`;
    },

    close(): void {
      this.store.closePopover();
    },

    speakWord(): void {
      const word = this.word;
      if (word && this.store.langId) {
        speechDispatcher(word.text, this.store.langId);
      }
    },

    async setStatus(status: number): Promise<void> {
      const word = this.word;
      if (!word) return;

      await this.store.setStatus(word.hex, status);
      const statusInfo = statusOptions(TERM_STATUS_VALUES).find(s => s.value === status);
      announce(`Changed to ${statusInfo?.label || 'status ' + status}`);
    },

    async markWellKnown(): Promise<void> {
      const word = this.word;
      if (!word) return;

      await this.store.createQuickWord(word.hex, word.position, 99);
    },

    async markIgnored(): Promise<void> {
      const word = this.word;
      if (!word) return;

      await this.store.createQuickWord(word.hex, word.position, 98);
    },

    async deleteWord(): Promise<void> {
      const word = this.word;
      if (!word) return;

      if (confirm('Delete this term?')) {
        await this.store.deleteWord(word.hex);
      }
    },

    openEditForm(): void {
      this.store.openEditModal();
    },

    getDictUrl(which: 'dict1' | 'dict2' | 'translator'): string {
      return this.store.getDictUrl(which);
    },

    hasDictUrl(which: 'dict1' | 'dict2' | 'translator'): boolean {
      return this.store.hasDictUrl(which);
    },

    isCurrentStatus(status: number): boolean {
      const word = this.word;
      return word ? word.status === status : false;
    },

    getStatusButtonClass(status: number): string {
      const statusInfo = statusOptions(TERM_STATUS_VALUES).find(s => s.value === status);
      const baseClass = statusInfo?.class || '';

      if (this.isCurrentStatus(status)) {
        return `button is-small ${baseClass}`;
      }
      return `button is-small is-outlined ${baseClass}`;
    },

    handleClickOutside(event: MouseEvent): void {
      if (!this.isOpen) return;

      const target = event.target as HTMLElement;
      const popoverEl = document.querySelector('.word-popover');

      // Check if click is outside popover and not on a word element
      if (popoverEl && !popoverEl.contains(target) && !target.closest('.word, .mword')) {
        this.close();
      }
    },

    handleEscape(event: KeyboardEvent): void {
      if (event.key === 'Escape' && this.isOpen) {
        this.close();
      }
    }
  };
}

/**
 * Initialize the word popover Alpine.js component.
 */
export function initWordPopoverAlpine(): void {
  Alpine.data('wordPopover', wordPopoverData);
}

// Register the component immediately
initWordPopoverAlpine();

// Expose for global access
declare global {
  interface Window {
    wordPopoverData: typeof wordPopoverData;
  }
}

window.wordPopoverData = wordPopoverData;
