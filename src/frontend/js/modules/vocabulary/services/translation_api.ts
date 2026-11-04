/**
 * Standard JS interface to get translations
 *
 * @author  andreask7 <andreasks7@users.noreply.github.com>
 * @license Unlicense <http://unlicense.org/>
 * @since   1.6.16-fork
 */

import { t } from '@shared/i18n/translator';
import { confirmAction } from '@shared/components/confirm_dialog';
import { iconHtml } from '@shared/icons/icons';
import { escapeHtml } from '@shared/utils/html_utils';

// Type for the frame with form check
interface LwtFormCheck {
  makeDirty: () => void;
}

interface LwtFrame extends Window {
  document: Document;
  lwtFormCheck: LwtFormCheck;
}

// Type for frames collection
interface FramesCollection {
  ro?: LwtFrame;
}

// Glosbe API response types
interface GlosbePhraseData {
  text: string;
}

interface GlosbeMeaningData {
  text: string;
}

interface GlosbeTucEntry {
  phrase?: GlosbePhraseData;
  meanings?: GlosbeMeaningData[];
}

interface GlosbeResponse {
  tuc: GlosbeTucEntry[];
  from: string;
  dest: string;
  phrase: string;
}

// LibreTranslate response type
interface LibreTranslateResponse {
  translatedText: string;
}

export function deleteTranslation(): void {
  let frame: LwtFrame | undefined = (window.parent as Window & { frames: FramesCollection }).frames.ro;
  if (frame === undefined) {
    frame = window.opener as LwtFrame | undefined;
  }
  if (frame === undefined) {
    return;
  }
  const translationInput = frame.document.querySelector<HTMLInputElement | HTMLTextAreaElement>('[name="WoTranslation"]');
  if (translationInput && translationInput.value.trim().length) {
    translationInput.value = '';
    frame.lwtFormCheck.makeDirty();
  }
}

export async function addTranslation(s: string): Promise<void> {
  let frame: LwtFrame | undefined = (window.parent as Window & { frames: FramesCollection }).frames.ro;
  if (frame === undefined) {
    frame = window.opener as LwtFrame | undefined;
  }
  if (frame === undefined) {
    alert('Translation can not be copied!');
    return;
  }
  const word_trans = (frame.document.forms[0] as HTMLFormElement & { WoTranslation: HTMLInputElement }).WoTranslation;
  if (typeof word_trans !== 'object') {
    alert('Translation can not be copied!');
    return;
  }
  const oldValue = word_trans.value;
  if (oldValue.trim() === '') {
    word_trans.value = s;
    frame.lwtFormCheck.makeDirty();
  } else {
    if (oldValue.indexOf(s) === -1) {
      word_trans.value = oldValue + ' / ' + s;
      frame.lwtFormCheck.makeDirty();
    } else {
      if (await confirmAction(t('common.dialog.translation_exists', { text: s }))) {
        word_trans.value = oldValue + ' / ' + s;
        frame.lwtFormCheck.makeDirty();
      }
    }
  }
}

export function getGlosbeTranslation(text: string, lang: string, dest: string): void {
  // Note from 2.9.0: make asynchronous if possible
  // Note: the Glosbe API is closed and may not be open again
  // JSONP implementation using dynamic script tag
  const params = new URLSearchParams({
    from: lang,
    dest: dest,
    format: 'json',
    phrase: text,
    callback: 'getTranslationFromGlosbeApi'
  });

  // Register the global callback function
  (window as unknown as Record<string, unknown>).getTranslationFromGlosbeApi = getTranslationFromGlosbeApi;

  const script = document.createElement('script');
  script.src = 'https://glosbe.com/gapi/translate?' + params.toString();
  script.async = true;
  script.onerror = () => {
    const translationsEl = document.getElementById('translations');
    if (translationsEl) {
      translationsEl.textContent =
        'Retrieval error. Possible reason: There is a limit of Glosbe API ' +
        'calls that may be done from one IP address in a fixed period of time,' +
        ' to prevent from abuse.';
      translationsEl.insertAdjacentHTML('afterend', '<hr />');
    }
  };
  document.head.appendChild(script);
}

