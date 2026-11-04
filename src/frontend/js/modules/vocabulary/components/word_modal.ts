/**
 * Word Modal - Alpine.js component for Bulma word edit modal.
 *
 * Displays word edit form in a centered Bulma modal.
 * This modal is only used for editing - info view is handled by word_popover.
 * Integrates with the word store for state management.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.0.0
 */

import { confirmDiscardChanges } from '@shared/components/confirm_dialog';
import Alpine from 'alpinejs';
import type { WordStoreState, WordData } from '../stores/word_store';
import type { WordFormStoreState } from '../stores/word_form_store';
import { speechDispatcher } from '@shared/utils/user_interactions';
import { initIcons } from '@shared/icons/lucide_icons';
import { trapFocus, releaseFocus } from '@shared/accessibility/focus_trap';
import { announce } from '@shared/accessibility/aria_live';
import { t } from '@shared/i18n/translator';
import {
  computeModalPlacement,
  getRememberedModalAnchor,
  modalPlacementClasses,
  type ModalPlacement,
} from './modal_placement';

/**
 * Status display information.
 *
 * `abbr` is a language-neutral short label ("1".."5"); empty for 98/99 where
 * the localized full `label` should be shown instead.
 */
interface StatusInfo {
  value: number;
  label: string;
  abbr: string;
  class: string;
}

/**
 * Status definitions. Computed lazily so that translations are loaded.
 */
function buildStatuses(): StatusInfo[] {
  const learning = t('common.status_learning');
  const learned = t('common.status_learned');
  const wellKnown = t('common.status_well_known');
  const ignored = t('common.status_ignored');
  // For numeric statuses `abbr` is the digit (button label).
  // For 98/99 the localized full name doubles as the button label.
  return [
    { value: 1, label: `${learning} (1)`, abbr: '1', class: 'is-danger' },
    { value: 2, label: `${learning} (2)`, abbr: '2', class: 'is-warning' },
    { value: 3, label: `${learning} (3)`, abbr: '3', class: 'is-info' },
    { value: 4, label: `${learning} (4)`, abbr: '4', class: 'is-primary' },
    { value: 5, label: learned, abbr: '5', class: 'is-success' },
    { value: 99, label: wellKnown, abbr: wellKnown, class: 'is-success is-light' },
    { value: 98, label: ignored, abbr: ignored, class: 'is-light' }
  ];
}

/**
 * View mode for the modal.
 */
export type ViewMode = 'info' | 'edit';

/**
 * Word modal Alpine.js component interface.
 */
export interface WordModalData {
  // Computed properties
  readonly store: WordStoreState;
  readonly formStore: WordFormStoreState;
  readonly word: WordData | null;
  readonly isOpen: boolean;
  readonly isLoading: boolean;
  readonly isUnknown: boolean;
  readonly modalTitle: string;
  readonly statuses: StatusInfo[];

  // Which half of the viewport the card takes, so it avoids the clicked word
  placement: ModalPlacement;
  readonly modalClasses: string;

  // CSP-safe null-safe proxy properties for word.*
  readonly wordText: string;
  readonly wordTranslation: string;
  readonly wordRomanization: string;
  readonly wordNotes: string;
  readonly wordTags: string;
  readonly hasTranslation: boolean;
  readonly hasRomanization: boolean;
  readonly hasNotes: boolean;
  readonly hasTags: boolean;
  readonly hasWordId: boolean;

  // View mode
  viewMode: ViewMode;

  // Lifecycle
  init(): void;

  // Methods
  close(): Promise<void>;
  speakWord(): void;
  setStatus(status: number): Promise<void>;
  markWellKnown(): Promise<void>;
  markIgnored(): Promise<void>;
  deleteWord(): Promise<void>;
  getDictUrl(which: 'dict1' | 'dict2' | 'translator'): string;
  hasDictUrl(which: 'dict1' | 'dict2' | 'translator'): boolean;
  isCurrentStatus(status: number): boolean;
  getStatusButtonClass(status: number): string;

  // Edit mode methods
  showEditForm(): Promise<void>;
  hideEditForm(): void;
  onFormSaved(): void;
  onFormCancelled(): void;
}

/**
 * Create the word modal Alpine.js component data.
 */
