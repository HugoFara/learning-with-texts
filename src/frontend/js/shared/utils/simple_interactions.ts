/**
 * Simple Interactions - Common UI patterns for navigation and confirmation.
 *
 * This module handles simple inline event handlers that were previously
 * embedded in PHP templates, including:
 * - Navigation buttons (cancel, back, redirect)
 * - Confirmation dialogs (form submission)
 * - Form dirty state management
 * - Translation actions (add/delete translation)
 * - Text word actions (know all, ignore all)
 *
 * @license unlicense
 * @since   3.0.0
 */

import { t } from '@shared/i18n/translator';
import { confirmAction, confirmBeforeSubmit, confirmDeletion } from '@shared/components/confirm_dialog';
import { lwtFormCheck } from '@shared/forms/unloadformcheck';
import { getCsrfToken } from '@shared/api/client';
import { showAllwordsClick } from './ui_utilities';
import { quickMenuRedirection } from './user_interactions';
import { deleteTranslation, addTranslation } from '@modules/vocabulary/services/translation_api';
import { changeTableTestStatus } from '@modules/vocabulary/services/term_operations';
import { showExportTemplateHelp } from '@shared/components/modal';
import { markWordWellKnownInDOM, markWordIgnoredInDOM } from '@modules/vocabulary/services/word_dom_updates';

interface MarkAllWordsResponse {
  count: number;
  words: Array<{
    wid: number;
    hex: string;
    term: string;
    status: number;
  }>;
}

/**
 * Mark all unknown words in a text as well-known via API.
 *
 * @param textId - The text ID
 * @returns The API response with count and word data
 */
async function markAllWellKnown(textId: string): Promise<MarkAllWordsResponse> {
  const headers: Record<string, string> = { 'Content-Type': 'application/json' };
  const csrf = getCsrfToken();
  if (csrf) {
    headers['X-CSRF-TOKEN'] = csrf;
  }
  const response = await fetch(`/api/v1/texts/${textId}/mark-all-wellknown`, {
    method: 'PUT',
    headers
  });
  if (!response.ok) {
    throw new Error(`Failed to mark words: ${response.statusText}`);
  }
  return response.json();
}

/**
 * Mark all unknown words in a text as ignored via API.
 *
 * @param textId - The text ID
 * @returns The API response with count and word data
 */
async function markAllIgnored(textId: string): Promise<MarkAllWordsResponse> {
  const headers: Record<string, string> = { 'Content-Type': 'application/json' };
  const csrf = getCsrfToken();
  if (csrf) {
    headers['X-CSRF-TOKEN'] = csrf;
  }
  const response = await fetch(`/api/v1/texts/${textId}/mark-all-ignored`, {
    method: 'PUT',
    headers
  });
  if (!response.ok) {
    throw new Error(`Failed to mark words: ${response.statusText}`);
  }
  return response.json();
}

/**
 * Navigate back in browser history.
 */
export function goBack(): void {
  history.back();
}

/**
 * Navigate to a URL.
 *
 * @param url - The URL to navigate to
 */
export function navigateTo(url: string): void {
  location.href = url;
}

/**
 * Reset form dirty state and navigate to a URL.
 * Used for cancel buttons that should not trigger "unsaved changes" warning.
 *
 * @param url - The URL to navigate to
 */
export function cancelAndNavigate(url: string): void {
  lwtFormCheck.resetDirty();
  location.href = url;
}

/**
 * Reset form dirty state and go back in history.
 * Used for "Go Back" buttons that should not trigger "unsaved changes" warning.
 */
export function cancelAndGoBack(): void {
  lwtFormCheck.resetDirty();
  history.back();
}

/**
 * Show a confirmation dialog before form submission.
 *
 * @param message - The confirmation message to display
 * @returns Promise resolving to true if the user confirmed
 */
export function confirmSubmit(message: string = t('common.dialog.are_you_sure')): Promise<boolean> {
  return confirmAction(message);
}

/** Elements whose next click is the confirmed one. */
const confirmedClicks = new WeakSet<HTMLElement>();

/**
 * Initialize simple interaction handlers using data attributes.
 *
 * Supported data attributes:
 * - data-action="cancel-navigate" data-url="..." - Cancel and navigate
 * - data-action="cancel-back" - Cancel and go back
 * - data-action="navigate" data-url="..." - Simple navigation
 * - data-action="back" - Go back in history
 * - data-confirm="message" - Show confirmation before action
 *
 * For forms:
 * - data-confirm-submit="message" - Confirm before form submission
 */