export function getTranslationFromGlosbeApi(data: GlosbeResponse): void {
  const translationsEl = document.getElementById('translations');
  if (!translationsEl) return;

  try {
    data.tuc.forEach((rows: GlosbeTucEntry) => {
      if (rows.phrase) {
        // Glosbe API text is third-party data; escape it and pass the word via a
        // data attribute consumed by the delegated 'add-translation' handler
        // (no inline onclick — CSP-safe and no string-literal breakout).
        const phrase = rows.phrase.text;
        translationsEl.insertAdjacentHTML('beforeend',
          '<span class="click" data-action="add-translation" data-word="' +
          escapeHtml(phrase) + '">' +
          iconHtml('tick-button', { title: 'Copy', alt: 'Copy' }) +
          ' &nbsp; ' + escapeHtml(phrase) +
          '</span><br />'
        );
      } else if (rows.meanings) {
        const meaning = '(' + rows.meanings[0].text + ')';
        translationsEl.insertAdjacentHTML('beforeend',
          '<span class="click" data-action="add-translation" data-word="' +
          escapeHtml(meaning) + '">' +
          iconHtml('tick-button', { title: 'Copy', alt: 'Copy' }) +
          ' &nbsp; ' + escapeHtml(meaning) +
          '</span><br />'
        );
      }
    });
    if (!data.tuc.length) {
      translationsEl.insertAdjacentHTML('beforebegin',
        '<p>No translations found (' + data.from + '-' + data.dest + ').</p>'
      );
      if (data.dest !== 'en' && data.from !== 'en') {
        translationsEl.id = 'no_trans';
        translationsEl.insertAdjacentHTML('afterend',
          '<hr /><p>&nbsp;</p><h3><a href="https://glosbe.com/' +
          data.from + '/en/' + data.phrase + '">Glosbe Dictionary (' +
          data.from + '-en):  &nbsp; <span class="has-text-danger has-text-weight-bold">' +
          data.phrase + '</span></a></h3>&nbsp;<p id="translations"></p>'
        );
        getGlosbeTranslation(data.phrase, data.from, 'en');
      } else {
        translationsEl.insertAdjacentHTML('afterend', '<hr />');
      }
    } else {
      translationsEl.insertAdjacentHTML('afterend',
        '<p>&nbsp;<br/>' + data.tuc.length + ' translation' +
        (data.tuc.length === 1 ? '' : 's') +
        ' retrieved via <a href="https://glosbe.com/a-api" target="_blank">' +
        'Glosbe API</a>.</p><hr />'
      );
    }
  } catch {
    translationsEl.textContent =
      'Retrieval error. Possible reason: There is a limit of Glosbe API ' +
      'calls that may be done from one IP address in a fixed period of time,' +
      ' to prevent from abuse.';
    translationsEl.insertAdjacentHTML('afterend', '<hr />');
  }
}

/**
 * Base function to get a translation from LibreTranslate.
 *
 * @param text Text to translate
 * @param lang Source language (language of the text, two letters or "auto")
 * @param dest Destination language (two language)
 * @param key  Optional API key
 * @param url  API URL
 * @returns Translation
 */
export async function getLibreTranslateTranslationBase(
  text: string,
  lang: string,
  dest: string,
  key: string = '',
  url: string = 'http://localhost:5000/translate'
): Promise<string> {
  const res = await fetch(
    url,
    {
      method: 'POST',
      body: JSON.stringify({
        q: text,
        source: lang,
        target: dest,
        format: 'text',
        api_key: key
      }),
      headers: { 'Content-Type': 'application/json' }
    }
  );

  const data: LibreTranslateResponse = await res.json();
  return data.translatedText;
}

/**
 * Main wrapper for LibreTranslate translation.
 *
 * @param libre_url URL of LibreTranslate.
 * @param text      Text to translate
 * @param lang      Source language (language of the text, two letters or "auto")
 * @param dest      Destination language (two language)
 * @returns Translation
 */
export async function getLibreTranslateTranslation(
  libre_url: URL,
  text: string,
  lang: string,
  dest: string
): Promise<string> {
  const search_params = libre_url.searchParams;
  if (search_params.get('lwt_translator') !== 'libretranslate') {
    throw new Error('Translation API not supported: ' +
      search_params.get('lwt_translator') + '!');
  }
  let translator_ajax: string;
  const ajaxParam = search_params.get('lwt_translator_ajax');
  if (ajaxParam) {
    translator_ajax = decodeURIComponent(ajaxParam);
  } else {
    translator_ajax = libre_url.toString().replace(libre_url.search, '') + 'translate';
  }
  return getLibreTranslateTranslationBase(
    text, lang, dest, search_params.get('lwt_key') || '', translator_ajax
  );
}

