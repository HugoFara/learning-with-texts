/**
 * Tests for bulk_actions.ts - Bulk action utilities for LWT
 */
import { answerConfirms, settle } from '../helpers/confirm_answers';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import {
  selectToggle,
  multiActionGo,
  allActionGo
} from '../../../src/frontend/js/shared/forms/bulk_actions';

// Mock ui_utilities
vi.mock('../../../src/frontend/js/shared/utils/ui_utilities', () => ({
  markClick: vi.fn()
}));

import { markClick } from '../../../src/frontend/js/shared/utils/ui_utilities';

describe('bulk_actions.ts', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
    vi.clearAllMocks();
  });

  afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
  });

  // ===========================================================================
  // selectToggle Tests
  // ===========================================================================

  describe('selectToggle', () => {
    it('checks all form elements when toggle is true', () => {
      document.body.innerHTML = `
        <form name="form1">
          <input type="checkbox" />
          <input type="checkbox" />
          <input type="checkbox" />
        </form>
      `;

      selectToggle(true, 'form1');

      const checkboxes = document.querySelectorAll<HTMLInputElement>('input[type="checkbox"]');
      checkboxes.forEach(cb => {
        expect(cb.checked).toBe(true);
      });
    });

    it('unchecks all form elements when toggle is false', () => {
      document.body.innerHTML = `
        <form name="form1">
          <input type="checkbox" checked />
          <input type="checkbox" checked />
          <input type="checkbox" checked />
        </form>
      `;

      selectToggle(false, 'form1');

      const checkboxes = document.querySelectorAll<HTMLInputElement>('input[type="checkbox"]');
      checkboxes.forEach(cb => {
        expect(cb.checked).toBe(false);
      });
    });

    it('calls markClick after toggling', () => {
      document.body.innerHTML = `
        <form name="form1">
          <input type="checkbox" />
        </form>
      `;

      selectToggle(true, 'form1');

      expect(markClick).toHaveBeenCalled();
    });

    it('handles form with mixed input types', () => {
      document.body.innerHTML = `
        <form name="form1">
          <input type="checkbox" />
          <input type="text" />
          <input type="radio" />
          <input type="checkbox" />
        </form>
      `;

      // Note: selectToggle sets checked on ALL form elements
      // This may or may not be the intended behavior
      selectToggle(true, 'form1');

      const checkboxes = document.querySelectorAll<HTMLInputElement>('input[type="checkbox"]');
      checkboxes.forEach(cb => {
        expect(cb.checked).toBe(true);
      });
    });
  });

  // ===========================================================================
  // multiActionGo Tests
  // ===========================================================================

  describe('multiActionGo', () => {
    it('does nothing when form is undefined', () => {
      const select = document.createElement('select');
      select.innerHTML = '<option value="del">Delete</option>';

      expect(() => multiActionGo(undefined, select)).not.toThrow();
    });

    it('does nothing when select is undefined', () => {
      const form = document.createElement('form');

      expect(() => multiActionGo(form, undefined)).not.toThrow();
    });

    it('submits form for simple actions', () => {
      document.body.innerHTML = `
        <form name="testform">
          <input type="hidden" name="data" value="" />
        </form>
        <select id="action">
          <option value="export">Export</option>
        </select>
      `;

      const form = document.querySelector('form') as HTMLFormElement;
      const select = document.querySelector('select') as HTMLSelectElement;
      const submitSpy = vi.spyOn(form, 'submit').mockImplementation(() => {});

      multiActionGo(form, select);

      expect(submitSpy).toHaveBeenCalled();
      expect(select.value).toBe('');
    });

    it('prompts for tag when action is addtag', () => {
      document.body.innerHTML = `
        <form name="testform">
          <input type="hidden" name="data" value="" />
        </form>
        <select id="action">
          <option value="addtag">Add Tag</option>
        </select>
        <input type="checkbox" class="markcheck" checked />
        <input type="checkbox" class="markcheck" checked />
      `;

      const form = document.querySelector('form') as HTMLFormElement;
      (form as any).data = form.querySelector('input[name="data"]');
      const select = document.querySelector('select') as HTMLSelectElement;
      const submitSpy = vi.spyOn(form, 'submit').mockImplementation(() => {});
      const promptSpy = vi.spyOn(window, 'prompt').mockReturnValue('mytag');

      multiActionGo(form, select);

      expect(promptSpy).toHaveBeenCalled();
      expect(submitSpy).toHaveBeenCalled();
      expect((form as any).data.value).toBe('mytag');
    });

    it('prompts for tag when action is deltag', () => {
      document.body.innerHTML = `
        <form name="testform">
          <input type="hidden" name="data" value="" />
        </form>
        <select id="action">
          <option value="deltag">Remove Tag</option>
        </select>
        <input type="checkbox" class="markcheck" checked />
      `;

      const form = document.querySelector('form') as HTMLFormElement;
      (form as any).data = form.querySelector('input[name="data"]');
      const select = document.querySelector('select') as HTMLSelectElement;
      const submitSpy = vi.spyOn(form, 'submit').mockImplementation(() => {});
      vi.spyOn(window, 'prompt').mockReturnValue('removetag');

      multiActionGo(form, select);

      expect(submitSpy).toHaveBeenCalled();
    });

    it('does not submit when tag prompt is cancelled', () => {
      document.body.innerHTML = `
        <form name="testform">
          <input type="hidden" name="data" value="" />
        </form>
        <select id="action">
          <option value="addtag">Add Tag</option>
        </select>
        <input type="checkbox" class="markcheck" checked />
      `;

      const form = document.querySelector('form') as HTMLFormElement;
      const select = document.querySelector('select') as HTMLSelectElement;
      const submitSpy = vi.spyOn(form, 'submit').mockImplementation(() => {});
      vi.spyOn(window, 'prompt').mockReturnValue('');

      multiActionGo(form, select);

      expect(submitSpy).not.toHaveBeenCalled();
    });

    it('re-prompts when tag contains spaces', () => {
      document.body.innerHTML = `
        <form name="testform">
          <input type="hidden" name="data" value="" />
        </form>
        <select id="action">
          <option value="addtag">Add Tag</option>
        </select>
        <input type="checkbox" class="markcheck" checked />
      `;

      const form = document.querySelector('form') as HTMLFormElement;
      (form as any).data = form.querySelector('input[name="data"]');
      const select = document.querySelector('select') as HTMLSelectElement;
      vi.spyOn(form, 'submit').mockImplementation(() => {});
      const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
      const promptSpy = vi.spyOn(window, 'prompt')
        .mockReturnValueOnce('tag with space')
        .mockReturnValueOnce('validtag');

      multiActionGo(form, select);

      expect(alertSpy).toHaveBeenCalledWith('Please no spaces or commas!');
      expect(promptSpy).toHaveBeenCalledTimes(2);
    });

    it('re-prompts when tag contains commas', () => {
      document.body.innerHTML = `
        <form name="testform">
          <input type="hidden" name="data" value="" />
        </form>
        <select id="action">
          <option value="addtag">Add Tag</option>
        </select>
        <input type="checkbox" class="markcheck" checked />
      `;

      const form = document.querySelector('form') as HTMLFormElement;
      (form as any).data = form.querySelector('input[name="data"]');
      const select = document.querySelector('select') as HTMLSelectElement;
      vi.spyOn(form, 'submit').mockImplementation(() => {});
      const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
      vi.spyOn(window, 'prompt')
        .mockReturnValueOnce('tag,comma')
        .mockReturnValueOnce('validtag');

      multiActionGo(form, select);

      expect(alertSpy).toHaveBeenCalledWith('Please no spaces or commas!');
    });

    it('re-prompts when tag is too long', () => {
      document.body.innerHTML = `
        <form name="testform">
          <input type="hidden" name="data" value="" />
        </form>
        <select id="action">
          <option value="addtag">Add Tag</option>
        </select>
        <input type="checkbox" class="markcheck" checked />
      `;

      const form = document.querySelector('form') as HTMLFormElement;
      (form as any).data = form.querySelector('input[name="data"]');
      const select = document.querySelector('select') as HTMLSelectElement;
      vi.spyOn(form, 'submit').mockImplementation(() => {});
      const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
      vi.spyOn(window, 'prompt')
        .mockReturnValueOnce('thistagiswaytoolongmorethan20chars')
        .mockReturnValueOnce('shorttag');

      multiActionGo(form, select);

      expect(alertSpy).toHaveBeenCalledWith('Please no tags longer than 20 char.!');
    });

    it('confirms for del action', async () => {
      document.body.innerHTML = `
        <form name="testform"></form>
        <select id="action">
          <option value="del">Delete</option>
        </select>
        <input type="checkbox" class="markcheck" checked />
        <input type="checkbox" class="markcheck" checked />
      `;

      const form = document.querySelector('form') as HTMLFormElement;
      const select = document.querySelector('select') as HTMLSelectElement;
      const submitSpy = vi.spyOn(form, 'submit').mockImplementation(() => {});
      const confirmSpy = answerConfirms(true);

      multiActionGo(form, select);

      await settle();
      expect(confirmSpy).toHaveBeenCalled();
      expect(submitSpy).toHaveBeenCalled();
    });

    it('does not submit when confirmation is cancelled', async () => {
      document.body.innerHTML = `
        <form name="testform"></form>
        <select id="action">
          <option value="del">Delete</option>
        </select>
        <input type="checkbox" class="markcheck" checked />
      `;

      const form = document.querySelector('form') as HTMLFormElement;
      const select = document.querySelector('select') as HTMLSelectElement;
      const submitSpy = vi.spyOn(form, 'submit').mockImplementation(() => {});
      answerConfirms(false);

      multiActionGo(form, select);

      await settle();
      expect(submitSpy).not.toHaveBeenCalled();
    });

    it.each(['smi1', 'spl1', 's1', 's5', 's98', 's99', 'today', 'delsent', 'lower', 'cap'])(
      'confirms for %s action',
      async (action) => {
        document.body.innerHTML = `
          <form name="testform"></form>
          <select id="action">
            <option value="${action}">${action}</option>
          </select>
          <input type="checkbox" class="markcheck" checked />
        `;

        const form = document.querySelector('form') as HTMLFormElement;
        const select = document.querySelector('select') as HTMLSelectElement;
        const submitSpy = vi.spyOn(form, 'submit').mockImplementation(() => {});
        const confirmSpy = answerConfirms(true);

        multiActionGo(form, select);

        await settle();
        expect(confirmSpy).toHaveBeenCalled();
        expect(submitSpy).toHaveBeenCalled();
      }
    );

    it('resets select value after action', () => {
      document.body.innerHTML = `
        <form name="testform"></form>
        <select id="action">
          <option value="">Select...</option>
          <option value="export">Export</option>
        </select>
      `;

      const form = document.querySelector('form') as HTMLFormElement;
      const select = document.querySelector('select') as HTMLSelectElement;
      select.value = 'export';
      vi.spyOn(form, 'submit').mockImplementation(() => {});

      multiActionGo(form, select);

      expect(select.value).toBe('');
    });
  });

  // ===========================================================================
  // allActionGo Tests
  // ===========================================================================

  describe('allActionGo', () => {
    it('does nothing when form is undefined', () => {
      const select = document.createElement('select');
      select.innerHTML = '<option value="delall">Delete All</option>';

      expect(() => allActionGo(undefined, select, 10)).not.toThrow();
    });

    it('does nothing when select is undefined', () => {
      const form = document.createElement('form');

      expect(() => allActionGo(form, undefined, 10)).not.toThrow();
    });

    it('prompts for tag when action is addtagall', () => {
      document.body.innerHTML = `
        <form name="testform">
          <input type="hidden" name="data" value="" />
        </form>
        <select id="action">
          <option value="addtagall">Add Tag to All</option>
        </select>
      `;

      const form = document.querySelector('form') as HTMLFormElement;
      (form as any).data = form.querySelector('input[name="data"]');
      const select = document.querySelector('select') as HTMLSelectElement;
      const submitSpy = vi.spyOn(form, 'submit').mockImplementation(() => {});
      const promptSpy = vi.spyOn(window, 'prompt').mockReturnValue('alltag');

      allActionGo(form, select, 50);

      expect(promptSpy).toHaveBeenCalled();
      expect(submitSpy).toHaveBeenCalled();
      expect((form as any).data.value).toBe('alltag');
    });

    it('prompts for tag when action is deltagall', () => {
      document.body.innerHTML = `
        <form name="testform">
          <input type="hidden" name="data" value="" />
        </form>
        <select id="action">
          <option value="deltagall">Remove Tag from All</option>
        </select>
      `;

      const form = document.querySelector('form') as HTMLFormElement;
      (form as any).data = form.querySelector('input[name="data"]');
      const select = document.querySelector('select') as HTMLSelectElement;
      const submitSpy = vi.spyOn(form, 'submit').mockImplementation(() => {});
      vi.spyOn(window, 'prompt').mockReturnValue('removetag');

      allActionGo(form, select, 100);

      expect(submitSpy).toHaveBeenCalled();
    });

    it('shows record count in prompt for all actions', () => {
      document.body.innerHTML = `
        <form name="testform">
          <input type="hidden" name="data" value="" />
        </form>
        <select id="action">
          <option value="addtagall">Add Tag to All</option>
        </select>
      `;

      const form = document.querySelector('form') as HTMLFormElement;
      (form as any).data = form.querySelector('input[name="data"]');
      const select = document.querySelector('select') as HTMLSelectElement;
      vi.spyOn(form, 'submit').mockImplementation(() => {});
      const promptSpy = vi.spyOn(window, 'prompt').mockReturnValue('tag');

      allActionGo(form, select, 42);

      expect(promptSpy).toHaveBeenCalledWith(
        expect.stringContaining('42 Record(s)'),
        ''
      );
    });

    it('confirms for delall action', async () => {
      document.body.innerHTML = `
        <form name="testform"></form>
        <select id="action">
          <option value="delall">Delete All</option>
        </select>
      `;

      const form = document.querySelector('form') as HTMLFormElement;
      const select = document.querySelector('select') as HTMLSelectElement;
      const submitSpy = vi.spyOn(form, 'submit').mockImplementation(() => {});
      const confirmSpy = answerConfirms(true);

      allActionGo(form, select, 25);

      await settle();
      expect(confirmSpy).toHaveBeenCalledWith(
        expect.stringContaining('Records affected: 25')
      );
      expect(submitSpy).toHaveBeenCalled();
    });

    it.each(['smi1all', 'spl1all', 's1all', 's5all', 's98all', 's99all', 'todayall', 'delsentall', 'capall', 'lowerall'])(
      'confirms for %s action',
      async (action) => {
        document.body.innerHTML = `
          <form name="testform"></form>
          <select id="action">
            <option value="${action}">${action}</option>
          </select>
        `;

        const form = document.querySelector('form') as HTMLFormElement;
        const select = document.querySelector('select') as HTMLSelectElement;
        const submitSpy = vi.spyOn(form, 'submit').mockImplementation(() => {});
        const confirmSpy = answerConfirms(true);

        allActionGo(form, select, 10);

        await settle();
        expect(confirmSpy).toHaveBeenCalled();
        expect(submitSpy).toHaveBeenCalled();
      }
    );

    it('shows warning about all pages in confirm dialog', async () => {
      document.body.innerHTML = `
        <form name="testform"></form>
        <select id="action">
          <option value="delall">Delete All</option>
        </select>
      `;

      const form = document.querySelector('form') as HTMLFormElement;
      const select = document.querySelector('select') as HTMLSelectElement;
      vi.spyOn(form, 'submit').mockImplementation(() => {});
      const confirmSpy = answerConfirms(true);

      allActionGo(form, select, 10);

      await settle();
      expect(confirmSpy).toHaveBeenCalledWith(
        expect.stringContaining('all pages')
      );
    });

    it('resets select value after action', () => {
      document.body.innerHTML = `
        <form name="testform"></form>
        <select id="action">
          <option value="">Select...</option>
          <option value="exportall">Export All</option>
        </select>
      `;

      const form = document.querySelector('form') as HTMLFormElement;
      const select = document.querySelector('select') as HTMLSelectElement;
      select.value = 'exportall';
      vi.spyOn(form, 'submit').mockImplementation(() => {});

      allActionGo(form, select, 10);

      expect(select.value).toBe('');
    });

    it('validates tag input for allActionGo same as multiActionGo', () => {
      document.body.innerHTML = `
        <form name="testform">
          <input type="hidden" name="data" value="" />
        </form>
        <select id="action">
          <option value="addtagall">Add Tag to All</option>
        </select>
      `;

      const form = document.querySelector('form') as HTMLFormElement;
      (form as any).data = form.querySelector('input[name="data"]');
      const select = document.querySelector('select') as HTMLSelectElement;
      vi.spyOn(form, 'submit').mockImplementation(() => {});
      const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
      vi.spyOn(window, 'prompt')
        .mockReturnValueOnce('invalid tag')
        .mockReturnValueOnce('validtag');

      allActionGo(form, select, 10);

      expect(alertSpy).toHaveBeenCalledWith('Please no spaces or commas!');
    });
  });
});
