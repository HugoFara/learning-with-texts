/**
 * Language Form - Handles the language configuration form.
 *
 * Extracted from Views/Language/form.php
 * Provides functions for managing dictionary URLs, translator settings,
 * and form validation.
 *
 * @license Unlicense
 * @since 3.0.0
 */

import { onDomReady } from '@shared/utils/dom_ready';
import { getLibreTranslateTranslation } from '@modules/vocabulary/services/translation_api';
import { deepFindValue, readTextWithExternal } from '@shared/utils/user_interactions';
import { lwtFormCheck } from '@shared/forms/unloadformcheck';
import { url } from '@shared/utils/url';
import { escapeHtml } from '@shared/utils/html_utils';

/**
 * Build a URL query string from an object (replaces $.param).
 */
function buildQueryString(params: Record<string, string>): string {
  return new URLSearchParams(params).toString();
}

// Module-level variables for dictionary URLs
let GGTRANSLATE = '';
let LIBRETRANSLATE = '';

/**
 * Configuration for language form.
 * Passed from PHP via JSON.
 */
export interface LanguageFormConfig {
  languageId: number;
  languageName: string;
  sourceLg: string;
  targetLg: string;
  languageDefs: Record<string, [string, string, boolean, string, string, boolean, boolean, boolean]>;
  allLanguages: Record<string, number>;
}

declare global {
  interface Window {
    LANGDEFS: Record<string, [string, string, boolean, string, string, boolean, boolean, boolean]>;
  }
}

/**
 * Language form object.
 * Handles the language configuration form functionality.
 */
