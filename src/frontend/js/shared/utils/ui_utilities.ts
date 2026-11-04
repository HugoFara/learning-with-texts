/**
 * UI Utilities - DOM manipulation, tooltips, and form wrapping
 *
 * @license unlicense
 * @author  andreask7 <andreasks7@users.noreply.github.com>
 * @since   1.6.16-fork
 */

import { confirmDeletion } from '@shared/components/confirm_dialog';
import { check } from '@shared/forms/form_validation';
import { getCsrfToken } from '@shared/api/client';
import { changeImprAnnText, changeImprAnnRadio, showSimilarTerms } from '@modules/vocabulary/services/term_operations';
import { readRawTextAloud } from './user_interactions';
import { initInlineEdit } from '@shared/components/inline_edit';
import { initTermTags, initTextTags } from '@shared/components/tagify_tags';
import { fetchTermTags, fetchTextTags } from '../stores/app_data';
import { spinnerHtml } from '@shared/icons/icons';

/**
 * Helper to safely get an HTML attribute value as a string.
 */
function getAttr(el: HTMLElement, attr: string): string {
  const val = el.getAttribute(attr);
  return typeof val === 'string' ? val : '';
}

/**
 * Enable or disable the mark action button based on checked items.
 * Enables the button if at least one checkbox with class 'markcheck' is checked.
 */
export function markClick(): void {
  const checkedCount = document.querySelectorAll('input.markcheck:checked').length;
  const markAction = document.getElementById('markaction') as HTMLButtonElement | null;
  if (markAction) {
    if (checkedCount > 0) {
      markAction.removeAttribute('disabled');
    } else {
      markAction.setAttribute('disabled', 'disabled');
    }
  }
}

/**
 * Show a confirmation dialog for delete operations.
 *
 * @returns Promise resolving to true if the user confirmed deletion
 */
export function confirmDelete(): Promise<boolean> {
  return confirmDeletion();
}

/**
 * Handle click on confirmdelete elements.
 * Supports data-method="delete" for RESTful DELETE requests.
 *
 * @param event - The click event
 */
async function handleConfirmDelete(event: Event): Promise<void> {
  const el = event.currentTarget as HTMLElement;
  const href = el.getAttribute('href');
  const method = el.getAttribute('data-method');

  // Cancel first: by the time the user answers, the link would be followed.
  event.preventDefault();
  if (!await confirmDelete()) {
    return;
  }

  // If data-method="delete", send DELETE request instead of following link
  if (method?.toLowerCase() === 'delete' && href) {
    try {
      const headers: Record<string, string> = {};
      const csrf = getCsrfToken();
      if (csrf) {
        headers['X-CSRF-TOKEN'] = csrf;
      }
      const response = await fetch(href, { method: 'DELETE', headers });
      if (response.redirected) {
        window.location.href = response.url;
      } else if (response.ok) {
        // Reload page to show updated list
        window.location.reload();
      } else {
        alert('Delete failed: ' + response.statusText);
      }
    } catch (error) {
      alert('Delete failed: ' + (error instanceof Error ? error.message : 'Unknown error'));
    }
  }
  else if (href) {
    window.location.href = href;
  }
}

/**
 * Save a setting via API.
 *
 * @param key - Setting key
 * @param value - Setting value
 */
async function saveSetting(key: string, value: string): Promise<void> {
  const headers: Record<string, string> = { 'Content-Type': 'application/json' };
  const csrf = getCsrfToken();
  if (csrf) {
    headers['X-CSRF-TOKEN'] = csrf;
  }
  const response = await fetch('/api/v1/settings', {
    method: 'POST',
    headers,
    body: JSON.stringify({ key, value })
  });
  if (!response.ok) {
    throw new Error(`Failed to save setting: ${response.statusText}`);
  }
}

/**
 * Enable/disable words hint.
 * Function called when clicking on "Show All" or "Learning Translations".
 * Saves settings via AJAX and reloads the page to apply changes.
 */
