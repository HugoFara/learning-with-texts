/**
 * In-app dialogs: confirmation, alert and text entry.
 *
 * Use these rather than `confirm()`, `alert()` and `prompt()`, which stop the
 * whole page, ignore the theme and show a browser-chosen title. The native
 * dialogs remain only as a fallback for when these cannot be built.
 *
 * One dialog shows at a time, and the others wait their turn.
 *
 * The answer comes later, so a handler cannot wait for it and then cancel a
 * link or a form submission: by then the browser has already acted. A form
 * handler must use confirmBeforeSubmit(), which cancels first and resubmits.
 *
 * Choosing a button, pressing Escape or clicking outside settles the promise.
 * Everything except the confirm button counts as declining, and in a
 * confirmation the decline button has the focus, so a stray Enter loses
 * nothing.
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

/** What a dialog shows. */
interface DialogView {
  title?: string;
  message: string;
  confirmLabel: string;
  /** Without one the dialog only acknowledges, like `alert()`. */
  cancelLabel?: string;
  danger?: boolean;
  /** Adds a text field, like `prompt()`. */
  input?: {
    value: string;
    /** Returns what is wrong with the value, or null if it is fine. */
    validate?: (value: string) => string | null;
  };
}

/** How the user left a dialog. */
interface DialogOutcome {
  confirmed: boolean;
  value: string;
}

/** A dialog is on screen. */
let showing = false;

/** Dialogs waiting for the one on screen to close. */
const waiting: Array<() => void> = [];

/** The confirmation on screen, so a second request joins it. */
let pending: Promise<boolean> | null = null;

/**
 * Build a Bulma modal for a view.
 *
 * @param view Dialog content
 *
 * @returns The root element and the parts that take part in the interaction
 */
function buildDialog(view: DialogView): {
  root: HTMLElement;
  background: HTMLElement;
  confirmButton: HTMLButtonElement;
  cancelButton: HTMLButtonElement | null;
  input: HTMLInputElement | null;
  error: HTMLElement | null;
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

  if (view.title) {
    const title = document.createElement('p');
    title.id = 'confirm-dialog-title';
    title.className = 'title is-5 mb-2';
    title.textContent = view.title;
    root.setAttribute('aria-labelledby', title.id);
    body.append(title);
  }

  const message = document.createElement('p');
  message.id = 'confirm-dialog-message';
  message.textContent = view.message;
  body.append(message);

  let input: HTMLInputElement | null = null;
  let error: HTMLElement | null = null;
  if (view.input) {
    input = document.createElement('input');
    input.type = 'text';
    input.className = 'input mt-3';
    input.value = view.input.value;
    input.setAttribute('aria-labelledby', 'confirm-dialog-message');
    error = document.createElement('p');
    error.className = 'help is-danger';
    error.setAttribute('role', 'alert');
    body.append(input, error);
  }

  const footer = document.createElement('footer');
  footer.className = 'modal-card-foot confirm-dialog__actions';

  let cancelButton: HTMLButtonElement | null = null;
  if (view.cancelLabel) {
    cancelButton = document.createElement('button');
    cancelButton.type = 'button';
    cancelButton.className = 'button';
    cancelButton.textContent = view.cancelLabel;
    footer.append(cancelButton);
  }

  const confirmButton = document.createElement('button');
  confirmButton.type = 'button';
  confirmButton.className = view.danger ? 'button is-danger' : 'button is-primary';
  confirmButton.textContent = view.confirmLabel;
  footer.append(confirmButton);

  card.append(body, footer);
  root.append(background, card);

  return { root, background, confirmButton, cancelButton, input, error };
}

/**
 * Put a dialog on screen.
 *
 * @param view Dialog content
 * @param done Called once, with how the user left it
 */
