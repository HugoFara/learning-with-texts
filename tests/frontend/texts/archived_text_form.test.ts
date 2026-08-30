/**
 * Tests for modules/text/pages/archived_text_form.ts.
 *
 * The archived editor posted back to /text/archived/{id}/edit until #262; it
 * now saves through PUT /api/v1/texts/archived/{id}.
 */
import { describe, it, expect, beforeEach, vi } from 'vitest';

vi.mock('alpinejs', () => ({
  default: { data: vi.fn() }
}));

vi.mock('../../../src/frontend/js/modules/text/pages/text_form_save', () => ({
  saveArchivedTextForm: vi.fn()
}));

import { saveArchivedTextForm } from
  '../../../src/frontend/js/modules/text/pages/text_form_save';
import { archivedTextFormData } from
  '../../../src/frontend/js/modules/text/pages/archived_text_form';

const saveMock = saveArchivedTextForm as ReturnType<typeof vi.fn>;

/** Build a submit event carrying a form, as Alpine hands it over. */
function submitEvent(form: HTMLFormElement): Event {
  const event = new Event('submit', { cancelable: true });
  Object.defineProperty(event, 'target', { value: form });
  return event;
}

describe('archived_text_form.ts', () => {
  let form: HTMLFormElement;

  beforeEach(() => {
    vi.clearAllMocks();
    document.body.innerHTML =
      '<script type="application/json" id="archived-text-config">{"textId":42}</script>'
      + '<form></form>';
    form = document.querySelector('form') as HTMLFormElement;
    Object.defineProperty(window, 'location', {
      value: { href: '' },
      writable: true
    });
  });

  describe('init', () => {
    it('reads the archived text id from the config blob', () => {
      const app = archivedTextFormData();
      app.init();

      expect(app.textId).toBe(42);
    });

    it('falls back to 0 on a malformed blob', () => {
      document.body.innerHTML =
        '<script type="application/json" id="archived-text-config">not json</script>';

      const app = archivedTextFormData();
      app.init();

      expect(app.textId).toBe(0);
    });
  });

  describe('handleSubmit', () => {
    it('prevents the native post and saves through the API', async () => {
      saveMock.mockResolvedValue({ textId: 42, bookId: null, error: '' });

      const app = archivedTextFormData();
      app.init();
      const event = submitEvent(form);
      app.handleSubmit(event);
      await vi.waitFor(() => expect(saveMock).toHaveBeenCalled());

      expect(event.defaultPrevented).toBe(true);
      expect(saveMock).toHaveBeenCalledWith(form, 42);
    });

    it('returns to the archived list anchored on the saved text', async () => {
      saveMock.mockResolvedValue({ textId: 42, bookId: null, error: '' });

      const app = archivedTextFormData();
      app.init();
      app.handleSubmit(submitEvent(form));
      await vi.waitFor(() => expect(window.location.href).toBe('/text/archived#rec42'));
    });

    it('shows the error and stays on the page when the save fails', async () => {
      saveMock.mockResolvedValue({ textId: null, bookId: null, error: 'Language not found' });

      const app = archivedTextFormData();
      app.init();
      app.handleSubmit(submitEvent(form));
      await vi.waitFor(() => expect(app.hasSaveError()).toBe(true));

      expect(app.saveError).toBe('Language not found');
      expect(app.saving).toBe(false);
      expect(window.location.href).toBe('');
    });

    it('does not submit twice while a save is in flight', () => {
      const app = archivedTextFormData();
      app.init();
      app.saving = true;
      app.handleSubmit(submitEvent(form));

      expect(saveMock).not.toHaveBeenCalled();
    });
  });
});
