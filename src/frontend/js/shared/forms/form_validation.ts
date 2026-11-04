/**
 * Form Validation - Input validation and form checking utilities
 *
 * @license unlicense
 * @author  andreask7 <andreasks7@users.noreply.github.com>
 * @since   1.6.16-fork
 */

import { showError } from '@shared/components/confirm_dialog';
import { t } from '@shared/i18n/translator';

/**
 * Helper to safely get an HTML attribute value as a string.
 *
 * @param el HTML element to get attribute from
 * @param attr Name of the attribute to retrieve
 * @returns Attribute value as string, or empty string if null
 */
function getAttr(el: HTMLElement, attr: string): string {
  return el.getAttribute(attr) || '';
}

/**
 * Helper to safely get element value as a string.
 *
 * @param el HTML input/textarea element to get value from
 * @returns Element value as string, or empty string if undefined
 */
function getVal(el: HTMLInputElement | HTMLTextAreaElement): string {
  return el.value || '';
}

/**
 * Return whether characters are outside the multilingual plane.
 *
 * @param s Input string
 * @returns true is some characters are outside the plane
 */
export function containsCharacterOutsideBasicMultilingualPlane(s: string): boolean {
  return /[\uD800-\uDFFF]/.test(s);
}

/**
 * Alert if characters are outside the multilingual plane.
 *
 * @param s Input string
 * @param info Info about the field
 * @returns 1 if characters are outside the plane, 0 otherwise
 */
export function alertFirstCharacterOutsideBasicMultilingualPlane(s: string, info: string): number {
  if (!containsCharacterOutsideBasicMultilingualPlane(s)) {
    return 0;
  }
  const match = /[\uD800-\uDFFF]/.exec(s);
  if (!match) return 0;
  void showError(t('common.dialog.field_unsupported_char', {
    field: info,
    char: s.substring(match.index, match.index + 2),
    position: match.index + 1
  }));
  return 1;
}

/**
 * Return the memory size of an UTF8 string.
 *
 * @param s String to evaluate
 * @returns Size in bytes
 */
export function getUTF8Length(s: string): number {
  return (new Blob([String(s)])).size;
}

/**
 * Check if a string represents a valid integer.
 *
 * @param value String value to check
 * @returns true if the value is a valid integer, false otherwise
 */
export function isInt(value: string): boolean {
  for (let i = 0; i < value.length; i++) {
    if ((value.charAt(i) < '0') || (value.charAt(i) > '9')) {
      return false;
    }
  }
  return true;
}

/**
 * Check if there is no problem with the text.
 *
 * @returns true if all checks were successfull
 */
function isElementHidden(el: HTMLElement): boolean {
  let cur: HTMLElement | null = el;
  while (cur) {
    if (cur.style.display === 'none') return true;
    if (cur.hasAttribute('hidden')) return true;
    cur = cur.parentElement;
  }
  return false;
}

