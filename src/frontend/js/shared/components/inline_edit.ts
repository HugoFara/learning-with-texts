/**
 * Inline Edit - Click-to-edit functionality for text elements
 *
 * Replaces jQuery Jeditable plugin with a native implementation.
 * Transforms elements into editable textareas on click, with Save/Cancel buttons.
 *
 * @license unlicense
 * @since 3.0.0
 */

import { t } from '@shared/i18n/translator';
import { showError } from '@shared/components/confirm_dialog';
import { spinnerHtml } from '../icons/icons';
import { getCsrfToken } from '@shared/api/client';

export interface InlineEditOptions {
  /** URL to POST the edited value to */
  url: string;
  /** Number of rows for the textarea */
  rows?: number;
  /** Number of columns for the textarea */
  cols?: number;
  /** Tooltip text shown on hover */
  tooltip?: string;
  /** Text for the submit button */
  submitText?: string;
  /** Text for the cancel button */
  cancelText?: string;
  /** HTML to show while saving */
  indicator?: string;
}

interface ActiveEdit {
  element: HTMLElement;
  originalContent: string;
  wrapper: HTMLElement;
}

let activeEdit: ActiveEdit | null = null;

/**
 * Initialize inline editing on elements matching a selector
 *
 * @param selector - CSS selector for editable elements
 * @param options - Configuration options
 */
export function initInlineEdit(
  selector: string,
  options: InlineEditOptions
): void {
  const defaults: Required<InlineEditOptions> = {
    url: '',
    rows: 3,
    cols: 35,
    tooltip: 'Click to edit...',
    submitText: 'Save',
    cancelText: 'Cancel',
    indicator: spinnerHtml({ alt: 'Saving...' })
  };

  const config = { ...defaults, ...options };

  // Use event delegation on document for dynamically added elements
  document.addEventListener('click', (e) => {
    const target = e.target as HTMLElement;
    const editElement = target.closest(selector) as HTMLElement | null;

    if (editElement && !isEditing(editElement)) {
      e.preventDefault();
      startEdit(editElement, config);
    }
  });

  // Add tooltip to existing elements
  document.querySelectorAll<HTMLElement>(selector).forEach((el) => {
    el.title = config.tooltip;
  });

  // Also observe for new elements to add tooltips
  const observer = new MutationObserver((mutations) => {
    mutations.forEach((mutation) => {
      mutation.addedNodes.forEach((node) => {
        if (node instanceof HTMLElement) {
          if (node.matches(selector)) {
            node.title = config.tooltip;
          }
          node.querySelectorAll<HTMLElement>(selector).forEach((el) => {
            el.title = config.tooltip;
          });
        }
      });
    });
  });

  observer.observe(document.body, { childList: true, subtree: true });
}

/**
 * Check if an element is currently being edited
 */
function isEditing(element: HTMLElement): boolean {
  return element.querySelector('.inline-edit-wrapper') !== null ||
         element.classList.contains('inline-edit-active');
}

/**
 * Start editing an element
 */
function startEdit(element: HTMLElement, config: Required<InlineEditOptions>): void {
  // Cancel any existing edit
  if (activeEdit) {
    cancelEdit(activeEdit);
  }

  const originalContent = element.textContent || '';
  element.classList.add('inline-edit-active');

  // Create editing UI
  const wrapper = document.createElement('div');
  wrapper.className = 'inline-edit-wrapper';

  const textarea = document.createElement('textarea');
  textarea.className = 'inline-edit-textarea';
  textarea.rows = config.rows;
  textarea.cols = config.cols;
  textarea.value = originalContent === '*' ? '' : originalContent;

  const buttonContainer = document.createElement('div');
  buttonContainer.className = 'inline-edit-buttons';

  const saveBtn = document.createElement('button');
  saveBtn.type = 'button';
  saveBtn.className = 'inline-edit-save';
  saveBtn.textContent = config.submitText;

  const cancelBtn = document.createElement('button');
  cancelBtn.type = 'button';
  cancelBtn.className = 'inline-edit-cancel';
  cancelBtn.textContent = config.cancelText;

  buttonContainer.appendChild(saveBtn);
  buttonContainer.appendChild(cancelBtn);
  wrapper.appendChild(textarea);
  wrapper.appendChild(buttonContainer);

  // Store original content and replace
  element.textContent = '';
  element.appendChild(wrapper);

  activeEdit = { element, originalContent, wrapper };

  // Focus the textarea
  textarea.focus();
  textarea.select();

  // Event handlers
  saveBtn.addEventListener('click', () => {
    saveEdit(activeEdit!, textarea.value, config);
  });

  cancelBtn.addEventListener('click', () => {
    cancelEdit(activeEdit!);
  });

  // Handle keyboard shortcuts
  textarea.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      e.preventDefault();
      cancelEdit(activeEdit!);
    } else if (e.key === 'Enter' && e.ctrlKey) {
      e.preventDefault();
      saveEdit(activeEdit!, textarea.value, config);
    }
  });
}

/**
 * Save the edited value
 */
async function saveEdit(
  edit: ActiveEdit,
  newValue: string,
  config: Required<InlineEditOptions>
): Promise<void> {
  const { element, wrapper } = edit;
  const id = element.id;

  // Show loading indicator
  const textarea = wrapper.querySelector('textarea')!;
  const buttons = wrapper.querySelector('.inline-edit-buttons')!;
  textarea.disabled = true;
  buttons.innerHTML = config.indicator;

  try {
    const formData = new FormData();
    formData.append('id', id);
    formData.append('value', newValue.trim());
    const csrf = getCsrfToken();
    if (csrf) {
      formData.append('_csrf_token', csrf);
    }

    const response = await fetch(config.url, {
      method: 'POST',
      body: formData
    });

    if (!response.ok) {
      throw new Error(`HTTP error: ${response.status}`);
    }

    const resultText = await response.text();

    // Update element with new value from server
    element.classList.remove('inline-edit-active');
    element.textContent = resultText;
    element.title = config.tooltip;
    activeEdit = null;
  } catch (error) {
    console.error('Inline edit save failed:', error);
    // Restore editing state on error
    textarea.disabled = false;
    buttons.innerHTML = '';
    const saveBtn = document.createElement('button');
    saveBtn.type = 'button';
    saveBtn.className = 'inline-edit-save';
    saveBtn.textContent = config.submitText;
    const cancelBtn = document.createElement('button');
    cancelBtn.type = 'button';
    cancelBtn.className = 'inline-edit-cancel';
    cancelBtn.textContent = config.cancelText;
    buttons.appendChild(saveBtn);
    buttons.appendChild(cancelBtn);

    saveBtn.addEventListener('click', () => {
      saveEdit(edit, textarea.value, config);
    });
    cancelBtn.addEventListener('click', () => {
      cancelEdit(edit);
    });

    void showError(t('common.dialog.save_failed'));
  }
}

/**
 * Cancel editing and restore original content
 */
function cancelEdit(edit: ActiveEdit): void {
  const { element, originalContent } = edit;
  element.classList.remove('inline-edit-active');
  element.textContent = originalContent;
  activeEdit = null;
}

