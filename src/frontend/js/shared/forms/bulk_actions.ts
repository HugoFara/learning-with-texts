/**
 * Bulk action utilities for LWT.
 *
 * Functions for handling bulk selection and actions on multiple records.
 *
 * @author  andreask7 <andreasks7@users.noreply.github.com>
 * @license Unlicense <http://unlicense.org/>
 * @since   2.10.0-fork Extracted from pgm.ts
 */

import { confirmAction, promptDialog } from '@shared/components/confirm_dialog';
import { t as translate } from '@shared/i18n/translator';
import { markClick } from '@shared/utils/ui_utilities';

export function selectToggle(toggle: boolean, form: string): void {
  const myForm = document.forms[form as unknown as number] as HTMLFormElement;
  for (let i = 0; i < myForm.length; i++) {
    const element = myForm.elements[i] as HTMLInputElement;
    if (toggle) {
      element.checked = true;
    } else {
      element.checked = false;
    }
  }
  markClick();
}

interface FormWithData extends HTMLFormElement {
  data?: HTMLInputElement;
}

/**
 * Ask for a tag, then submit the form with it.
 *
 * @param f       The form
 * @param context What the action will affect
 * @param title   The action's name
 */
function submitWithTag(f: HTMLFormElement | FormWithData, context: string, title: string): void {
  void promptDialog(`${context}\n\n${translate('common.dialog.enter_tag')}`, {
    title,
    validate: (value) => (
      value.includes(' ') || value.includes(',') || value.length > 20
        ? translate('common.dialog.tag_invalid')
        : null
    )
  }).then((tag) => {
    if (!tag) return;
    const formWithData = f as FormWithData;
    if (formWithData.data) {
      formWithData.data.value = tag;
    }
    f.submit();
  });
}

export function multiActionGo(f: HTMLFormElement | FormWithData | undefined, sel: HTMLSelectElement | undefined): void {
  if (f !== undefined && sel !== undefined) {
    const v = sel.value;
    const t = sel.options[sel.selectedIndex].text;
    if (typeof v === 'string') {
      if (v === 'addtag' || v === 'deltag') {
        const checkedCount = document.querySelectorAll('input.markcheck:checked').length;
        submitWithTag(f, translate('common.dialog.records_affected', { count: checkedCount }), t);
      } else if (
        v === 'del' || v === 'smi1' || v === 'spl1' || v === 's1' || v === 's5' ||
        v === 's98' || v === 's99' || v === 'today' || v === 'delsent' ||
        v === 'lower' || v === 'cap'
      ) {
        const checkedCount = document.querySelectorAll('input.markcheck:checked').length;
        void confirmAction(
          translate('common.dialog.records_affected', { count: checkedCount }),
          { title: t, danger: v === 'del' || v === 'delsent' }
        ).then((confirmed) => {
          if (confirmed) {
            f.submit();
          }
        });
      } else {
        f.submit();
      }
    }
    sel.value = '';
  }
}

export function allActionGo(f: HTMLFormElement | FormWithData | undefined, sel: HTMLSelectElement | undefined, n: number): void {
  if (typeof f !== 'undefined' && typeof sel !== 'undefined') {
    const v = sel.value;
    const t = sel.options[sel.selectedIndex].text;
    if (typeof v === 'string') {
      if (v === 'addtagall' || v === 'deltagall') {
        const warning = translate('common.dialog.all_records_warning');
        const affected = translate('common.dialog.records_affected', { count: n });
        submitWithTag(f, `${warning}\n\n${affected}`, t);
      } else if (
        v === 'delall' || v === 'smi1all' || v === 'spl1all' || v === 's1all' ||
        v === 's5all' || v === 's98all' || v === 's99all' || v === 'todayall' ||
        v === 'delsentall' || v === 'capall' || v === 'lowerall'
      ) {
        const warning = translate('common.dialog.all_records_warning');
        const affected = translate('common.dialog.records_affected', { count: n });
        void confirmAction(`${warning}\n\n${affected}`, {
          title: t,
          danger: true
        }).then((confirmed) => {
          if (confirmed) {
            f.submit();
          }
        });
      } else {
        f.submit();
      }
    }
    sel.value = '';
  }
}