export async function showAllwordsClick(): Promise<void> {
  const showAllEl = document.getElementById('showallwords') as HTMLInputElement | null;
  const showLearningEl = document.getElementById('showlearningtranslations') as HTMLInputElement | null;

  const showAll = showAllEl?.checked ? '1' : '0';
  const showLearning = showLearningEl?.checked ? '1' : '0';

  try {
    // Save both settings in parallel
    await Promise.all([
      saveSetting('showallwords', showAll),
      saveSetting('showlearningtranslations', showLearning)
    ]);
    // Reload to apply the new display settings
    window.location.reload();
  } catch (err) {
    console.error('Failed to save display settings:', err);
    alert('Failed to save settings. Please try again.');
    // Revert checkbox states on error
    if (showAllEl) showAllEl.checked = showAll !== '1';
    if (showLearningEl) showLearningEl.checked = showLearning !== '1';
  }
}

/**
 * Slide up animation using CSS transitions.
 * Hides an element by animating its height to 0.
 *
 * @param element The element to animate
 * @param duration Animation duration in milliseconds (default 400)
 * @param callback Optional callback when animation completes
 */
function slideUp(element: HTMLElement, duration = 400, callback?: () => void): void {
  // Set initial height explicitly for transition
  element.style.height = element.offsetHeight + 'px';
  element.style.overflow = 'hidden';
  element.style.transition = `height ${duration}ms ease-out, padding ${duration}ms ease-out, margin ${duration}ms ease-out`;

  // Force reflow to ensure initial height is applied
  void element.offsetHeight;

  // Animate to 0
  element.style.height = '0';
  element.style.paddingTop = '0';
  element.style.paddingBottom = '0';
  element.style.marginTop = '0';
  element.style.marginBottom = '0';

  // Clean up after animation
  setTimeout(() => {
    element.style.display = 'none';
    // Reset inline styles
    element.style.height = '';
    element.style.overflow = '';
    element.style.transition = '';
    element.style.paddingTop = '';
    element.style.paddingBottom = '';
    element.style.marginTop = '';
    element.style.marginBottom = '';
    if (callback) callback();
  }, duration);
}

/**
 * Auto-hide notifications marked with data-auto-hide attribute.
 * Used to automatically dismiss success/info messages after 3 seconds.
 */
export function initAutoHideNotifications(): void {
  // Handle new data-auto-hide attribute on Bulma notifications
  const autoHideElements = document.querySelectorAll<HTMLElement>('[data-auto-hide="true"]');
  autoHideElements.forEach(element => {
    slideUp(element);
  });

  // Legacy: handle old #hide3 element if present
  const legacyElement = document.getElementById('hide3');
  if (legacyElement) {
    slideUp(legacyElement);
  }
}

/**
 * Initialize Bulma notification close buttons.
 * Adds click handler to .delete buttons inside .notification elements.
 */
export function initNotificationCloseButtons(): void {
  document.querySelectorAll('.notification .delete').forEach(button => {
    button.addEventListener('click', () => {
      const notification = button.parentElement;
      if (notification) {
        notification.remove();
      }
    });
  });
}

/**
 * Auto-dismiss messages with the 'hide_message' class.
 * Messages fade out after 2.5 seconds with a 1 second animation.
 */
export function initHideMessages(): void {
  const elements = document.querySelectorAll<HTMLElement>('.hide_message');
  elements.forEach(element => {
    setTimeout(() => {
      slideUp(element, 1000);
    }, 2500);
  });
}

/**
 * Set the focus on an element with the "focus" class.
 */
export function setTheFocus(): void {
  const focusEl = document.querySelector<HTMLElement>('.setfocus');
  if (!focusEl) return;
  focusEl.focus();
  // .select() exists on <input> and <textarea> but not on <select>, <button>, etc.
  if (focusEl instanceof HTMLInputElement || focusEl instanceof HTMLTextAreaElement) {
    focusEl.select();
  }
}

/**
 * Serialize a form into an object with key-value pairs.
 * Replaces jQuery's serializeObject plugin.
 *
 * @param form The form element to serialize
 * @returns Object with form field names as keys and values
 */