export const languageForm = {
  /** Current language ID */
  languageId: 0 as number,

  /** Current language name */
  languageName: '' as string,

  /** Language definitions loaded from config */
  langDefs: {} as Record<string, [string, string, boolean, string, string, boolean, boolean, boolean]>,

  /** All existing languages (name -> id map) */
  allLanguages: {} as Record<string, number>,

  /**
   * Initialize the form with language configuration.
   */
  init(config: LanguageFormConfig): void {
    this.languageId = config.languageId;
    this.languageName = config.languageName;
    this.langDefs = config.languageDefs;
    this.allLanguages = config.allLanguages;

    // Initialize dictionary URLs
    this.reloadDictURLs(config.sourceLg, config.targetLg);
  },

  /**
   * Reload dictionary URLs with the given language codes.
   *
   * @param sourceLg - Source language code (default: 'auto')
   * @param targetLg - Target language code (default: 'en')
   */
  reloadDictURLs(sourceLg = 'auto', targetLg = 'en'): void {
    GGTRANSLATE = 'https://translate.google.com/?' + buildQueryString({
      ie: 'UTF-8',
      sl: sourceLg,
      tl: targetLg,
      text: 'lwt_term'
    });

    LIBRETRANSLATE = 'http://localhost:5000/?' + buildQueryString({
      lwt_translator: 'libretranslate',
      source: sourceLg,
      target: targetLg,
      q: 'lwt_term'
    });
  },

  /**
   * Handle multi-word translator selection change.
   *
   * @param value - The selected translator type
   */
  multiWordsTranslateChange(value: string): void {
    let result: string | undefined;
    let usesKey = false;

    switch (value) {
      case 'google_translate':
        result = GGTRANSLATE;
        break;
      case 'libretranslate':
        result = LIBRETRANSLATE;
        usesKey = true;
        break;
    }

    if (result) {
      const lgForm = document.forms.namedItem('lg_form') as HTMLFormElement | null;
      if (lgForm) {
        const translatorUri = lgForm.elements.namedItem('LgGoogleTranslateURI') as HTMLInputElement | null;
        if (translatorUri) {
          translatorUri.value = result;
        }
      }
    }

    const keyWrapper = document.getElementById('LgTranslatorKeyWrapper');
    if (keyWrapper) {
      keyWrapper.style.display = usesKey ? 'inherit' : 'none';
    }
  },

  /**
   * Display an error message for LibreTranslate connection issues.
   *
   * @param error - The error message
   */
  displayLibreTranslateError(error: string): void {
    const statusEl = document.getElementById('translator_status');
    if (statusEl) {
      // The message can carry text echoed from the configured translator URL,
      // so it is escaped before joining the surrounding markup.
      statusEl.innerHTML =
        '<a href="https://libretranslate.com/">LibreTranslate</a> server seems to be unreachable. ' +
        'You can install it on your server with the <a href="">LibreTranslate installation guide</a>. ' +
        'Error: ' + escapeHtml(error);
    }
  },

  /**
   * Check the status of a translator URL.
   *
   * @param url - The translator URL to check
   */
  checkTranslatorStatus(url: string): void {
    let urlObj: URL;
    try {
      urlObj = new URL(url);
    } catch {
      return;
    }

    const params = urlObj.searchParams;
    if (params.get('lwt_translator') === 'libretranslate') {
      try {
        this.checkLibreTranslateStatus(urlObj, params.get('key') || '');
      } catch (error) {
        this.displayLibreTranslateError(String(error));
      }
    }
  },

  /**
   * Check the status of a LibreTranslate server.
   *
   * @param url - The LibreTranslate URL
   * @param key - Optional API key
   */
  checkLibreTranslateStatus(url: URL, key = ''): void {
    const transUrl = new URL(url.toString());
    transUrl.searchParams.append('lwt_key', key);

    getLibreTranslateTranslation(transUrl, 'ping', 'en', 'es')
      .then((translation: string) => {
        if (typeof translation === 'string') {
          const statusEl = document.getElementById('translator_status');
          if (statusEl) {
            statusEl.innerHTML = '<a href="https://libretranslate.com/">LibreTranslate</a> online!';
            statusEl.className = 'notification is-success';
          }
        }
      })
      .catch((error: Error) => {
        this.displayLibreTranslateError(error.message || String(error));
      });
  },

  /**
   * Update the text size example when the slider changes.
   *
   * @param value - The text size percentage
   */
  changeLanguageTextSize(value: string | number): void {
    const exampleEl = document.getElementById('LgTextSizeExample');
    if (exampleEl) {
      exampleEl.style.fontSize = value + '%';
    }
  },

  /**
   * Handle popup checkbox state change.
   * Popup setting is now stored in the database, not in the URL.
   *
   * @since 3.1.0 No longer modifies URLs, popup is stored in database column
   */
  changePopUpState(): void {
    // Popup is now just a form field, no URL manipulation needed
    // The checkbox state will be submitted with the form
  },

  /**
   * Handle dictionary URL input change.
   * Validates the URL and triggers related checks.
   *
   * @since 3.1.0 No longer detects popup from URL (asterisk or lwt_popup param)
   */
  checkDictionaryChanged(): void {
    // Previously this would detect popup settings from URLs
    // Now popup settings are stored in separate database columns
    // This method is kept for potential future URL validation
  },

  /**
   * Check the translator type and update the select box.
   *
   * @param url - The translator URL
   * @param typeSelect - The select element to update
   */
  checkTranslatorType(url: string, typeSelect: HTMLSelectElement): void {
    let parsedUrl: URL;
    try {
      parsedUrl = new URL(url);
    } catch {
      return;
    }

    let finalValue: string;
    switch (parsedUrl.searchParams.get('lwt_translator')) {
      case 'libretranslate':
        finalValue = 'libretranslate';
        break;
      default:
        finalValue = 'google_translate';
        break;
    }
    typeSelect.value = finalValue;
  },

  /**
   * Validate the Voice API JSON configuration.
   *
   * @param apiValue - The API configuration JSON string
   * @returns true if valid, false otherwise
   */
  checkVoiceAPI(apiValue: string): boolean {
    const messageField = document.getElementById('voice-api-message-zone');
    if (!messageField) return true;

    if (apiValue === '') {
      messageField.style.display = 'none';
      return true;
    }

    if (!apiValue.includes('lwt_term')) {
      messageField.textContent = '"lwt_term" is missing!';
      messageField.style.display = '';
      return false;
    }

    let query: Record<string, unknown>;
    try {
      query = JSON.parse(apiValue);
    } catch (error) {
      messageField.textContent = 'Cannot parse as JSON! ' + error;
      messageField.style.display = '';
      return false;
    }

    if (deepFindValue(query, 'lwt_term') === null) {
      messageField.textContent = "Cannot find 'lwt_term' in JSON!";
      messageField.style.display = '';
      return false;
    }

    messageField.style.display = 'none';
    return true;
  },

  /**
   * Test the Voice API with a demo text.
   */
  testVoiceAPI(): void {
    const lgForm = document.forms.namedItem('lg_form') as HTMLFormElement | null;
    if (!lgForm) return;

    const apiValue = (lgForm.elements.namedItem('LgTTSVoiceAPI') as HTMLTextAreaElement)?.value || '';
    const term = (lgForm.elements.namedItem('LgVoiceAPIDemo') as HTMLInputElement)?.value || '';
    readTextWithExternal(term, apiValue, this.languageName);
  },

  /**
   * Perform a full form check on page load.
   */
  fullFormCheck(): void {
    const lgForm = document.forms.namedItem('lg_form') as HTMLFormElement | null;
    if (!lgForm) return;

    checkLanguageForm(lgForm);
  }
};

