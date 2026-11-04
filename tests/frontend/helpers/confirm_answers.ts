/**
 * Answer the real in-app confirmation dialog from a test.
 *
 * The dialog answers later, so a test must wait for it: call answerConfirms()
 * before the action, and `await settle()` after it.
 */
import { afterEach, vi } from 'vitest';

/** Records each dialog's text, for asserting what was asked. */
export const confirmPrompts = vi.fn();

let observer: MutationObserver | null = null;

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

  observer = new MutationObserver(() => {
    const dialog = document.querySelector('.confirm-dialog');
    if (!dialog) return;
    confirmPrompts(dialog.querySelector('#confirm-dialog-message')?.textContent);
    const buttons = dialog.querySelectorAll<HTMLButtonElement>('.confirm-dialog__actions button');
    buttons[answer ? 1 : 0].click();
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
