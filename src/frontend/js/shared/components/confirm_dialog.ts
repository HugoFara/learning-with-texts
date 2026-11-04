/**
 * In-app confirmation dialog.
 *
 * Use this rather than `window.confirm()`, which stops the whole page, ignores
 * the theme and shows a browser-chosen title. The native dialog remains only
 * as a fallback for when this one cannot be built.
 *
 * The answer comes later, so a handler cannot wait for it and then cancel a
 * link or a form submission: by then the browser has already acted. A form
 * handler must use confirmBeforeSubmit(), which cancels first and resubmits.
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
  /** Heading, for when the message needs one. */
  title?: string;
  /** The question, or what will happen if the user confirms. */
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
  root.setAttribute('aria-describedby', 'confirm-dialog-message');

  const background = document.createElement('div');
  background.className = 'modal-background';

  const card = document.createElement('div');
  card.className = 'modal-card';

  const body = document.createElement('section');
  body.className = 'modal-card-body';

  if (options.title) {
    const title = document.createElement('p');
    title.id = 'confirm-dialog-title';
    title.className = 'title is-5 mb-2';
    title.textContent = options.title;
    root.setAttribute('aria-labelledby', title.id);
    body.append(title);
  }

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

  body.append(message);
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
    const text = options.title ? `${options.title}\n\n${options.message}` : options.message;
    return Promise.resolve(window.confirm(text));
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

/**
 * Ask the user to confirm an action, with the usual buttons.
 *
 * @param message The question
 * @param options Anything to override
 *
 * @returns Promise resolving to true if the user confirmed
 */
export function confirmAction(
  message: string,
  options: Partial<ConfirmDialogOptions> = {}
): Promise<boolean> {
  return confirmDialog({
    message,
    confirmLabel: t('common.dialog.confirm'),
    cancelLabel: t('common.cancel'),
    ...options
  });
}

/**
 * Ask the user to confirm a deletion.
 *
 * @param message The question; defaults to a generic one
 *
 * @returns Promise resolving to true if the user confirmed
 */
export function confirmDeletion(message: string = t('common.confirm_delete')): Promise<boolean> {
  return confirmAction(message, { confirmLabel: t('common.delete'), danger: true });
}

/** Forms whose next submit event is the confirmed one. */
const confirmedForms = new WeakSet<HTMLFormElement>();

/**
 * Hold a form submission until the user confirms it.
 *
 * Call this first thing in a submit handler, and carry on only if it returns
 * true. The first time it cancels the submission and returns false. If the
 * user confirms, it submits the form again, with the same submit button, and
 * this time it returns true.
 *
 * @param event   The submit event
 * @param form    The form
 * @param ask     Asks the user
 *
 * @returns Whether this submission has been confirmed
 */
export function confirmBeforeSubmit(
  event: Event,
  form: HTMLFormElement,
  ask: () => Promise<boolean>
): boolean {
  if (confirmedForms.has(form)) {
    return true;
  }

  event.preventDefault();
  event.stopImmediatePropagation();
  const submitter = (event as SubmitEvent).submitter ?? undefined;

  void ask().then((confirmed) => {
    if (!confirmed) return;
    confirmedForms.add(form);
    form.requestSubmit(submitter);
    // Submitting runs synchronously. If validation stopped it, the next
    // attempt must ask again.
    confirmedForms.delete(form);
  });
  return false;
}