/**
 * Check the translator input and update related fields.
 *
 * @param translatorInput - The translator URL input element
 */
export function checkTranslatorChanged(translatorInput: HTMLInputElement): void {
  languageForm.checkTranslatorStatus(translatorInput.value);
  languageForm.checkDictionaryChanged();

  const lgForm = document.forms.namedItem('lg_form') as HTMLFormElement | null;
  if (lgForm) {
    const translatorName = lgForm.elements.namedItem('LgTranslatorName') as HTMLSelectElement | null;
    if (translatorName) {
      languageForm.checkTranslatorType(translatorInput.value, translatorName);
    }
  }
}

/**
 * Perform a full validation of the language form.
 *
 * @param lgForm - The language form element
 */
export function checkLanguageForm(lgForm: HTMLFormElement): void {
  const lgDict1URI = lgForm.elements.namedItem('LgDict1URI') as HTMLInputElement | null;
  const lgDict2URI = lgForm.elements.namedItem('LgDict2URI') as HTMLInputElement | null;
  const lgGoogleTranslateURI = lgForm.elements.namedItem('LgGoogleTranslateURI') as HTMLInputElement | null;

  if (lgDict1URI) {
    languageForm.checkDictionaryChanged();
  }
  if (lgDict2URI) {
    languageForm.checkDictionaryChanged();
  }
  if (lgGoogleTranslateURI) {
    checkTranslatorChanged(lgGoogleTranslateURI);
  }
}

/**
 * Check for duplicate language names.
 *
 * @param curr - Current language ID (0 for new languages)
 * @param languages - Map of language names to IDs (optional, uses languageForm.allLanguages if not provided)
 * @returns true if no duplicate, false if duplicate found
 */
export function checkDuplicateLanguage(
  curr?: number,
  languages?: Record<string, number>
): boolean {
  const langId = curr ?? languageForm.languageId;
  const allLangs = languages ?? languageForm.allLanguages;
  const lgNameEl = document.getElementById('LgName') as HTMLInputElement | null;
  const lgName = lgNameEl?.value ?? '';

  if (lgName in allLangs) {
    if (langId !== allLangs[lgName]) {
      alert(
        'Language "' + lgName + '" already exists. Please change the language name!'
      );
      lgNameEl?.focus();
      return false;
    }
  }
  return true;
}

/**
 * Set an input's value and tell Alpine about it.
 *
 * The language fields are bound with `x-model`, which reads from the DOM only
 * when an input/change event fires. Assigning `.value` alone updates what the
 * user sees but leaves `lang` holding the old value, so the preset would be
 * discarded the moment the form is saved.
 *
 * @param input Field to write to, if it exists
 * @param value Value to set
 */
function setBoundValue(
  input: HTMLInputElement | HTMLSelectElement | null,
  value: string
): void {
  if (!input) return;
  input.value = value;
  input.dispatchEvent(new Event('input', { bubbles: true }));
  // Alpine binds a <select> on change, not input
  input.dispatchEvent(new Event('change', { bubbles: true }));
}

/**
 * Check or uncheck a bound checkbox and tell Alpine about it.
 *
 * @param input   Checkbox to write to, if it exists
 * @param checked Desired state
 */
