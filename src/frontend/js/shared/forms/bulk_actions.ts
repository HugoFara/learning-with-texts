/**
 * Bulk action utilities for LWT.
 *
 * Functions for handling bulk selection and actions on multiple records.
 *
 * @author  andreask7 <andreasks7@users.noreply.github.com>
 * @license Unlicense <http://unlicense.org/>
 * @since   2.10.0-fork Extracted from pgm.ts
 */

import { confirmAction } from '@shared/components/confirm_dialog';
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

export function multiActionGo(f: HTMLFormElement | FormWithData | undefined, sel: HTMLSelectElement | undefined): void {
  if (f !== undefined && sel !== undefined) {
    const v = sel.value;
    const t = sel.options[sel.selectedIndex].text;
    if (typeof v === 'string') {
      if (v === 'addtag' || v === 'deltag') {
        let notok = true;
        let answer: string | null = '';
        const checkedCount = document.querySelectorAll('input.markcheck:checked').length;
        while (notok) {
          answer = prompt(
            '*** ' + t + ' ***' +
            '\n\n*** ' + checkedCount +
            ' Record(s) will be affected ***' +
            '\n\nPlease enter one tag (20 char. max., no spaces, no commas -- ' +
            'or leave empty to cancel:',
            answer || ''
          );
          if (answer === null) answer = '';
          if (answer.indexOf(' ') > 0 || answer.indexOf(',') > 0) {
            alert('Please no spaces or commas!');
          } else if (answer.length > 20) {
            alert('Please no tags longer than 20 char.!');
          } else {
            notok = false;
          }
        }
        if (answer !== '') {
          const formWithData = f as FormWithData;
          if (formWithData.data) {
            formWithData.data.value = answer;
          }
          f.submit();
        }
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
        let notok = true;
        let answer: string | null = '';
        while (notok) {
          answer = prompt(
            'THIS IS AN ACTION ON ALL RECORDS\n' +
            'ON ALL PAGES OF THE CURRENT QUERY!\n\n' +
            '*** ' + t + ' ***\n\n*** ' + n + ' Record(s) will be affected ***\n\n' +
            'Please enter one tag (20 char. max., no spaces, no commas -- ' +
            'or leave empty to cancel:',
            answer || ''
          );
          if (answer === null) answer = '';
          if (answer.indexOf(' ') > 0 || answer.indexOf(',') > 0) {
            alert('Please no spaces or commas!');
          } else if (answer.length > 20) {
            alert('Please no tags longer than 20 char.!');
          } else {
            notok = false;
          }
        }
        if (answer !== '') {
          const formWithData = f as FormWithData;
          if (formWithData.data) {
            formWithData.data.value = answer;
          }
          f.submit();
        }
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

