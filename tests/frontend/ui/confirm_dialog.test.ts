/**
 * Tests for confirm_dialog.ts - in-app confirmation dialog
 */
import { describe, it, expect, afterEach, vi } from 'vitest';
import { confirmBeforeSubmit, confirmDialog, promptDialog, showAlert, showError } from '../../../src/frontend/js/shared/components/confirm_dialog';
import { settle, trackSubmissions } from '../helpers/confirm_answers';
import { readdirSync, readFileSync, statSync } from 'fs';
import { join } from 'path';

const options = {
  title: 'Discard your changes?',
  message: 'They have not been saved.',
  confirmLabel: 'Discard',
  cancelLabel: 'Keep editing',
  danger: true
};

function button(label: string): HTMLButtonElement {
  const found = Array.from(document.querySelectorAll('button'))
    .find((el) => el.textContent === label);
  if (!found) throw new Error(`No button "${label}"`);
  return found;
}

describe('confirm_dialog.ts', () => {
  afterEach(() => {
    document.querySelector('.confirm-dialog [class*="is-danger"]')
      ?.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    document.body.innerHTML = '';
    vi.restoreAllMocks();
  });

  it('shows the text as a modal alert, without the native dialog', () => {
    const native = vi.spyOn(window, 'confirm');

    confirmDialog(options);

    const dialog = document.querySelector('.confirm-dialog');
    expect(dialog?.getAttribute('role')).toBe('alertdialog');
    expect(dialog?.getAttribute('aria-modal')).toBe('true');
    expect(dialog?.textContent).toContain('They have not been saved.');
    expect(native).not.toHaveBeenCalled();
  });

  it('resolves true on the confirm button and removes itself', async () => {
    const answer = confirmDialog(options);

    button('Discard').click();

    await expect(answer).resolves.toBe(true);
    expect(document.querySelector('.confirm-dialog')).toBeNull();
  });

  it('resolves false on the cancel button', async () => {
    const answer = confirmDialog(options);

    button('Keep editing').click();

    await expect(answer).resolves.toBe(false);
  });

  it('resolves false on a click outside the card', async () => {
    const answer = confirmDialog(options);

    document.querySelector<HTMLElement>('.confirm-dialog .modal-background')?.click();

    await expect(answer).resolves.toBe(false);
  });

  it('takes Escape for itself, so the dialog underneath stays open', async () => {
    const underneath = vi.fn();
    document.addEventListener('keydown', underneath);
    const answer = confirmDialog(options);

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));

    await expect(answer).resolves.toBe(false);
    expect(underneath).not.toHaveBeenCalled();
    document.removeEventListener('keydown', underneath);
  });

  it('focuses the safe choice, keeps Tab inside, and restores focus', async () => {
    const input = document.createElement('input');
    document.body.append(input);
    input.focus();

    const answer = confirmDialog(options);
    expect(document.activeElement).toBe(button('Keep editing'));

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', bubbles: true }));
    expect(document.activeElement).toBe(button('Discard'));

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', bubbles: true }));
    expect(document.activeElement).toBe(button('Keep editing'));

    button('Keep editing').click();
    await answer;
    expect(document.activeElement).toBe(input);
  });

  it('joins a dialog already on screen rather than stacking another', () => {
    const first = confirmDialog(options);
    const second = confirmDialog(options);

    expect(second).toBe(first);
    expect(document.querySelectorAll('.confirm-dialog')).toHaveLength(1);
  });

  it('shows only the message when there is no title', () => {
    confirmDialog({ ...options, title: undefined });

    expect(document.querySelector('#confirm-dialog-title')).toBeNull();
    expect(document.querySelector('.confirm-dialog')?.getAttribute('aria-labelledby')).toBeNull();
    expect(document.querySelector('.confirm-dialog')?.textContent).toContain('They have not been saved.');
  });

  describe('confirmBeforeSubmit', () => {
    function formWithHandler(ask: () => Promise<boolean>): {
      form: HTMLFormElement;
      after: ReturnType<typeof vi.fn>;
    } {
      document.body.innerHTML = `
        <form>
          <input name="required" required value="filled" />
          <button type="submit" name="op" value="other">Other</button>
          <button type="submit" name="op" value="restore" id="restore">Restore</button>
        </form>
      `;
      const form = document.querySelector('form') as HTMLFormElement;
      const after = vi.fn();
      form.addEventListener('submit', (event) => {
        if (confirmBeforeSubmit(event, form, ask)) after();
      });
      return { form, after };
    }

    it('holds the submission until the user answers', async () => {
      const submissions = trackSubmissions();
      let answer: (confirmed: boolean) => void = () => undefined;
      const { form, after } = formWithHandler(() => new Promise((resolve) => { answer = resolve; }));

      form.requestSubmit();
      await settle();

      expect(submissions).toHaveLength(0);
      expect(after).not.toHaveBeenCalled();

      answer(true);
      await settle();

      expect(submissions).toHaveLength(1);
      expect(after).toHaveBeenCalledOnce();
    });

    it('never submits when declined', async () => {
      const submissions = trackSubmissions();
      const { form, after } = formWithHandler(() => Promise.resolve(false));

      form.requestSubmit();
      await settle();

      expect(submissions).toHaveLength(0);
      expect(after).not.toHaveBeenCalled();
    });

    it('keeps the button that was pressed', async () => {
      const { form } = formWithHandler(() => Promise.resolve(true));
      const restore = document.getElementById('restore') as HTMLButtonElement;
      const resubmit = vi.spyOn(form, 'requestSubmit').mockImplementation(() => undefined);
      const event = new Event('submit', { bubbles: true, cancelable: true });
      Object.defineProperty(event, 'submitter', { value: restore });

      form.dispatchEvent(event);
      await settle();

      expect(resubmit).toHaveBeenCalledWith(restore);
    });

    it('asks again each time', async () => {
      const ask = vi.fn().mockResolvedValue(true);
      const { form } = formWithHandler(ask);
      vi.spyOn(form, 'requestSubmit').mockImplementation(() => undefined);
      const submit = () => form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));

      submit();
      await settle();
      submit();
      await settle();

      expect(ask).toHaveBeenCalledTimes(2);
    });

    it('asks again after the browser refused the confirmed submission', async () => {
      const ask = vi.fn().mockResolvedValue(true);
      const { form } = formWithHandler(ask);
      // A failed validation fires no submit event.
      vi.spyOn(form, 'requestSubmit').mockImplementation(() => undefined);
      const submit = () => form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));

      submit();
      await settle();
      submit();
      await settle();

      expect(ask).toHaveBeenCalledTimes(2);
    });
  });

  describe('showAlert', () => {
    it('shows one button and resolves when dismissed', async () => {
      const closed = showAlert('Saved.');

      const buttons = document.querySelectorAll('.confirm-dialog button');
      expect(buttons).toHaveLength(1);
      expect(document.activeElement).toBe(buttons[0]);

      (buttons[0] as HTMLButtonElement).click();

      await closed;
      expect(document.querySelector('.confirm-dialog')).toBeNull();
    });

    it('resolves on Escape', async () => {
      const closed = showAlert('Saved.');

      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));

      await expect(closed).resolves.toBeUndefined();
    });

    it('gives errors a heading', () => {
      void showError('It broke.');

      expect(document.querySelector('#confirm-dialog-title')?.textContent).toBe('Error');
      button('OK').click();
    });

    it('is not dismissed by the Enter press that opened it', async () => {
      const closed = vi.fn();
      void showAlert('Saved.').then(closed);

      // The browser sends keypress after keydown, to whatever now has focus.
      const press = new KeyboardEvent('keypress', { key: 'Enter', bubbles: true, cancelable: true });
      document.activeElement?.dispatchEvent(press);

      expect(press.defaultPrevented).toBe(true);
      await settle();
      expect(closed).not.toHaveBeenCalled();
      button('OK').click();
    });

    it('lets a later Enter press through', async () => {
      void showAlert('Saved.');
      await settle();

      const press = new KeyboardEvent('keypress', { key: 'Enter', bubbles: true, cancelable: true });
      document.activeElement?.dispatchEvent(press);

      expect(press.defaultPrevented).toBe(false);
      button('OK').click();
    });
  });

  describe('one dialog at a time', () => {
    it('shows queued alerts in order, so none is lost', async () => {
      const first = showAlert('First');
      const second = showAlert('Second');

      expect(document.querySelectorAll('.confirm-dialog')).toHaveLength(1);
      expect(document.querySelector('.confirm-dialog')?.textContent).toContain('First');

      button('OK').click();
      await first;

      expect(document.querySelector('.confirm-dialog')?.textContent).toContain('Second');
      button('OK').click();
      await second;
    });

    it('does not let an alert answer a confirmation', async () => {
      const alerted = showAlert('Heads up');
      const answer = confirmDialog(options);

      button('OK').click();
      await alerted;
      button('Discard').click();

      await expect(answer).resolves.toBe(true);
    });
  });

  describe('promptDialog', () => {
    function field(): HTMLInputElement {
      return document.querySelector('.confirm-dialog input') as HTMLInputElement;
    }

    it('focuses the field and returns what was typed', async () => {
      const answer = promptDialog('Enter tag:', { value: 'start' });

      expect(document.activeElement).toBe(field());
      expect(field().value).toBe('start');

      field().value = 'mytag';
      button('OK').click();

      await expect(answer).resolves.toBe('mytag');
    });

    it('submits on Enter', async () => {
      const answer = promptDialog('Enter tag:');

      field().value = 'mytag';
      field().dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));

      await expect(answer).resolves.toBe('mytag');
    });

    it('returns null when cancelled', async () => {
      const answer = promptDialog('Enter tag:');

      button('Cancel').click();

      await expect(answer).resolves.toBeNull();
    });

    it('keeps the dialog open and says what is wrong', async () => {
      const answer = promptDialog('Enter tag:', {
        validate: (value) => (value.includes(' ') ? 'No spaces.' : null)
      });

      field().value = 'two words';
      button('OK').click();

      expect(document.querySelector('.confirm-dialog .help')?.textContent).toBe('No spaces.');
      expect(document.activeElement).toBe(field());

      field().value = 'oneword';
      button('OK').click();

      await expect(answer).resolves.toBe('oneword');
    });

    it('keeps Tab inside, through the field and both buttons', () => {
      void promptDialog('Enter tag:');
      const tab = () => document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', bubbles: true }));

      tab();
      expect(document.activeElement).toBe(button('Cancel'));
      tab();
      expect(document.activeElement).toBe(button('OK'));
      tab();
      expect(document.activeElement).toBe(field());

      button('Cancel').click();
    });
  });

  it('falls back to native alert and prompt when they cannot be built', async () => {
    vi.spyOn(document, 'createElement').mockImplementation(() => {
      throw new Error('no DOM');
    });
    const nativeAlert = vi.spyOn(window, 'alert').mockImplementation(() => undefined);
    const nativePrompt = vi.spyOn(window, 'prompt').mockReturnValue('typed');

    await showAlert('Saved.');
    await expect(promptDialog('Enter tag:', { value: 'start' })).resolves.toBe('typed');

    expect(nativeAlert).toHaveBeenCalledWith('Saved.');
    expect(nativePrompt).toHaveBeenCalledWith('Enter tag:', 'start');
  });

  it('is the only code that opens a native dialog', () => {
    const offenders: string[] = [];
    const walk = (dir: string): void => {
      for (const name of readdirSync(dir)) {
        const path = join(dir, name);
        if (statSync(path).isDirectory()) {
          walk(path);
        } else if (path.endsWith('.ts') && !path.endsWith('confirm_dialog.ts')) {
          if (/(^|[^.\w])(window\.)?(confirm|alert|prompt)\(/m.test(readFileSync(path, 'utf8'))) {
            offenders.push(path);
          }
        }
      }
    };

    walk('src/frontend/js');

    expect(offenders).toEqual([]);
  });

  it('never tests a pending confirmation for truth without awaiting it', () => {
    // A promise is always truthy, so `if (confirmDelete())` would act without
    // asking, and `!confirmDelete()` would never run. The compiler catches
    // only some of these.
    const offenders: string[] = [];
    const asks = /(confirmAction|confirmDeletion|confirmDialog|confirmDelete|confirmDiscardChanges|confirmSubmit)\(/;
    const walk = (dir: string): void => {
      for (const name of readdirSync(dir)) {
        const path = join(dir, name);
        if (statSync(path).isDirectory()) {
          walk(path);
        } else if (path.endsWith('.ts')) {
          readFileSync(path, 'utf8').split('\n').forEach((line, index) => {
            const handedOver = line.includes('confirmBeforeSubmit(');
            if (/\b(if|while)\b|&&|\|\||!\s*confirm/.test(line) && asks.test(line)
              && !line.includes('await ') && !handedOver) {
              offenders.push(`${path}:${index + 1}`);
            }
          });
        }
      }
    };

    walk('src/frontend/js');

    expect(offenders).toEqual([]);
  });

  it('falls back to the native dialog when it cannot be built', async () => {
    vi.spyOn(document, 'createElement').mockImplementation(() => {
      throw new Error('no DOM');
    });
    const native = vi.spyOn(window, 'confirm').mockReturnValue(true);

    await expect(confirmDialog(options)).resolves.toBe(true);

    expect(native).toHaveBeenCalledOnce();
  });
});
