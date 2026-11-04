/**
 * Answer the real in-app dialogs from a test.
 *
 * The dialog answers later, so a test must wait for it: call answerConfirms()
 * before the action, and `await settle()` after it.
 */
import { afterEach, vi } from 'vitest';

/** Records each dialog's text, for asserting what was asked. */
export const confirmPrompts = vi.fn();

let observer: MutationObserver | null = null;

/** What the next text dialogs are given. */
let promptValues: Array<string | null> = [];

afterEach(() => {
  observer?.disconnect();
  observer = null;
});

/**
 * Answer every confirmation dialog that appears from now on.
 *
 * @param answer true for the confirming button, false for the other one
 *
 * @returns The recorder of what was asked
 */
export function answerConfirms(answer: boolean): typeof confirmPrompts {
  observer?.disconnect();
  confirmPrompts.mockClear();
  promptValues = [];

  observer = new MutationObserver(() => {
    const dialog = document.querySelector('.confirm-dialog');
    if (!dialog) return;
    confirmPrompts(dialog.querySelector('#confirm-dialog-message')?.textContent);
    const buttons = dialog.querySelectorAll<HTMLButtonElement>('.confirm-dialog__actions button');
    const input = dialog.querySelector('input');
    if (input) {
      // A refused value leaves the dialog open, for the next one.
      while (dialog.isConnected) {
        const value = promptValues.shift() ?? null;
        if (value === null) {
          buttons[0].click();
          return;
        }
        input.value = value;
        buttons[1].click();
        const problem = dialog.querySelector('.help.is-danger')?.textContent;
        if (dialog.isConnected && problem) confirmPrompts(problem);
      }
      return;
    }
    // A lone button is an alert, which only acknowledges.
    (buttons[answer ? buttons.length - 1 : 0]).click();
  });
  observer.observe(document.body, { childList: true });

  return confirmPrompts;
}

/**
 * Wait for dialogs to be answered and for what follows them to run.
 */
export async function settle(): Promise<void> {
  for (let i = 0; i < 5; i++) {
    await (vi.isFakeTimers()
      ? Promise.resolve()
      : new Promise((resolve) => setTimeout(resolve, 0)));
  }
}

let submissionListener: ((event: Event) => void) | null = null;

/**
 * Count the form submissions that actually go ahead.
 *
 * Listens last, on the window, and stops the navigation jsdom cannot perform.
 *
 * @returns The submit events that nothing cancelled
 */
export function trackSubmissions(): Event[] {
  if (submissionListener) {
    window.removeEventListener('submit', submissionListener);
  }
  const submissions: Event[] = [];
  submissionListener = (event: Event) => {
    if (!event.defaultPrevented) {
      submissions.push(event);
      event.preventDefault();
    }
  };
  window.addEventListener('submit', submissionListener);
  return submissions;
}

/**
 * Dismiss every alert that appears from now on.
 *
 * @returns The recorder of what was shown
 */
export function dismissAlerts(): typeof confirmPrompts {
  return answerConfirms(true);
}

/**
 * Fill in the text dialogs that appear from now on.
 *
 * @param values What to enter, in order; null cancels
 *
 * @returns The recorder of what was asked
 */
export function answerPrompts(...values: Array<string | null>): typeof confirmPrompts {
  const recorder = answerConfirms(true);
  promptValues = values;
  return recorder;
}