export function wordModalData(): WordModalData {
  return {
    // View mode state
    viewMode: 'info' as ViewMode,

    // Default matches the pre-existing placement, so nothing moves until an
    // anchor says it should.
    placement: 'bottom' as ModalPlacement,

    // Initialize icons when modal opens (called by Alpine.js x-init or x-effect)
    init(): void {
      // Close on Escape key
      document.addEventListener('keydown', (e: KeyboardEvent) => {
        if (e.key === 'Escape' && this.isOpen) {
          this.close();
        }
      });

      // Watch for modal open state changes to load form data and re-initialize icons
      Alpine.effect(() => {
        if (this.store.isEditModalOpen) {
          // Decide placement before the card paints, so it does not visibly
          // jump. `openEditModal()` keeps `popoverTargetElement` alive, so the
          // clicked word is still available here.
          this.placement = computeModalPlacement(
            this.store.popoverTargetElement || getRememberedModalAnchor()
          );
          // Load form data when modal opens
          this.showEditForm();
          // Re-initialize icons and trap focus after DOM update
          requestAnimationFrame(() => {
            initIcons();
            const modalCard = document.querySelector<HTMLElement>('#word-modal-title')
              ?.closest('.modal-card');
            if (modalCard) {
              trapFocus(modalCard as HTMLElement);
            }
            announce(this.modalTitle);
          });
        }
      });

      // Watch for form store signals to close modal
      Alpine.effect(() => {
        if (this.formStore.shouldCloseModal) {
          this.formStore.shouldCloseModal = false;
          // Set viewMode first so x-if tears down the edit template
          // before reset() triggers reactive updates on form fields
          this.viewMode = 'info';
          this.formStore.reset();
          releaseFocus();
          this.store.closeEditModal();
        }
      });

      // Watch for form store signals to cancel edit (close modal)
      Alpine.effect(() => {
        if (this.formStore.shouldReturnToInfo) {
          this.formStore.shouldReturnToInfo = false;
          this.viewMode = 'info';
          this.formStore.reset();
          releaseFocus();
          this.store.closeEditModal();
        }
      });
    },

    get store(): WordStoreState {
      return Alpine.store('words') as WordStoreState;
    },

    get formStore(): WordFormStoreState {
      return Alpine.store('wordForm') as WordFormStoreState;
    },

    get word(): WordData | null {
      return this.store.getSelectedWord();
    },

    get isOpen(): boolean {
      return this.store.isEditModalOpen;
    },

    get modalClasses(): string {
      return modalPlacementClasses(this.isOpen, this.placement);
    },

    get isLoading(): boolean {
      return this.store.isLoading || this.formStore.isLoading;
    },

    get isUnknown(): boolean {
      const word = this.word;
      return !word || word.status === 0;
    },

    get modalTitle(): string {
      if (this.viewMode === 'edit') {
        return this.formStore.isNewWord ? 'Add Term' : 'Edit Term';
      }
      if (this.isUnknown) {
        return 'New Word';
      }
      return 'Word';
    },

    get statuses(): StatusInfo[] {
      return buildStatuses();
    },

    // CSP-safe null-safe proxies for word.* — during batched reactive updates
    // word can become null while word.* bindings are still dirty.
    get wordText(): string {
      return this.word?.text ?? '';
    },

    get wordTranslation(): string {
      return this.word?.translation ?? '';
    },

    get wordRomanization(): string {
      return this.word?.romanization ?? '';
    },

    get wordNotes(): string {
      return this.word?.notes ?? '';
    },

    get wordTags(): string {
      return this.word?.tags ?? '';
    },

    get hasTranslation(): boolean {
      const word = this.word;
      return !!word && !this.isUnknown && !!word.translation;
    },

    get hasRomanization(): boolean {
      return !!this.word?.romanization;
    },

    get hasNotes(): boolean {
      const word = this.word;
      return !!word && !this.isUnknown && !!word.notes;
    },

    get hasTags(): boolean {
      const word = this.word;
      return !!word && !this.isUnknown && !!word.tags;
    },

    get hasWordId(): boolean {
      return !!this.word?.wordId;
    },

    async close(): Promise<void> {
      // Check for unsaved changes
      if (this.formStore.isDirty) {
        if (!await confirmDiscardChanges()) {
          return;
        }
        this.formStore.reset();
      }
      releaseFocus();
      this.store.closeEditModal();
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
      const statusInfo = buildStatuses().find(s => s.value === status);
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
      const statusInfo = buildStatuses().find(s => s.value === status);
      const baseClass = statusInfo?.class || '';

      if (this.isCurrentStatus(status)) {
        return `button is-small ${baseClass}`;
      }
      return `button is-small is-outlined ${baseClass}`;
    },

    // =========================================================================
    // Edit Mode Methods
    // =========================================================================

    async showEditForm(): Promise<void> {
      const word = this.word;
      if (!word) return;

      // Load the form data
      await this.formStore.loadForEdit(
        this.store.textId,
        word.position,
        word.wordId ?? undefined
      );

      // Set view mode to edit (modal is now edit-only)
      this.viewMode = 'edit';

      // Re-initialize Lucide icons after the edit form template renders
      requestAnimationFrame(() => initIcons());
    },

    hideEditForm(): void {
      this.formStore.reset();
      this.store.closeEditModal();
    },

    onFormSaved(): void {
      // Reset form and close modal after successful save
      this.formStore.reset();
      releaseFocus();
      this.store.closeEditModal();
    },

    onFormCancelled(): void {
      this.formStore.reset();
      releaseFocus();
      this.store.closeEditModal();
    }
  };
}

/**
 * Initialize the word modal Alpine.js component.
 */
export function initWordModalAlpine(): void {
  Alpine.data('wordModal', wordModalData);
}

// Register the component immediately
initWordModalAlpine();

// Expose for global access
declare global {
  interface Window {
    wordModalData: typeof wordModalData;
  }
}

window.wordModalData = wordModalData;