function setBoundChecked(input: HTMLInputElement | null, checked: boolean): void {
  if (!input) return;
  input.checked = checked;
  input.dispatchEvent(new Event('change', { bubbles: true }));
}

/**
 * Apply wizard preset data to the form.
 */
function applyWizardPreset(): void {
  const wizardData = sessionStorage.getItem('lwt_language_wizard');
  if (!wizardData) return;

  // Clear the session storage immediately
  sessionStorage.removeItem('lwt_language_wizard');

  try {
    const data = JSON.parse(wizardData) as {
      l1: string;
      l2: string;
      definitions: Record<
        string,
        {
          glosbeIso: string;
          googleIso: string;
          biggerFont: boolean;
          wordCharRegExp: string;
          sentSplRegExp: string;
          makeCharacterWord: boolean;
          removeSpaces: boolean;
          rightToLeft: boolean;
          parserType?: string;
        }
      >;
    };

    const l1Def = data.definitions[data.l1];
    const l2Def = data.definitions[data.l2];

    if (!l2Def) {
      console.error('Unknown study language:', data.l2);
      return;
    }

    const lgForm = document.forms.namedItem('lg_form') as HTMLFormElement | null;
    if (!lgForm) return;

    // Set the language name
    setBoundValue(lgForm.elements.namedItem('LgName') as HTMLInputElement | null, data.l2);

    // Set dictionary URL (Glosbe)
    const l1Code = l1Def?.glosbeIso || 'en';
    const l2Code = l2Def.glosbeIso;
    setBoundValue(
      lgForm.elements.namedItem('LgDict1URI') as HTMLInputElement | null,
      `https://glosbe.com/${l2Code}/${l1Code}/lwt_term`
    );

    // Set dictionary popup (enabled by default for wizard)
    setBoundChecked(lgForm.elements.namedItem('LgDict1PopUp') as HTMLInputElement | null, true);

    // Set translator URL
    const l1GoogleCode = l1Def?.googleIso || 'en';
    const l2GoogleCode = l2Def.googleIso;
    setBoundValue(
      lgForm.elements.namedItem('LgGoogleTranslateURI') as HTMLInputElement | null,
      `https://translate.google.com/?sl=${l2GoogleCode}&tl=${l1GoogleCode}&text=lwt_term&op=translate`
    );

    // Set translator popup (enabled by default for wizard)
    setBoundChecked(
      lgForm.elements.namedItem('LgGoogleTranslatePopUp') as HTMLInputElement | null,
      true
    );

    // Set source/target language codes
    setBoundValue(lgForm.elements.namedItem('LgSourceLang') as HTMLInputElement | null, l2GoogleCode);
    setBoundValue(lgForm.elements.namedItem('LgTargetLang') as HTMLInputElement | null, l1GoogleCode);

    // Set text size based on language
    setBoundValue(
      lgForm.elements.namedItem('LgTextSize') as HTMLInputElement | null,
      l2Def.biggerFont ? '150' : '100'
    );

    // Set parsing rules
    setBoundValue(
      lgForm.elements.namedItem('LgRegexpSplitSentences') as HTMLInputElement | null,
      l2Def.sentSplRegExp
    );
    setBoundValue(
      lgForm.elements.namedItem('LgRegexpWordCharacters') as HTMLInputElement | null,
      l2Def.wordCharRegExp
    );

    // Set checkboxes
    setBoundChecked(
      lgForm.elements.namedItem('LgSplitEachChar') as HTMLInputElement | null,
      l2Def.makeCharacterWord
    );
    setBoundChecked(
      lgForm.elements.namedItem('LgRemoveSpaces') as HTMLInputElement | null,
      l2Def.removeSpaces
    );
    setBoundChecked(
      lgForm.elements.namedItem('LgRightToLeft') as HTMLInputElement | null,
      l2Def.rightToLeft
    );

    // Tokenizer the preset asks for — jieba for Chinese, MeCab for Japanese.
    // An empty value leaves it inferred from the flags above.
    setBoundValue(
      lgForm.elements.namedItem('LgParserType') as HTMLSelectElement | null,
      l2Def.parserType ?? ''
    );

    console.log(`Applied wizard preset for ${data.l2} (L1: ${data.l1})`);
  } catch (e) {
    console.error('Failed to apply wizard preset:', e);
  }
}

