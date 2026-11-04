/**
 * Tests for confirm_dialog.ts - in-app confirmation dialog
 */
import { describe, it, expect, afterEach, vi } from 'vitest';
import { confirmDialog } from '../../../src/frontend/js/shared/components/confirm_dialog';

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

  it('falls back to the native dialog when it cannot be built', async () => {
    vi.spyOn(document, 'createElement').mockImplementation(() => {
      throw new Error('no DOM');
    });
    const native = vi.spyOn(window, 'confirm').mockReturnValue(true);

    await expect(confirmDialog(options)).resolves.toBe(true);

    expect(native).toHaveBeenCalledOnce();
  });
});
