/**
 * Tests for confirm_dialog.ts - in-app confirmation dialog
 */
import { describe, it, expect, afterEach, vi } from 'vitest';
import { confirmBeforeSubmit, confirmDialog } from '../../../src/frontend/js/shared/components/confirm_dialog';
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

  it('is the only code that opens a native confirmation', () => {
    const offenders: string[] = [];
    const walk = (dir: string): void => {
      for (const name of readdirSync(dir)) {
        const path = join(dir, name);
        if (statSync(path).isDirectory()) {
          walk(path);
        } else if (path.endsWith('.ts') && !path.endsWith('confirm_dialog.ts')) {
          if (/(^|[^.\w])(window\.)?confirm\(/m.test(readFileSync(path, 'utf8'))) {
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