export function serializeFormToObject(form: HTMLFormElement): Record<string, unknown> {
  const o: Record<string, unknown> = {};
  const formData = new FormData(form);

  formData.forEach((value, key) => {
    if (o[key] !== undefined) {
      if (!Array.isArray(o[key])) {
        o[key] = [o[key]];
      }
      (o[key] as unknown[]).push(value || '');
    } else {
      o[key] = value || '';
    }
  });

  return o;
}

/**
 * Wrap the radio buttons into stylised elements.
 */
export function wrapRadioButtons(): void {
  let tabIndex = 1;
  const tabElements = document.querySelectorAll<HTMLElement>(
    ':is(input, textarea, .wrap_checkbox span, .wrap_radio span, select, ' +
    '.searchable-select__trigger, button[type="submit"], ' +
    '#mediaselect span.click, #forwbutt, #backbutt), a:not([name^=rec])'
  );
  tabElements.forEach((el) => {
    el.setAttribute('tabindex', String(tabIndex++));
  });

  document.querySelectorAll<HTMLElement>('.wrap_radio span').forEach((span) => {
    span.addEventListener('keydown', function (e) {
      if (e.keyCode === 32) {
        const radioInput = this.closest('label')?.parentElement?.querySelector('input[type=radio]') as HTMLInputElement | null;
        radioInput?.click();
        e.preventDefault();
      }
    });
  });
}

/**
 * Do a lot of different DOM manipulations
 */
