/**
 * Tests for language_form.ts - Language form configuration
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import {
  languageForm,
  checkTranslatorChanged,
  checkLanguageForm,
  checkDuplicateLanguage,
  initLanguageForm,
  type LanguageFormConfig
} from '../../../src/frontend/js/modules/language/pages/language_form';

// Mock dependencies
vi.mock('../../../src/frontend/js/modules/vocabulary/services/translation_api', () => ({
  getLibreTranslateTranslation: vi.fn().mockResolvedValue('translated')
}));

vi.mock('../../../src/frontend/js/shared/utils/user_interactions', () => ({
  deepFindValue: vi.fn((obj, key) => {
    // Simple implementation for testing
    if (typeof obj !== 'object' || obj === null) return null;
    if (key in obj) return obj[key];
    for (const k in obj) {
      const result = (vi.mocked as any).deepFindValue?.(obj[k], key);
      if (result !== null) return result;
    }
    return null;
  }),
  readTextWithExternal: vi.fn()
}));

vi.mock('../../../src/frontend/js/shared/forms/unloadformcheck', () => ({
  lwtFormCheck: {
    resetDirty: vi.fn(),
    askBeforeExit: vi.fn()
  }
}));

describe('language_form.ts', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
    vi.clearAllMocks();
    // Reset languageForm state
    languageForm.languageId = 0;
    languageForm.languageName = '';
    languageForm.langDefs = {};
    languageForm.allLanguages = {};
  });

  afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
  });

  // ===========================================================================
  // languageForm.init Tests
  // ===========================================================================

  describe('languageForm.init', () => {
    it('sets languageId from config', () => {
      const config: LanguageFormConfig = {
        languageId: 5,
        languageName: 'Spanish',
        sourceLg: 'es',
        targetLg: 'en',
        languageDefs: {},
        allLanguages: {}
      };

      languageForm.init(config);

      expect(languageForm.languageId).toBe(5);
    });

    it('sets languageName from config', () => {
      const config: LanguageFormConfig = {
        languageId: 1,
        languageName: 'French',
        sourceLg: 'fr',
        targetLg: 'en',
        languageDefs: {},
        allLanguages: {}
      };

      languageForm.init(config);

      expect(languageForm.languageName).toBe('French');
    });

    it('sets langDefs from config', () => {
      const langDefs = {
        'Japanese': ['ja', 'Japanese', false, '\\p{Han}\\p{Hiragana}\\p{Katakana}', '', false, false, false] as [string, string, boolean, string, string, boolean, boolean, boolean]
      };

      const config: LanguageFormConfig = {
        languageId: 1,
        languageName: '',
        sourceLg: 'auto',
        targetLg: 'en',
        languageDefs: langDefs,
        allLanguages: {}
      };

      languageForm.init(config);

      expect(languageForm.langDefs).toEqual(langDefs);
    });

    it('sets allLanguages from config', () => {
      const allLanguages = { 'English': 1, 'Spanish': 2 };

      const config: LanguageFormConfig = {
        languageId: 1,
        languageName: '',
        sourceLg: 'auto',
        targetLg: 'en',
        languageDefs: {},
        allLanguages
      };

      languageForm.init(config);

      expect(languageForm.allLanguages).toEqual(allLanguages);
    });

    it('calls reloadDictURLs with source and target languages', () => {
      const spy = vi.spyOn(languageForm, 'reloadDictURLs');

      const config: LanguageFormConfig = {
        languageId: 1,
        languageName: '',
        sourceLg: 'de',
        targetLg: 'fr',
        languageDefs: {},
        allLanguages: {}
      };

      languageForm.init(config);

      expect(spy).toHaveBeenCalledWith('de', 'fr');
    });
  });

  // ===========================================================================
  // languageForm.reloadDictURLs Tests
  // ===========================================================================

  describe('languageForm.reloadDictURLs', () => {
    const originalLocation = window.location;

    beforeEach(() => {
      delete (window as any).location;
      (window as any).location = {
        href: 'http://localhost/languages/edit/1'
      };
    });

    afterEach(() => {
      window.location = originalLocation;
    });

    it('does not throw when called', () => {
      expect(() => languageForm.reloadDictURLs('en', 'es')).not.toThrow();
    });

    it('uses default values when called without arguments', () => {
      expect(() => languageForm.reloadDictURLs()).not.toThrow();
    });
  });

  // ===========================================================================
  // languageForm.multiWordsTranslateChange Tests
  // ===========================================================================

  describe('languageForm.multiWordsTranslateChange', () => {
    const originalLocation = window.location;

    beforeEach(() => {
      delete (window as any).location;
      (window as any).location = {
        href: 'http://localhost//languages/edit/1'
      };

      document.body.innerHTML = `
        <form name="lg_form">
          <input name="LgGoogleTranslateURI" value="">
        </form>
        <div id="LgTranslatorKeyWrapper" style="display: none;"></div>
      `;
    });

    afterEach(() => {
      window.location = originalLocation;
    });

    it('sets Google Translate URL for google_translate option', () => {
      languageForm.reloadDictURLs('en', 'es');
      languageForm.multiWordsTranslateChange('google_translate');

      const input = document.querySelector('[name="LgGoogleTranslateURI"]') as HTMLInputElement;
      expect(input.value).toContain('translate.google.com');
    });

    it('sets LibreTranslate URL and shows key wrapper for libretranslate option', () => {
      languageForm.reloadDictURLs('en', 'es');
      languageForm.multiWordsTranslateChange('libretranslate');

      const input = document.querySelector('[name="LgGoogleTranslateURI"]') as HTMLInputElement;
      expect(input.value).toContain('localhost:5000');
      // style.display = 'inherit' may be normalized by browser to 'block' or similar
      const wrapper = document.getElementById('LgTranslatorKeyWrapper') as HTMLElement;
      expect(wrapper.style.display).not.toBe('none');
    });

    it('does not change URL for unknown option', () => {
      // Old options like 'ggl' and 'glosbe' have been removed
      // The function only handles 'google_translate' and 'libretranslate' now
      const input = document.querySelector('[name="LgGoogleTranslateURI"]') as HTMLInputElement;
      input.value = 'original_url';

      languageForm.multiWordsTranslateChange('unknown_option');

      // Value should not change for unknown option
      expect(input.value).toBe('original_url');
    });

    it('hides key wrapper for non-libretranslate options', () => {
      const wrapper = document.getElementById('LgTranslatorKeyWrapper') as HTMLElement;
      wrapper.style.display = 'inherit';

      languageForm.reloadDictURLs('en', 'es');
      languageForm.multiWordsTranslateChange('google_translate');

      expect(wrapper.style.display).toBe('none');
    });
  });

  // ===========================================================================
  // languageForm.changeLanguageTextSize Tests
  // ===========================================================================

  describe('languageForm.changeLanguageTextSize', () => {
    it('sets font size on example element', () => {
      document.body.innerHTML = `
        <div id="LgTextSizeExample">Sample text</div>
      `;

      languageForm.changeLanguageTextSize(150);

      const example = document.getElementById('LgTextSizeExample') as HTMLElement;
      expect(example.style.fontSize).toBe('150%');
    });

    it('handles string values', () => {
      document.body.innerHTML = `
        <div id="LgTextSizeExample">Sample text</div>
      `;

      languageForm.changeLanguageTextSize('200');

      const example = document.getElementById('LgTextSizeExample') as HTMLElement;
      expect(example.style.fontSize).toBe('200%');
    });
  });

  // ===========================================================================
  // languageForm.addPopUpOption Tests
  // ===========================================================================

  // ===========================================================================
  // languageForm.changePopUpState Tests
  // Note: Since popup is now stored in database columns (not URL), this method is now a no-op
  // ===========================================================================

  describe('languageForm.changePopUpState', () => {
    it('does not modify URL when popup checkbox is changed', () => {
      document.body.innerHTML = `
        <form name="lg_form">
          <input name="LgDict1URI" value="https://dict.com?q=test">
          <input type="checkbox" name="LgDict1PopUp">
        </form>
      `;

      const checkbox = document.querySelector('[name="LgDict1PopUp"]') as HTMLInputElement;
      checkbox.checked = true;

      // Should not throw and should not modify URL
      languageForm.changePopUpState(checkbox);

      const input = document.querySelector('[name="LgDict1URI"]') as HTMLInputElement;
      // URL should remain unchanged - popup is now stored in database
      expect(input.value).toBe('https://dict.com?q=test');
    });

    it('accepts the checkbox element without error', () => {
      document.body.innerHTML = `
        <form name="lg_form">
          <input type="checkbox" name="LgDict2PopUp">
        </form>
      `;

      const checkbox = document.querySelector('[name="LgDict2PopUp"]') as HTMLInputElement;
      expect(() => languageForm.changePopUpState(checkbox)).not.toThrow();
    });
  });

  // ===========================================================================
  // languageForm.checkDictionaryChanged Tests
  // Note: Since popup is now stored in database columns, this method no longer detects popup from URL
  // ===========================================================================

  describe('languageForm.checkDictionaryChanged', () => {
    it('does not modify checkbox state from URL', () => {
      document.body.innerHTML = `
        <form name="lg_form">
          <input name="LgDict1URI" value="https://dict.com?q=test">
          <input type="checkbox" name="LgDict1PopUp">
        </form>
      `;

      languageForm.checkDictionaryChanged();

      // Popup setting is now in database, not detected from URL
      const checkbox = document.querySelector('[name="LgDict1PopUp"]') as HTMLInputElement;
      expect(checkbox.checked).toBe(false);
    });

    it('accepts input element without error', () => {
      document.body.innerHTML = `
        <form name="lg_form">
          <input name="LgDict1URI" value="https://dict.com?q=test">
          <input type="checkbox" name="LgDict1PopUp">
        </form>
      `;

      // Should not throw - method is now a no-op
      expect(() => languageForm.checkDictionaryChanged()).not.toThrow();
    });

    it('does nothing when input is empty', () => {
      document.body.innerHTML = `
        <form name="lg_form">
          <input name="LgDict1URI" value="">
          <input type="checkbox" name="LgDict1PopUp">
        </form>
      `;

      languageForm.checkDictionaryChanged();

      const checkbox = document.querySelector('[name="LgDict1PopUp"]') as HTMLInputElement;
      expect(checkbox.checked).toBe(false);
    });
  });

  // ===========================================================================
  // languageForm.checkTranslatorType Tests
  // ===========================================================================

  describe('languageForm.checkTranslatorType', () => {
    it('selects libretranslate when URL has lwt_translator=libretranslate', () => {
      document.body.innerHTML = `
        <select id="translatorType">
          <option value="google_translate">Google Translate</option>
          <option value="libretranslate">LibreTranslate</option>
        </select>
      `;

      const select = document.getElementById('translatorType') as HTMLSelectElement;
      languageForm.checkTranslatorType(
        'http://localhost:5000?lwt_translator=libretranslate&q=test',
        select
      );

      expect(select.value).toBe('libretranslate');
    });

    it('selects google_translate for other URLs', () => {
      document.body.innerHTML = `
        <select id="translatorType">
          <option value="google_translate">Google Translate</option>
          <option value="libretranslate">LibreTranslate</option>
        </select>
      `;

      const select = document.getElementById('translatorType') as HTMLSelectElement;
      languageForm.checkTranslatorType(
        'https://translate.google.com?q=test',
        select
      );

      expect(select.value).toBe('google_translate');
    });

    it('handles invalid URL gracefully', () => {
      document.body.innerHTML = `
        <select id="translatorType">
          <option value="google_translate" selected>Google Translate</option>
          <option value="libretranslate">LibreTranslate</option>
        </select>
      `;

      const select = document.getElementById('translatorType') as HTMLSelectElement;

      expect(() => languageForm.checkTranslatorType('not-a-url', select)).not.toThrow();
    });
  });

  // ===========================================================================
  // languageForm.checkVoiceAPI Tests
  // ===========================================================================

  describe('languageForm.checkVoiceAPI', () => {
    beforeEach(() => {
      document.body.innerHTML = `
        <div id="voice-api-message-zone" style="display: none;"></div>
      `;
    });

    it('returns true and hides message for empty value', () => {
      const result = languageForm.checkVoiceAPI('');

      expect(result).toBe(true);
      const zone = document.getElementById('voice-api-message-zone') as HTMLElement;
      expect(zone.style.display).toBe('none');
    });

    it('returns false when lwt_term is missing', () => {
      const result = languageForm.checkVoiceAPI('{"url": "http://api.com"}');

      expect(result).toBe(false);
      const zone = document.getElementById('voice-api-message-zone') as HTMLElement;
      expect(zone.textContent).toContain('lwt_term');
    });

    it('returns false for invalid JSON', () => {
      const result = languageForm.checkVoiceAPI('lwt_term {invalid json}');

      expect(result).toBe(false);
      const zone = document.getElementById('voice-api-message-zone') as HTMLElement;
      expect(zone.textContent).toContain('JSON');
    });

    it('returns true for valid JSON with lwt_term', async () => {
      const { deepFindValue } = await import('../../../src/frontend/js/shared/utils/user_interactions');
      (deepFindValue as any).mockReturnValue('lwt_term');

      const result = languageForm.checkVoiceAPI('{"text": "lwt_term"}');

      expect(result).toBe(true);
    });
  });

  // ===========================================================================
  // checkDuplicateLanguage Tests
  // ===========================================================================

  describe('checkDuplicateLanguage', () => {
    beforeEach(() => {
      vi.spyOn(window, 'alert').mockImplementation(() => {});
    });

    it('returns true when language does not exist', () => {
      languageForm.languageId = 0;
      languageForm.allLanguages = { 'English': 1 };

      document.body.innerHTML = `
        <input id="LgName" value="Spanish">
      `;

      const result = checkDuplicateLanguage();

      expect(result).toBe(true);
    });

    it('returns true when editing existing language with same name', () => {
      languageForm.languageId = 1;
      languageForm.allLanguages = { 'English': 1 };

      document.body.innerHTML = `
        <input id="LgName" value="English">
      `;

      const result = checkDuplicateLanguage();

      expect(result).toBe(true);
    });

    it('returns false when creating duplicate language', () => {
      languageForm.languageId = 0;
      languageForm.allLanguages = { 'English': 1 };

      document.body.innerHTML = `
        <input id="LgName" value="English">
      `;

      const result = checkDuplicateLanguage();

      expect(result).toBe(false);
      expect(window.alert).toHaveBeenCalled();
    });

    it('accepts custom language ID and languages map', () => {
      document.body.innerHTML = `
        <input id="LgName" value="French">
      `;

      const result = checkDuplicateLanguage(2, { 'French': 2 });

      expect(result).toBe(true);
    });
  });

  // ===========================================================================
  // checkTranslatorChanged Tests
  // ===========================================================================

  describe('checkTranslatorChanged', () => {
    it('calls checkTranslatorStatus and checkDictionaryChanged', () => {
      const statusSpy = vi.spyOn(languageForm, 'checkTranslatorStatus');
      const dictSpy = vi.spyOn(languageForm, 'checkDictionaryChanged');

      document.body.innerHTML = `
        <form name="lg_form">
          <input name="LgGoogleTranslateURI" value="https://translate.com">
          <input type="checkbox" name="LgGoogleTranslatePopUp">
          <select name="LgTranslatorName">
            <option value="google_translate">Google</option>
          </select>
        </form>
      `;

      const input = document.querySelector('[name="LgGoogleTranslateURI"]') as HTMLInputElement;
      checkTranslatorChanged(input);

      expect(statusSpy).toHaveBeenCalledWith('https://translate.com');
      expect(dictSpy).toHaveBeenCalled();
    });
  });

  // ===========================================================================
  // checkLanguageForm Tests
  // ===========================================================================

  describe('checkLanguageForm', () => {
    it('calls check functions for all form elements', () => {
      const dictChangeSpy = vi.spyOn(languageForm, 'checkDictionaryChanged');

      document.body.innerHTML = `
        <form name="lg_form">
          <input name="LgName" value="Japanese">
          <input name="LgDict1URI" value="https://dict1.com">
          <input name="LgDict2URI" value="https://dict2.com">
          <input name="LgGoogleTranslateURI" value="https://translate.com">
          <input name="LgRegexpWordCharacters" value="mecab">
          <input type="checkbox" name="LgDict1PopUp">
          <input type="checkbox" name="LgDict2PopUp">
          <input type="checkbox" name="LgGoogleTranslatePopUp">
          <select name="LgTranslatorName">
            <option value="google_translate">Google</option>
          </select>
        </form>
      `;

      const form = document.querySelector('[name="lg_form"]') as HTMLFormElement;
      checkLanguageForm(form);

      expect(dictChangeSpy).toHaveBeenCalled();
    });
  });

  // ===========================================================================
  // initLanguageForm Tests
  // ===========================================================================

  describe('initLanguageForm', () => {
    it('initializes from JSON config element', () => {
      document.body.innerHTML = `
        <script type="application/json" id="language-form-config">
          {
            "languageId": 3,
            "languageName": "German",
            "sourceLg": "de",
            "targetLg": "en",
            "languageDefs": {},
            "allLanguages": {"German": 3}
          }
        </script>
        <form name="lg_form">
          <input name="LgName" value="German">
        </form>
      `;

      initLanguageForm();

      expect(languageForm.languageId).toBe(3);
      expect(languageForm.languageName).toBe('German');
    });

    it('does nothing when config element is missing', () => {
      document.body.innerHTML = `
        <form name="lg_form">
          <input name="LgName" value="German">
        </form>
      `;

      const initSpy = vi.spyOn(languageForm, 'init');
      initLanguageForm();

      expect(initSpy).not.toHaveBeenCalled();
    });

    it('handles invalid JSON in config element', () => {
      const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});

      document.body.innerHTML = `
        <script type="application/json" id="language-form-config">
          {invalid json}
        </script>
        <form name="lg_form"></form>
      `;

      initLanguageForm();

      expect(consoleSpy).toHaveBeenCalled();
    });

    it('sets up form submit handler for duplicate check', () => {
      languageForm.allLanguages = { 'English': 1 };
      vi.spyOn(window, 'alert').mockImplementation(() => {});

      document.body.innerHTML = `
        <script type="application/json" id="language-form-config">
          {
            "languageId": 0,
            "languageName": "",
            "sourceLg": "auto",
            "targetLg": "en",
            "languageDefs": {},
            "allLanguages": {"English": 1}
          }
        </script>
        <form name="lg_form">
          <input id="LgName" name="LgName" value="English">
          <button type="submit">Save</button>
        </form>
      `;

      initLanguageForm();

      const form = document.querySelector('[name="lg_form"]') as HTMLFormElement;
      const submitEvent = new Event('submit', { cancelable: true });
      form.dispatchEvent(submitEvent);

      expect(submitEvent.defaultPrevented).toBe(true);
    });

    it('sets up cancel button handler', async () => {
      const { lwtFormCheck } = await import('../../../src/frontend/js/shared/forms/unloadformcheck');

      const originalLocation = window.location;
      delete (window as any).location;
      (window as any).location = { href: '', search: '' };

      document.body.innerHTML = `
        <script type="application/json" id="language-form-config">
          {
            "languageId": 1,
            "languageName": "Test",
            "sourceLg": "auto",
            "targetLg": "en",
            "languageDefs": {},
            "allLanguages": {}
          }
        </script>
        <form name="lg_form">
          <input name="LgName" value="Test">
        </form>
        <button data-action="cancel-form" data-redirect="/my-languages">Cancel</button>
      `;

      initLanguageForm();

      const cancelBtn = document.querySelector('[data-action="cancel-form"]') as HTMLButtonElement;
      cancelBtn.click();

      expect(lwtFormCheck.resetDirty).toHaveBeenCalled();
      expect(window.location.href).toBe('/my-languages');

      window.location = originalLocation;
    });
  });

  // ===========================================================================
  // Edge Cases
  // ===========================================================================

  describe('Edge Cases', () => {
    it('handles missing form elements gracefully', () => {
      document.body.innerHTML = '<form name="lg_form"></form>';

      const form = document.querySelector('[name="lg_form"]') as HTMLFormElement;

      expect(() => checkLanguageForm(form)).not.toThrow();
    });

    it('checkTranslatorStatus handles LibreTranslate URLs', () => {
      const spy = vi.spyOn(languageForm, 'checkLibreTranslateStatus');

      // Asterisk prefix is no longer used for popup - popup is stored in database
      languageForm.checkTranslatorStatus('http://localhost:5000?lwt_translator=libretranslate');

      expect(spy).toHaveBeenCalled();
    });

    it('checkTranslatorStatus handles invalid URLs gracefully', () => {
      expect(() => languageForm.checkTranslatorStatus('not-a-valid-url')).not.toThrow();
    });

    it('displayLibreTranslateError shows error message', () => {
      document.body.innerHTML = `
        <div id="translator_status"></div>
      `;

      languageForm.displayLibreTranslateError('Connection refused');

      const status = document.getElementById('translator_status') as HTMLElement;
      expect(status.innerHTML).toContain('LibreTranslate');
      expect(status.innerHTML).toContain('Connection refused');
    });
  });
});
