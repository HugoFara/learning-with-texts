/**
 * Table Management - Alpine.js component for table set management.
 *
 * Handles table set selection, creation, and deletion with
 * reactive validation and confirmation dialogs.
 *
 * @author  HugoFara <git@hugofara.net>
 * @license Unlicense <http://unlicense.org/>
 * @since   3.0.0
 */

import { t } from '@shared/i18n/translator';
import { confirmBeforeSubmit, confirmDeletion } from '@shared/components/confirm_dialog';
import Alpine from 'alpinejs';

interface TableManagementState {
  selectedPrefix: string;
  newPrefix: string;
  createError: string | null;
  deletePrefix: string;
  confirmDelete: boolean;
  validatePrefix(): boolean;
  submitCreate(event: Event): void;
  submitDelete(event: Event): void;
}

/**
 * Validate table prefix value.
 *
 * @param value The prefix value to validate
 * @returns Error message or null if valid
 */
function getValidationError(value: string): string | null {
  const trimmed = value.trim();
  if (!trimmed) {
    return 'Table Set Name must not be empty';
  }
  if (!/^[a-zA-Z0-9_]+$/.test(trimmed)) {
    return 'Only letters, numbers, and underscores allowed';
  }
  if (trimmed.length > 20) {
    return 'Maximum 20 characters';
  }
  return null;
}

/**
 * Alpine.js data component for the table management page.
 * Manages table set operations with validation and confirmations.
 */
export function tableManagementApp(): TableManagementState {
  return {
    selectedPrefix: '-',
    newPrefix: '',
    createError: null,
    deletePrefix: '-',
    confirmDelete: false,

    /**
     * Validate the new prefix input and update error state.
     * @returns true if valid
     */
    validatePrefix(): boolean {
      this.createError = getValidationError(this.newPrefix);
      return this.createError === null;
    },

    /**
     * Handle create form submission.
     * Prevents submission if validation fails.
     */
    submitCreate(event: Event): void {
      if (!this.validatePrefix()) {
        event.preventDefault();
      }
    },

    /**
     * Handle delete form submission.
     * Shows confirmation dialog and prevents if not confirmed.
     */
    submitDelete(event: Event): void {
      if (this.deletePrefix === '-' || !this.confirmDelete) {
        event.preventDefault();
        return;
      }

      confirmBeforeSubmit(event, event.target as HTMLFormElement, () =>
        confirmDeletion(t('common.dialog.delete_table_set', { name: this.deletePrefix }))
      );
    }
  };
}

/**
 * Register the Alpine.js component.
 */
export function initTableManagementAlpine(): void {
  Alpine.data('tableManagementApp', tableManagementApp);
}

// Auto-register before Alpine.start() is called
initTableManagementAlpine();

// Export to window for potential external use
declare global {
  interface Window {
    tableManagementApp: typeof tableManagementApp;
  }
}

window.tableManagementApp = tableManagementApp;