export function prepareMainAreas(): void {
  // Initialize inline editing for editable areas
  initInlineEdit('.edit_area', {
    url: '/word/inline-edit',
    tooltip: 'Click to edit...',
    submitText: 'Save',
    cancelText: 'Cancel',
    rows: 3,
    cols: 35,
    indicator: spinnerHtml({ alt: 'Saving...' })
  });

  // Wrap selects
  document.querySelectorAll<HTMLSelectElement>('select').forEach((select) => {
    const label = document.createElement('label');
    label.className = 'wrap_select';
    select.parentNode?.insertBefore(label, select);
    label.appendChild(select);
  });

  // Disable autocomplete on forms
  document.querySelectorAll<HTMLFormElement>('form').forEach((form) => {
    form.setAttribute('autocomplete', 'off');
  });

  // Handle file inputs (skip Bulma file components which have their own styling)
  document.querySelectorAll<HTMLInputElement>('input[type="file"]').forEach((fileInput) => {
    // Skip if already inside a Bulma file component
    if (fileInput.classList.contains('file-input') || fileInput.closest('.file')) {
      return;
    }
    if (fileInput.offsetParent === null) { // Not visible
      const button = document.createElement('button');
      button.className = 'button-file';
      button.textContent = 'Choose File';
      button.type = 'button';

      const fakeFile = document.createElement('span');
      fakeFile.className = 'fakefile';
      fakeFile.style.position = 'relative';

      fileInput.parentNode?.insertBefore(button, fileInput);
      fileInput.parentNode?.insertBefore(fakeFile, fileInput.nextSibling);

      const updateText = () => {
        let txt = fileInput.value.replace('C:\\fakepath\\', '');
        if (txt.length > 85) txt = txt.replace(/.*(.{80})$/, ' ... $1');
        fakeFile.textContent = txt;
      };

      fileInput.addEventListener('change', updateText);
      fileInput.addEventListener('mouseout', updateText);
    }
  });

  // Handle checkboxes
  let cbIndex = 1;
  document.querySelectorAll<HTMLInputElement>('input[type="checkbox"]').forEach((checkbox) => {
    if (!checkbox.id) {
      checkbox.id = 'cb_' + cbIndex++;
    }
    const label = document.createElement('label');
    label.className = 'wrap_checkbox';
    label.setAttribute('for', checkbox.id);
    label.innerHTML = '<span></span>';
    checkbox.parentNode?.insertBefore(label, checkbox.nextSibling);
  });

  // Handle TTS spans
  document.querySelectorAll<HTMLElement>('span[class*="tts_"]').forEach((span) => {
    span.addEventListener('click', function () {
      const classAttr = getAttr(this, 'class');
      const lg = classAttr.replace(/.*tts_([a-zA-Z-]+).*/, '$1');
      const txt = this.textContent || '';
      readRawTextAloud(txt, lg);
    });
  });

  // Blur buttons on mouseup
  document.addEventListener('mouseup', function () {
    document.querySelectorAll<HTMLElement>(
      'button, input[type=button], .wrap_radio span, .wrap_checkbox span'
    ).forEach((el) => {
      (el as HTMLElement).blur();
    });
  });

  // Handle checkbox wrapper keyboard interaction
  document.querySelectorAll<HTMLElement>('.wrap_checkbox span').forEach((span) => {
    span.addEventListener('keydown', function (e) {
      if (e.keyCode === 32) {
        const checkbox = this.closest('label')?.parentElement?.querySelector('input[type=checkbox]') as HTMLInputElement | null;
        checkbox?.click();
        e.preventDefault();
      }
    });
  });

  // Handle radio buttons
  let rbIndex = 1;
  document.querySelectorAll<HTMLInputElement>('input[type="radio"]').forEach((radio) => {
    if (!radio.id) {
      radio.id = 'rb_' + rbIndex++;
    }
    const label = document.createElement('label');
    label.className = 'wrap_radio';
    label.setAttribute('for', radio.id);
    label.innerHTML = '<span></span>';
    radio.parentNode?.insertBefore(label, radio.nextSibling);
  });

  // Handle file button clicks
  document.querySelectorAll<HTMLButtonElement>('.button-file').forEach((button) => {
    button.addEventListener('click', function () {
      const fileInput = this.nextElementSibling as HTMLInputElement | null;
      if (fileInput?.type === 'file') {
        fileInput.click();
      }
      return false;
    });
  });

  // Annotation event handlers
  document.querySelectorAll<HTMLInputElement>('input.impr-ann-text').forEach((input) => {
    input.addEventListener('change', changeImprAnnText);
  });

  document.querySelectorAll<HTMLInputElement>('input.impr-ann-radio').forEach((input) => {
    input.addEventListener('change', changeImprAnnRadio);
  });

  // Form validation
  document.querySelectorAll<HTMLFormElement>('form.validate').forEach((form) => {
    form.addEventListener('submit', check);
  });

  // Mark checkbox clicks
  document.querySelectorAll<HTMLInputElement>('input.markcheck').forEach((input) => {
    input.addEventListener('click', markClick);
  });

  // Confirm delete buttons - supports data-method="delete" for RESTful DELETE requests
  document.querySelectorAll<HTMLElement>('.confirmdelete').forEach((el) => {
    el.addEventListener('click', handleConfirmDelete);
  });

  // Textarea no-return handling
  document.querySelectorAll<HTMLTextAreaElement>('textarea.textarea-noreturn').forEach((textarea) => {
    textarea.addEventListener('keydown', function (e) {
      if (e.keyCode === 13) {
        if (check()) {
          const submitBtn = document.querySelector<HTMLInputElement>('input[type="submit"]:last-of-type');
          submitBtn?.click();
        }
        e.preventDefault();
      }
    });
  });

  // Initialize Tagify for term and text tags
  // Tags are fetched from API asynchronously
  if (document.getElementById('termtags')) {
    fetchTermTags().then(tags => {
      initTermTags(tags);
    });
  }

  if (document.getElementById('texttags')) {
    fetchTextTags().then(tags => {
      initTextTags(tags);
    });
  }

  markClick();
  setTheFocus();

  const simWords = document.getElementById('simwords');
  const langField = document.getElementById('langfield');
  const wordField = document.getElementById('wordfield');
  if (simWords && langField && wordField) {
    wordField.addEventListener('blur', showSimilarTerms);
    showSimilarTerms();
  }

  // Initialize Bulma notification close buttons
  initNotificationCloseButtons();
  // Auto-hide notifications after 3 seconds
  window.setTimeout(initAutoHideNotifications, 3000);
  // Auto-dismiss messages with hide_message class
  initHideMessages();
}

window.addEventListener('load', wrapRadioButtons);

document.addEventListener('DOMContentLoaded', prepareMainAreas);