function renderDialog(view: DialogView, done: (outcome: DialogOutcome) => void): void {
  const dialog = buildDialog(view);
  document.body.append(dialog.root);

  const { root, background, confirmButton, cancelButton, input, error } = dialog;
  const previouslyFocused = document.activeElement as HTMLElement | null;
  const focusable = [input, cancelButton, confirmButton]
    .filter((el): el is HTMLInputElement | HTMLButtonElement => el !== null);

  const settle = (confirmed: boolean): void => {
    document.removeEventListener('keydown', onKeydown, true);
    root.remove();
    previouslyFocused?.focus?.();
    done({ confirmed, value: input?.value ?? '' });
  };

  const submit = (): void => {
    const problem = input ? view.input?.validate?.(input.value) : null;
    if (problem && error) {
      error.textContent = problem;
      input?.focus();
      return;
    }
    settle(true);
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
      const index = focusable.indexOf(document.activeElement as HTMLButtonElement);
      focusable[(index + 1) % focusable.length].focus();
    } else if (event.key === 'Enter' && event.target === input) {
      event.preventDefault();
      event.stopImmediatePropagation();
      submit();
    }
  };

  document.addEventListener('keydown', onKeydown, true);
  confirmButton.addEventListener('click', submit);
  cancelButton?.addEventListener('click', () => settle(false));
  background.addEventListener('click', () => settle(false));

  // Opened by pressing Enter, this dialog takes the focus before the browser
  // has finished with the key, and the same press would activate its button.
  const swallowKeypress = (event: Event): void => event.preventDefault();
  document.addEventListener('keypress', swallowKeypress, { capture: true, once: true });
  setTimeout(() => document.removeEventListener('keypress', swallowKeypress, true), 0);

  (input ?? cancelButton ?? confirmButton).focus();
  input?.select();
}

/**
 * Ask through the browser, for when the dialog cannot be built.
 *
 * @param view Dialog content
 *
 * @returns How the user answered
 */
function askNatively(view: DialogView): DialogOutcome {
  const text = view.title ? `${view.title}\n\n${view.message}` : view.message;
  if (view.input) {
    const value = window.prompt(text, view.input.value);
    return { confirmed: value !== null, value: value ?? '' };
  }
  if (view.cancelLabel) {
    return { confirmed: window.confirm(text), value: '' };
  }
  window.alert(text);
  return { confirmed: true, value: '' };
}

/**
 * Show a dialog, after any that is already on screen.
 *
 * @param view Dialog content
 *
 * @returns Promise resolving to how the user left it
 */
function openDialog(view: DialogView): Promise<DialogOutcome> {
  return new Promise((resolve) => {
    const show = (): void => {
      showing = true;
      const done = (outcome: DialogOutcome): void => {
        showing = false;
        resolve(outcome);
        waiting.shift()?.();
      };
      try {
        renderDialog(view, done);
      } catch {
        done(askNatively(view));
      }
    };

    if (showing) {
      waiting.push(show);
    } else {
      show();
    }
  });
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

  pending = openDialog(options).then(({ confirmed }) => {
    pending = null;
    return confirmed;
  });
  return pending;
}

/**
 * Tell the user something, in place of `alert()`.
 *
 * The code after this call runs straight away. Wait for the promise only for
 * what must happen once the user has read it, such as moving the focus.
 *
 * @param message What to say
 * @param title   Heading, if any
 *
 * @returns Promise resolving once the user has dismissed it
 */
export async function showAlert(message: string, title?: string): Promise<void> {
  await openDialog({ title, message, confirmLabel: t('common.dialog.ok') });
}

/**
 * Report a problem to the user.
 *
 * @param message What went wrong
 *
 * @returns Promise resolving once the user has dismissed it
 */
export function showError(message: string): Promise<void> {
  return showAlert(message, t('common.dialog.error'));
}

/**
 * Ask the user for some text, in place of `prompt()`.
 *
 * @param message  What to enter
 * @param options  Heading, starting value, and a check that keeps the dialog
 *                 open and shows what is wrong
 *
 * @returns Promise resolving to the text, or null if the user cancelled
 */
export async function promptDialog(
  message: string,
  options: {
    title?: string;
    value?: string;
    validate?: (value: string) => string | null;
  } = {}
): Promise<string | null> {
  const { confirmed, value } = await openDialog({
    title: options.title,
    message,
    confirmLabel: t('common.dialog.ok'),
    cancelLabel: t('common.cancel'),
    input: { value: options.value ?? '', validate: options.validate }
  });
  return confirmed ? value : null;
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
