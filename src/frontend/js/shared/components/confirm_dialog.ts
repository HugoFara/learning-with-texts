/**
 * In-app confirmation dialog.
 *
 * Use this rather than `window.confirm()`, which stops the whole page, ignores
 * the theme and shows a browser-chosen title. The native dialog remains only
 * as a fallback for when this one cannot be built.
 *
 * Choosing either button, pressing Escape or clicking outside settles the
 * promise. Everything except the confirm button counts as declining, and the
 * decline button has the focus, so a stray Enter loses nothing.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.8.1
 */

import { t } from '@shared/i18n/translator';

export interface ConfirmDialogOptions {
  /** The question, shown as the dialog's heading. */
  title: string;
  /** What will happen if the user confirms. */
  message: string;
  /** Label of the confirming button. */
  confirmLabel: string;
  /** Label of the declining button. */
  cancelLabel: string;
  /** Show the confirming button as destructive. */
  danger?: boolean;
}

/** The dialog on screen, so a second request joins it rather than stacking. */
let pending: Promise<boolean> | null = null;

/**
 * Build a Bulma modal with the given text.
 *
 * @param options Dialog text
 *
 * @returns The root element and its two buttons
 */
function buildDialog(options: ConfirmDialogOptions): {
  root: HTMLElement;
  confirmButton: HTMLButtonElement;
  cancelButton: HTMLButtonElement;
  background: HTMLElement;
} {
  const root = document.createElement('div');
  root.className = 'modal is-active confirm-dialog';
  root.setAttribute('role', 'alertdialog');
  root.setAttribute('aria-modal', 'true');
  root.setAttribute('aria-labelledby', 'confirm-dialog-title');
  root.setAttribute('aria-describedby', 'confirm-dialog-message');

  const background = document.createElement('div');
  background.className = 'modal-background';

  const card = document.createElement('div');
  card.className = 'modal-card';

  const body = document.createElement('section');
  body.className = 'modal-card-body';

  const title = document.createElement('p');
  title.id = 'confirm-dialog-title';
  title.className = 'title is-5 mb-2';
  title.textContent = options.title;

  const message = document.createElement('p');
  message.id = 'confirm-dialog-message';
  message.textContent = options.message;

  const footer = document.createElement('footer');
  footer.className = 'modal-card-foot confirm-dialog__actions';

  const cancelButton = document.createElement('button');
  cancelButton.type = 'button';
  cancelButton.className = 'button';
  cancelButton.textContent = options.cancelLabel;

  const confirmButton = document.createElement('button');
  confirmButton.type = 'button';
  confirmButton.className = options.danger ? 'button is-danger' : 'button is-primary';
  confirmButton.textContent = options.confirmLabel;

  body.append(title, message);
  footer.append(cancelButton, confirmButton);
  card.append(body, footer);
  root.append(background, card);

  return { root, confirmButton, cancelButton, background };
}

/**
 * Ask the user to confirm something.
 *
 * @param options Dialog text
 *
 * @returns Promise resolving to true if the user confirmed
 */
export function confirmDialog(options: ConfirmDialogOptions): Promise<boolean> {
  if (pending) return pending;

  let dialog: ReturnType<typeof buildDialog>;
  try {
    dialog = buildDialog(options);
    document.body.append(dialog.root);
  } catch {
    return Promise.resolve(window.confirm(`${options.title}\n\n${options.message}`));
  }

  const { root, confirmButton, cancelButton, background } = dialog;
  const previouslyFocused = document.activeElement as HTMLElement | null;

  pending = new Promise<boolean>((resolve) => {
    const settle = (confirmed: boolean): void => {
      document.removeEventListener('keydown', onKeydown, true);
      root.remove();
      pending = null;
      previouslyFocused?.focus?.();
      resolve(confirmed);
    };

    // Capturing, and stopped here: the dialogs underneath also listen for
    // Escape on the document, and would close behind this one.
    const onKeydown = (event: KeyboardEvent): void => {
      if (event.key === 'Escape') {
        event.preventDefault();
        event.stopImmediatePropagation();
        settle(false);
      } else if (event.key === 'Tab') {
        event.preventDefault();
        event.stopImmediatePropagation();
        (document.activeElement === cancelButton ? confirmButton : cancelButton).focus();
      }
    };

    document.addEventListener('keydown', onKeydown, true);
    confirmButton.addEventListener('click', () => settle(true));
    cancelButton.addEventListener('click', () => settle(false));
    background.addEventListener('click', () => settle(false));
  });

  cancelButton.focus();
  return pending;
}

/**
 * Ask whether to throw away unsaved changes.
 *
 * @returns Promise resolving to true if the changes can be discarded
 */
export function confirmDiscardChanges(): Promise<boolean> {
  return confirmDialog({
    title: t('common.dialog.discard_title'),
    message: t('common.dialog.discard_message'),
    confirmLabel: t('common.dialog.discard'),
    cancelLabel: t('common.dialog.keep_editing'),
    danger: true
  });
}