export function initSimpleInteractions(): void {
  // Handle click actions using event delegation
  document.addEventListener('click', (e) => {
    const el = (e.target as HTMLElement).closest<HTMLElement>('[data-action]');
    if (!el) return;

    const action = el.dataset.action;
    const url = el.dataset.url;
    const confirmMsg = el.dataset.confirm;

    // Check for confirmation first. The answer comes too late to let this
    // click through, so cancel it and click again once confirmed.
    if (confirmMsg && !confirmedClicks.has(el)) {
      e.preventDefault();
      e.stopImmediatePropagation();
      void confirmAction(confirmMsg).then((confirmed) => {
        if (confirmed) {
          confirmedClicks.add(el);
          el.click();
          // Clicking runs synchronously, and every listener has now seen it.
          confirmedClicks.delete(el);
        }
      });
      return;
    }

    switch (action) {
    case 'cancel-navigate':
      if (url) {
        e.preventDefault();
        cancelAndNavigate(url);
      }
      break;

    case 'cancel-back':
      e.preventDefault();
      cancelAndGoBack();
      break;

    case 'navigate':
      if (url) {
        e.preventDefault();
        navigateTo(url);
      }
      break;

    case 'back':
      e.preventDefault();
      goBack();
      break;

    case 'confirm-delete':
      // Asks first, then navigates if there is a URL
      e.preventDefault();
      void confirmDeletion().then((confirmed) => {
        if (confirmed && url) {
          navigateTo(url);
        }
      });
      break;

    case 'cancel-form':
      // Cancel and navigate (same as cancel-navigate but more semantic)
      if (url) {
        e.preventDefault();
        cancelAndNavigate(url);
      }
      break;

    case 'hide-right-frames':
      // Legacy action - right frames panel was removed in v3.2.0
      // This action is now a no-op
      e.preventDefault();
      break;

    case 'toggle-show-all':
      // Toggle "Show All" or "Learning Translations" mode
      showAllwordsClick();
      break;

    case 'delete-translation':
      // Clear the translation field
      e.preventDefault();
      deleteTranslation();
      break;

    case 'add-translation':
      // Add a translation word to the field
      e.preventDefault();
      {
        const word = el.dataset.word;
        if (word) {
          addTranslation(word);
        }
      }
      break;

    case 'open-window':
      // Open URL in new window (optionally named via data-window-name)
      e.preventDefault();
      {
        const windowName = el.dataset.windowName;
        const targetUrl = url || (el.tagName === 'A' ? (el as HTMLAnchorElement).href : undefined);
        if (targetUrl) {
          window.open(targetUrl, windowName || '_blank');
        }
      }
      break;

    case 'know-all':
      // Mark all unknown words as well-known
      e.preventDefault();
      {
        const textId = el.dataset.textId;
        if (textId) {
          void confirmAction(t('common.dialog.mark_all_known')).then((confirmed) => {
            if (!confirmed) return;
            el.classList.add('is-loading');
            markAllWellKnown(textId)
              .then(data => {
                data.words.forEach(word => {
                  markWordWellKnownInDOM(word.wid, word.hex, word.term);
                });
              })
              .catch(err => {
                console.error('Failed to mark all as well-known:', err);
                alert('Failed to mark words. Please try again.');
              })
              .finally(() => {
                el.classList.remove('is-loading');
              });
          });
        }
      }
      break;

    case 'ignore-all':
      // Mark all unknown words as ignored
      e.preventDefault();
      {
        const textId = el.dataset.textId;
        if (textId) {
          void confirmAction(t('common.dialog.mark_all_ignored')).then((confirmed) => {
            if (!confirmed) return;
            el.classList.add('is-loading');
            markAllIgnored(textId)
              .then(data => {
                data.words.forEach(word => {
                  markWordIgnoredInDOM(word.wid, word.hex, word.term);
                });
              })
              .catch(err => {
                console.error('Failed to mark all as ignored:', err);
                alert('Failed to mark words. Please try again.');
              })
              .finally(() => {
                el.classList.remove('is-loading');
              });
          });
        }
      }
      break;

    case 'bulk-translate':
      // Open bulk translate page
      e.preventDefault();
      if (url) {
        window.location.href = url;
      }
      break;

    case 'change-test-status':
      // Change word status in test table (plus/minus buttons)
      e.preventDefault();
      {
        const wordId = el.dataset.wordId;
        const direction = el.dataset.direction;
        if (wordId) {
          changeTableTestStatus(wordId, direction === 'up');
        }
      }
      break;

    case 'go-back':
      // Navigate back in browser history
      e.preventDefault();
      history.back();
      break;

    case 'show-export-template-help':
      // Show export template help modal
      e.preventDefault();
      showExportTemplateHelp();
      break;
    }
  });

  // Handle pager navigation (select dropdown)
  document.addEventListener('change', (e) => {
    const target = e.target as HTMLElement;

    // Pager navigation
    if (target.matches('select[data-action="pager-navigate"]')) {
      const select = target as HTMLSelectElement;
      const baseUrl = select.dataset.baseUrl;
      const selectedValue = select.value;
      if (baseUrl && selectedValue) {
        location.href = baseUrl + '?page=' + selectedValue;
      }
      return;
    }

    // Quick menu navigation
    if (target.matches('select[data-action="quick-menu-redirect"]')) {
      quickMenuRedirection((target as HTMLSelectElement).value);
    }
  });

  // Form submission confirmation. Capturing, so that it comes before the
  // form's own handlers: they should not run until the user has confirmed.
  document.addEventListener('submit', (e) => {
    const form = e.target as HTMLFormElement;
    if (form.dataset.confirmSubmit !== undefined) {
      const message = form.dataset.confirmSubmit || t('common.dialog.are_you_sure');
      confirmBeforeSubmit(e, form, () => confirmAction(message, { danger: true }));
    }
  }, true);

  // Handle auto-submit and loading state
  document.addEventListener('submit', (e) => {
    const form = e.target as HTMLFormElement;

    // Forms that auto-submit by clicking a button
    if (form.dataset.autoSubmitButton) {
      e.preventDefault();
      const buttonName = form.dataset.autoSubmitButton;
      const button = form.querySelector<HTMLElement>(`[name="${buttonName}"]`);
      if (button) {
        button.click();
      }
    }

    // Show loading state on submit button
    // Applies to forms with data-loading-submit attribute or any confirmed form
    if (form.dataset.loadingSubmit !== undefined || form.dataset.confirmSubmit !== undefined) {
      const submitButton = form.querySelector<HTMLInputElement | HTMLButtonElement>(
        'input[type="submit"], button[type="submit"]'
      );
      if (submitButton) {
        submitButton.classList.add('is-loading');
        submitButton.disabled = true;
      }
    }
  });
}

// Initialize on document ready
document.addEventListener('DOMContentLoaded', initSimpleInteractions);
