/**
 * Write to language form fields that are bound with Alpine's `x-model`.
 *
 * `x-model` reads from the DOM only when an input or change event fires.
 * Assigning `.value` alone updates what the user sees but leaves the form's
 * state holding the old value, so the write is lost the moment the form saves.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.8.1
 */

/**
 * Set a bound field's value and tell Alpine about it.
 *
 * @param input Field to write to, if it exists
 * @param value Value to set
 */
export function setBoundValue(
  input: HTMLInputElement | HTMLSelectElement | null,
  value: string
): void {
  if (!input) return;
  input.value = value;
  input.dispatchEvent(new Event('input', { bubbles: true }));
  // Alpine binds a <select> on change, not input
  input.dispatchEvent(new Event('change', { bubbles: true }));
}

/**
 * Check or uncheck a bound checkbox and tell Alpine about it.
 *
 * @param input   Checkbox to write to, if it exists
 * @param checked Desired state
 */
export function setBoundChecked(input: HTMLInputElement | null, checked: boolean): void {
  if (!input) return;
  input.checked = checked;
  input.dispatchEvent(new Event('change', { bubbles: true }));
}