/**
 * Initialize the language form from JSON config element.
 */
export function initLanguageForm(): void {
  const configEl = document.getElementById('language-form-config');
  if (!configEl) return;

  let config: LanguageFormConfig;
  try {
    config = JSON.parse(configEl.textContent || '{}');
  } catch (e) {
    console.error('Failed to parse language-form-config:', e);
    return;
  }

  languageForm.init(config);

  // Check if we came from the wizard and apply preset
  if (window.location.search.includes('wizard=1')) {
    applyWizardPreset();
  }

  // Set up event listeners
  const lgForm = document.forms.namedItem('lg_form') as HTMLFormElement | null;
  if (!lgForm) return;

  // Form submit handler - check for duplicate language names
  lgForm.addEventListener('submit', function (e) {
    if (!checkDuplicateLanguage()) {
      e.preventDefault();
      return false;
    }
    return true;
  });

  // Dictionary inputs
  const dictInputs = ['LgDict1URI', 'LgDict2URI'];
  dictInputs.forEach(name => {
    const input = lgForm.elements.namedItem(name) as HTMLInputElement | null;
    if (input) {
      input.addEventListener('input', function () {
        languageForm.checkDictionaryChanged();
      });
    }
  });

  // Translator URL input
  const translatorUri = lgForm.elements.namedItem('LgGoogleTranslateURI') as HTMLInputElement | null;
  if (translatorUri) {
    translatorUri.addEventListener('input', function () {
      checkTranslatorChanged(this);
    });
  }

  // Translator select
  const translatorName = lgForm.elements.namedItem('LgTranslatorName') as HTMLSelectElement | null;
  if (translatorName) {
    translatorName.addEventListener('change', function () {
      languageForm.multiWordsTranslateChange(this.value);
    });
  }

  // Popup checkboxes
  const popupCheckboxes = ['LgDict1PopUp', 'LgDict2PopUp', 'LgGoogleTranslatePopUp'];
  popupCheckboxes.forEach(name => {
    const checkbox = lgForm.elements.namedItem(name) as HTMLInputElement | null;
    if (checkbox) {
      checkbox.addEventListener('change', function () {
        languageForm.changePopUpState();
      });
    }
  });

  // Text size input
  const textSize = lgForm.elements.namedItem('LgTextSize') as HTMLInputElement | null;
  if (textSize) {
    textSize.addEventListener('change', function () {
      languageForm.changeLanguageTextSize(this.value);
    });
  }

  // Voice API textarea
  const voiceApi = lgForm.elements.namedItem('LgTTSVoiceAPI') as HTMLTextAreaElement | null;
  if (voiceApi) {
    voiceApi.addEventListener('change', function () {
      languageForm.checkVoiceAPI(this.value);
    });
  }

  // Check Voice API button
  const checkVoiceBtn = document.querySelector('[data-action="check-voice-api"]');
  if (checkVoiceBtn) {
    checkVoiceBtn.addEventListener('click', () => {
      const voiceApiEl = lgForm.elements.namedItem('LgTTSVoiceAPI') as HTMLTextAreaElement | null;
      if (voiceApiEl) {
        languageForm.checkVoiceAPI(voiceApiEl.value);
      }
    });
  }

  // Test Voice API button
  const testVoiceBtn = document.querySelector('[data-action="test-voice-api"]');
  if (testVoiceBtn) {
    testVoiceBtn.addEventListener('click', () => {
      languageForm.testVoiceAPI();
    });
  }

  // Cancel button
  const cancelBtn = document.querySelector('[data-action="cancel-form"]') as HTMLButtonElement | null;
  if (cancelBtn) {
    cancelBtn.addEventListener('click', () => {
      lwtFormCheck.resetDirty();
      const redirect = cancelBtn.dataset.redirect || url('/languages');
      window.location.href = redirect;
    });
  }

  // Run initial form check (DOM should already be ready at this point)
  languageForm.fullFormCheck();

  // Set up form check for unsaved changes
  lwtFormCheck.askBeforeExit();
}

// Auto-initialize on DOM ready if config element is present
onDomReady(() => {
  if (document.getElementById('language-form-config')) {
    initLanguageForm();
  }
});
