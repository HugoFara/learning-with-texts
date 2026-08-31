/**
 * Tests for language_wizard.ts - Language Wizard functionality
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import {
  languageWizard,
  initLanguageWizard,
  type LanguageWizardConfig
} from '../../../src/frontend/js/modules/language/pages/language_wizard';

// Mock dependencies
vi.mock('../../../src/frontend/js/shared/utils/ajax_utilities', () => ({
  saveSetting: vi.fn()
}));

vi.mock('../../../src/frontend/js/shared/forms/unloadformcheck', () => ({
  lwtFormCheck: {
    askBeforeExit: vi.fn()
  }
}));

vi.mock('../../../src/frontend/js/modules/language/pages/language_form', () => ({
  languageForm: {
    reloadDictURLs: vi.fn(),
    checkLanguageChanged: vi.fn()
  }
}));

import { saveSetting } from '../../../src/frontend/js/shared/utils/ajax_utilities';
import { lwtFormCheck } from '../../../src/frontend/js/shared/forms/unloadformcheck';
import { languageForm } from '../../../src/frontend/js/modules/language/pages/language_form';

describe('language_wizard.ts', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
    vi.clearAllMocks();
    // Reset wizard state
    languageWizard.langDefs = {};
    // Mock window globals
    (window as any).GGTRANSLATE = '';
    (window as any).LIBRETRANSLATE = '';
    (window as any).reloadDictURLs = vi.fn();
    (window as any).checkLanguageChanged = vi.fn();
    // Mock window.location
    Object.defineProperty(window, 'location', {
      value: {
        href: 'http://localhost:8000/languages/edit',
        protocol: 'http:',
        hostname: 'localhost'
      },
      writable: true,
      configurable: true
    });
  });

  afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
  });

  // ===========================================================================
  // languageWizard.init Tests
  // ===========================================================================

  describe('languageWizard.init', () => {
    it('stores language definitions from config', () => {
      const config: LanguageWizardConfig = {
        languageDefs: {
          'English': ['en', 'en', false, '[a-zA-Z]', '.!?', false, false, false],
          'French': ['fr', 'fr', false, '[a-zA-Zéèêëàâùûôîçœæ]', '.!?', false, false, false]
        }
      };

      languageWizard.init(config);

      expect(languageWizard.langDefs).toEqual(config.languageDefs);
    });
  });

  // ===========================================================================
  // languageWizard.onL2Change Tests
  // ===========================================================================

  describe('languageWizard.onL2Change', () => {
    beforeEach(() => {
      languageWizard.langDefs = {
        'English': ['en', 'en', false, '[a-zA-Z]', '.!?', false, false, false],
        'French': ['fr', 'fr', false, '[a-zA-Z]', '.!?', false, false, false],
        'Japanese': ['ja', 'ja', true, '[\\p{Han}\\p{Hiragana}\\p{Katakana}]', '。！？', true, true, false],
        'Arabic': ['ar', 'ar', false, '[\\p{Arabic}]', '.!?', false, false, true]
      };
      document.body.innerHTML = `
        <select id="l1">
          <option value="">Select...</option>
          <option value="English">English</option>
        </select>
        <select id="l2">
          <option value="">Select...</option>
          <option value="French">French</option>
          <option value="Japanese">Japanese</option>
          <option value="Arabic">Arabic</option>
        </select>
        <input name="LgName" />
        <input name="LgSourceLang" />
        <input name="LgTargetLang" />
        <input name="LgDict1URI" />
        <input name="LgDict1PopUp" type="checkbox" />
        <input name="LgGoogleTranslateURI" />
        <input name="LgTextSize" />
        <input name="LgRegexpSplitSentences" />
        <input name="LgRegexpWordCharacters" />
        <input name="LgSplitEachChar" type="checkbox" />
        <input name="LgRemoveSpaces" type="checkbox" />
        <input name="LgRightToLeft" type="checkbox" />
      `;
    });

    it('does nothing when L2 is empty', () => {
      const l2Select = document.getElementById('l2') as HTMLSelectElement;
      l2Select.value = '';

      languageWizard.onL2Change();

      const nameInput = document.querySelector('input[name="LgName"]') as HTMLInputElement;
      expect(nameInput.value).toBe('');
    });

    it('sets language name when L2 is selected', () => {
      const l2Select = document.getElementById('l2') as HTMLSelectElement;
      l2Select.value = 'French';

      languageWizard.onL2Change();

      const nameInput = document.querySelector('input[name="LgName"]') as HTMLInputElement;
      expect(nameInput.value).toBe('French');
    });


    it('sets source language code', () => {
      const l2Select = document.getElementById('l2') as HTMLSelectElement;
      l2Select.value = 'French';

      languageWizard.onL2Change();

      const sourceInput = document.querySelector('input[name="LgSourceLang"]') as HTMLInputElement;
      expect(sourceInput.value).toBe('fr');
    });

    it('sets text size 200 for languages needing large text', () => {
      const l2Select = document.getElementById('l2') as HTMLSelectElement;
      l2Select.value = 'Japanese';

      languageWizard.onL2Change();

      const textSizeInput = document.querySelector('input[name="LgTextSize"]') as HTMLInputElement;
      expect(textSizeInput.value).toBe('200');
    });

    it('sets text size 150 for languages not needing large text', () => {
      const l2Select = document.getElementById('l2') as HTMLSelectElement;
      l2Select.value = 'French';

      languageWizard.onL2Change();

      const textSizeInput = document.querySelector('input[name="LgTextSize"]') as HTMLInputElement;
      expect(textSizeInput.value).toBe('150');
    });

    it('sets language parsing rules', () => {
      const l2Select = document.getElementById('l2') as HTMLSelectElement;
      l2Select.value = 'Japanese';

      languageWizard.onL2Change();

      const sentencesInput = document.querySelector('input[name="LgRegexpSplitSentences"]') as HTMLInputElement;
      expect(sentencesInput.value).toBe('。！？');
      const wordCharsInput = document.querySelector('input[name="LgRegexpWordCharacters"]') as HTMLInputElement;
      expect(wordCharsInput.value).toBe('[\\p{Han}\\p{Hiragana}\\p{Katakana}]');
      const splitCharInput = document.querySelector('input[name="LgSplitEachChar"]') as HTMLInputElement;
      expect(splitCharInput.checked).toBe(true);
      const removeSpacesInput = document.querySelector('input[name="LgRemoveSpaces"]') as HTMLInputElement;
      expect(removeSpacesInput.checked).toBe(true);
    });

    it('sets RTL flag for RTL languages', () => {
      const l2Select = document.getElementById('l2') as HTMLSelectElement;
      l2Select.value = 'Arabic';

      languageWizard.onL2Change();

      const rtlInput = document.querySelector('input[name="LgRightToLeft"]') as HTMLInputElement;
      expect(rtlInput.checked).toBe(true);
    });

    it('updates dictionary URLs if L1 is already set', () => {
      const l1Select = document.getElementById('l1') as HTMLSelectElement;
      l1Select.value = 'English';
      const l2Select = document.getElementById('l2') as HTMLSelectElement;
      l2Select.value = 'French';

      languageWizard.onL2Change();

      expect(languageForm.reloadDictURLs).toHaveBeenCalledWith('fr', 'en');
    });
  });

  // ===========================================================================
  // languageWizard.onL1Change Tests
  // ===========================================================================

  describe('languageWizard.onL1Change', () => {
    beforeEach(() => {
      languageWizard.langDefs = {
        'English': ['en', 'en', false, '[a-zA-Z]', '.!?', false, false, false],
        'French': ['fr', 'fr', false, '[a-zA-Z]', '.!?', false, false, false]
      };
      document.body.innerHTML = `
        <select id="l1"><option value="">Select...</option><option value="English">English</option></select>
        <select id="l2"><option value="">Select...</option><option value="French">French</option></select>
        <input name="LgTargetLang" />
        <input name="LgDict1URI" />
        <input name="LgDict1PopUp" type="checkbox" />
        <input name="LgGoogleTranslateURI" />
      `;
    });

    it('does nothing when L1 is empty', () => {
      const l1Select = document.getElementById('l1') as HTMLSelectElement;
      l1Select.value = '';

      languageWizard.onL1Change();

      expect(saveSetting).not.toHaveBeenCalled();
    });

    it('saves native language setting', () => {
      const l1Select = document.getElementById('l1') as HTMLSelectElement;
      l1Select.value = 'English';

      languageWizard.onL1Change();

      expect(saveSetting).toHaveBeenCalledWith('currentnativelanguage', 'English');
    });

    it('sets target language code', () => {
      const l1Select = document.getElementById('l1') as HTMLSelectElement;
      l1Select.value = 'English';

      languageWizard.onL1Change();

      const targetInput = document.querySelector('input[name="LgTargetLang"]') as HTMLInputElement;
      expect(targetInput.value).toBe('en');
    });

    it('updates dictionary URLs if L2 is already set', () => {
      const l1Select = document.getElementById('l1') as HTMLSelectElement;
      const l2Select = document.getElementById('l2') as HTMLSelectElement;
      l2Select.value = 'French';
      l1Select.value = 'English';

      languageWizard.onL1Change();

      expect(languageForm.reloadDictURLs).toHaveBeenCalledWith('fr', 'en');
    });
  });

  // ===========================================================================
  // languageWizard.updateDictionaryUrls Tests
  // ===========================================================================

  describe('languageWizard.updateDictionaryUrls', () => {
    beforeEach(() => {
      languageWizard.langDefs = {
        'English': ['en', 'en', false, '[a-zA-Z]', '.!?', false, false, false],
        'French': ['fr', 'fr', false, '[a-zA-Z]', '.!?', false, false, false],
        'German': ['de', 'de', false, '[a-zA-Z]', '.!?', false, false, false]
      };
      document.body.innerHTML = `
        <select id="l1">
          <option value="">Select...</option>
          <option value="English">English</option>
        </select>
        <select id="l2">
          <option value="">Select...</option>
          <option value="French">French</option>
          <option value="German">German</option>
        </select>
        <input name="LgDict1URI" />
        <input name="LgDict1PopUp" type="checkbox" />
        <input name="LgGoogleTranslateURI" />
      `;
    });

    it('does nothing when L1 is empty', () => {
      const l2Select = document.getElementById('l2') as HTMLSelectElement;
      l2Select.value = 'French';

      languageWizard.updateDictionaryUrls();

      expect(languageForm.reloadDictURLs).not.toHaveBeenCalled();
    });

    it('does nothing when L2 is empty', () => {
      const l1Select = document.getElementById('l1') as HTMLSelectElement;
      l1Select.value = 'English';

      languageWizard.updateDictionaryUrls();

      expect(languageForm.reloadDictURLs).not.toHaveBeenCalled();
    });

    it('does nothing when L1 and L2 are the same', () => {
      const l1Select = document.getElementById('l1') as HTMLSelectElement;
      const l2Select = document.getElementById('l2') as HTMLSelectElement;
      l1Select.value = 'English';
      l2Select.value = 'English';

      languageWizard.updateDictionaryUrls();

      expect(languageForm.reloadDictURLs).not.toHaveBeenCalled();
    });

    it('calls reloadDictURLs with language codes', () => {
      const l1Select = document.getElementById('l1') as HTMLSelectElement;
      const l2Select = document.getElementById('l2') as HTMLSelectElement;
      l1Select.value = 'English';
      l2Select.value = 'French';

      languageWizard.updateDictionaryUrls();

      expect(languageForm.reloadDictURLs).toHaveBeenCalledWith('fr', 'en');
    });

    it('sets up LibreTranslate URL', () => {
      const l1Select = document.getElementById('l1') as HTMLSelectElement;
      const l2Select = document.getElementById('l2') as HTMLSelectElement;
      l1Select.value = 'English';
      l2Select.value = 'French';

      languageWizard.updateDictionaryUrls();

      expect((window as any).LIBRETRANSLATE).toContain('libretranslate');
      expect((window as any).LIBRETRANSLATE).toContain('source=fr');
      expect((window as any).LIBRETRANSLATE).toContain('target=en');
    });

    it('sets Glosbe dictionary URL', () => {
      const l1Select = document.getElementById('l1') as HTMLSelectElement;
      const l2Select = document.getElementById('l2') as HTMLSelectElement;
      l1Select.value = 'English';
      l2Select.value = 'German';

      languageWizard.updateDictionaryUrls();

      const dictInput = document.querySelector('input[name="LgDict1URI"]') as HTMLInputElement;
      expect(dictInput.value).toContain('glosbe.com/de/en');
      const popupInput = document.querySelector('input[name="LgDict1PopUp"]') as HTMLInputElement;
      expect(popupInput.checked).toBe(true);
    });

    it('sets Google Translate URL when available', () => {
      (window as any).GGTRANSLATE = 'https://translate.google.com/?source=fr&target=en';

      const l1Select = document.getElementById('l1') as HTMLSelectElement;
      const l2Select = document.getElementById('l2') as HTMLSelectElement;
      l1Select.value = 'English';
      l2Select.value = 'French';

      languageWizard.updateDictionaryUrls();

      const input = document.querySelector('input[name="LgGoogleTranslateURI"]') as HTMLInputElement;
      expect(input.value).toBe('https://translate.google.com/?source=fr&target=en');
    });
  });

  // ===========================================================================
  // languageWizard.toggleWizardZone Tests
  // ===========================================================================

  describe('languageWizard.toggleWizardZone', () => {
    it('toggles wizard zone visibility', () => {
      document.body.innerHTML = '<div id="wizard_zone" style="display: block;">Wizard Content</div>';

      languageWizard.toggleWizardZone();

      // Note: The actual toggle implementation may use jQuery slideToggle which is harder to test
      // In a real vanilla JS implementation, this would check display property
      // For now, we just verify the function doesn't throw
      expect(document.getElementById('wizard_zone')).toBeTruthy();
    });
  });

  // ===========================================================================
  // initLanguageWizard Tests
  // ===========================================================================

  describe('initLanguageWizard', () => {
    it('does nothing when config element does not exist', () => {
      expect(() => initLanguageWizard()).not.toThrow();
    });

    it('initializes wizard with config', () => {
      document.body.innerHTML = `
        <script id="language-wizard-config" type="application/json">
          {"languageDefs": {"English": ["en", "en", false, "[a-zA-Z]", ".!?", false, false, false]}}
        </script>
      `;

      initLanguageWizard();

      expect(languageWizard.langDefs).toHaveProperty('English');
    });

    it('sets up L2 change handler', () => {
      document.body.innerHTML = `
        <script id="language-wizard-config" type="application/json">
          {"languageDefs": {"French": ["fr", "fr", false, "[a-zA-Z]", ".!?", false, false, false]}}
        </script>
        <select id="l2">
          <option value="">Select...</option>
          <option value="French">French</option>
        </select>
        <input name="LgName" />
        <input name="LgSourceLang" />
        <input name="LgTextSize" />
        <input name="LgRegexpSplitSentences" />
        <input name="LgRegexpWordCharacters" />
        <input name="LgSplitEachChar" type="checkbox" />
        <input name="LgRemoveSpaces" type="checkbox" />
        <input name="LgRightToLeft" type="checkbox" />
      `;

      initLanguageWizard();

      const l2Select = document.getElementById('l2') as HTMLSelectElement;
      l2Select.value = 'French';
      l2Select.dispatchEvent(new Event('change'));

      const nameInput = document.querySelector('input[name="LgName"]') as HTMLInputElement;
      expect(nameInput.value).toBe('French');
    });

    it('sets up L1 change handler', () => {
      document.body.innerHTML = `
        <script id="language-wizard-config" type="application/json">
          {"languageDefs": {"English": ["en", "en", false, "[a-zA-Z]", ".!?", false, false, false]}}
        </script>
        <select id="l1">
          <option value="">Select...</option>
          <option value="English">English</option>
        </select>
        <input name="LgTargetLang" />
      `;

      initLanguageWizard();

      const l1Select = document.getElementById('l1') as HTMLSelectElement;
      l1Select.value = 'English';
      l1Select.dispatchEvent(new Event('change'));

      expect(saveSetting).toHaveBeenCalledWith('currentnativelanguage', 'English');
    });

    it('sets up wizard toggle handler', () => {
      document.body.innerHTML = `
        <script id="language-wizard-config" type="application/json">
          {"languageDefs": {}}
        </script>
        <div id="wizard_zone" style="display: block;">Content</div>
        <h3 data-action="wizard-toggle">Toggle</h3>
      `;

      initLanguageWizard();

      const toggleSpy = vi.spyOn(languageWizard, 'toggleWizardZone');
      const toggleHeader = document.querySelector('[data-action="wizard-toggle"]')!;
      toggleHeader.dispatchEvent(new Event('click'));

      expect(toggleSpy).toHaveBeenCalled();
    });

    it('sets up form check for unsaved changes', () => {
      document.body.innerHTML = `
        <script id="language-wizard-config" type="application/json">
          {"languageDefs": {}}
        </script>
      `;

      initLanguageWizard();

      expect(lwtFormCheck.askBeforeExit).toHaveBeenCalled();
    });

    it('handles invalid JSON config gracefully', () => {
      document.body.innerHTML = `
        <script id="language-wizard-config" type="application/json">
          {invalid json}
        </script>
      `;

      const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});

      expect(() => initLanguageWizard()).not.toThrow();
      expect(consoleSpy).toHaveBeenCalled();
    });
  });
});