export function check(): boolean {
  let count = 0;

  // Check non-empty fields. Skip elements whose ancestor chain is hidden via
  // x-show / display:none — e.g. on /texts/new the TxText textarea is hidden
  // when the user is importing a file, so requiring text content there is a
  // false positive.
  document.querySelectorAll<HTMLInputElement | HTMLTextAreaElement>('.notempty').forEach(el => {
    if (isElementHidden(el)) return;
    if (el.value.trim() === '') count++;
  });
  if (count > 0) {
    void showError(t('common.dialog.fields_empty', { count }));
    return false;
  }

  count = 0;

  // Check URL fields
  document.querySelectorAll<HTMLInputElement>('input.checkurl').forEach(el => {
    const val = el.value.trim();
    if (val.length > 0) {
      if ((val.indexOf('http://') !== 0) &&
          (val.indexOf('https://') !== 0) &&
          (val.indexOf('#') !== 0)) {
        void showError(t('common.dialog.field_url', { field: el.getAttribute('data_info') ?? '' }));
        count++;
      }
    }
  });

  // Note: as of LWT 2.9.0, no field with "checkregexp" property is found in the code base
  document.querySelectorAll<HTMLInputElement>('input.checkregexp').forEach(el => {
    const regexp = el.value.trim();
    if (regexp.length > 0) {
      // Synchronous XHR (deprecated but preserved for backwards compatibility)
      const xhr = new XMLHttpRequest();
      xhr.open('POST', 'inc/ajax.php', false);
      xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
      xhr.send('action=&action_type=check_regexp&regex=' + encodeURIComponent(regexp));
      if (xhr.responseText !== '') {
        void showError(xhr.responseText);
        count++;
      }
    }
  });

  // To enable limits of custom feed texts/articl.
  // change the following «input[class*="max_int_"]» into «input[class*="maxint_"]»
  document.querySelectorAll<HTMLInputElement>('input[class*="max_int_"]').forEach(el => {
    const classAttr = el.getAttribute('class') || '';
    const maxvalue = parseInt(classAttr.replace(/.*maxint_([0-9]+).*/, '$1'), 10);
    const val = el.value.trim();
    if (val.length > 0) {
      if (parseInt(val, 10) > maxvalue) {
        void showError(t('common.dialog.field_max', {
          field: el.getAttribute('data_info') ?? '',
          max: maxvalue
        }));
        count++;
      }
    }
  });

  // Check that the Google Translate field is of good type
  document.querySelectorAll<HTMLInputElement>('input.checkdicturl').forEach(el => {
    const translate_input = el.value.trim();
    if (translate_input.length > 0) {
      let refinned = translate_input;
      if (translate_input.startsWith('*')) {
        refinned = translate_input.substring(1);
      }
      if (!/^https?:\/\//.test(refinned)) {
        refinned = 'http://' + refinned;
      }
      try {
        new URL(refinned);
      } catch (err) {
        if (err instanceof TypeError) {
          void showError(t('common.dialog.field_dict_url', { field: el.getAttribute('data_info') ?? '' }));
          count++;
        }
      }
    }
  });

  // Check positive integer fields
  document.querySelectorAll<HTMLInputElement>('input.posintnumber').forEach(el => {
    const val = el.value.trim();
    if (val.length > 0) {
      if (!(isInt(val) && (parseInt(val, 10) > 0))) {
        void showError(t('common.dialog.field_positive', { field: el.getAttribute('data_info') ?? '' }));
        count++;
      }
    }
  });

  // Check zero or positive integer fields
  document.querySelectorAll<HTMLInputElement>('input.zeroposintnumber').forEach(el => {
    const val = el.value.trim();
    if (val.length > 0) {
      if (!(isInt(val) && (parseInt(val, 10) >= 0))) {
        void showError(t('common.dialog.field_nonnegative', { field: el.getAttribute('data_info') ?? '' }));
        count++;
      }
    }
  });

  // Check input fields for characters outside BMP
  document.querySelectorAll<HTMLInputElement>('input.checkoutsidebmp').forEach(el => {
    const val = getVal(el);
    if (val.trim().length > 0) {
      if (containsCharacterOutsideBasicMultilingualPlane(val)) {
        count += alertFirstCharacterOutsideBasicMultilingualPlane(
          val, getAttr(el, 'data_info')
        );
      }
    }
  });

  // Check textarea length
  document.querySelectorAll<HTMLTextAreaElement>('textarea.checklength').forEach(el => {
    const maxLength = parseInt(getAttr(el, 'data_maxlength') || '0', 10);
    const val = getVal(el);
    if (val.trim().length > maxLength) {
      void showError(t('common.dialog.field_too_long_chars', {
        field: getAttr(el, 'data_info'),
        max: getAttr(el, 'data_maxlength')
      }));
      count++;
    }
  });

  // Check textarea for characters outside BMP
  document.querySelectorAll<HTMLTextAreaElement>('textarea.checkoutsidebmp').forEach(el => {
    const val = getVal(el);
    if (containsCharacterOutsideBasicMultilingualPlane(val)) {
      count += alertFirstCharacterOutsideBasicMultilingualPlane(
        val, getAttr(el, 'data_info')
      );
    }
  });

  // Check textarea byte length
  document.querySelectorAll<HTMLTextAreaElement>('textarea.checkbytes').forEach(el => {
    const maxLength = parseInt(getAttr(el, 'data_maxlength') || '0', 10);
    const val = getVal(el);
    if (getUTF8Length(val.trim()) > maxLength) {
      void showError(t('common.dialog.field_too_long_bytes', {
        field: getAttr(el, 'data_info'),
        max: getAttr(el, 'data_maxlength')
      }));
      count++;
    }
  });

  // Check for spaces or commas
  document.querySelectorAll<HTMLInputElement>('input.noblanksnocomma').forEach(el => {
    const val = el.value;
    if (val.indexOf(' ') > 0 || val.indexOf(',') > 0) {
      void showError(t('common.dialog.field_no_spaces', { field: el.getAttribute('data_info') ?? '' }));
      count++;
    }
  });

  return (count === 0);
}

/**
 * Handle Enter key press in textareas to trigger form submission.
 *
 * @param event Keyboard event
 * @returns false to prevent default behavior if Enter was pressed and form is valid, true otherwise
 */
export function textareaKeydown(event: KeyboardEvent): boolean {
  if (event.key === 'Enter' || event.keyCode === 13) {
    if (check()) {
      const submitButtons = document.querySelectorAll<HTMLInputElement>('input[type="submit"]');
      const lastSubmit = submitButtons[submitButtons.length - 1];
      if (lastSubmit) {
        lastSubmit.click();
      }
    }
    return false;
  } else {
    return true;
  }
}

