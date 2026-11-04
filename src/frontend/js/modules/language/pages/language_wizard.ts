/**
 * Language Wizard - Helper for setting up new languages.
 *
 * Extracted from Views/Language/wizard.php and Views/Language/select_pair.php
 * Provides UI for selecting native (L1) and study (L2) languages,
 * then auto-populates language form fields.
 *
 * Supports two modes:
 * - Inline wizard: embedded in the language form page
 * - Popup wizard: separate popup window that modifies opener's form
 *
 * @license Unlicense
 * @since 3.0.0
 */

import { onDomReady } from '@shared/utils/dom_ready';
import { saveSetting } from '@shared/utils/ajax_utilities';
import { lwtFormCheck } from '@shared/forms/unloadformcheck';
import { languageForm } from './language_form';
import { setBoundChecked, setBoundValue } from './bound_fields';

/**
 * Build a URL query string from an object (replaces $.param).
 */
function buildQueryString(params: Record<string, string>): string {
  return new URLSearchParams(params).toString();
}

/**
 * Get the value of an input or select element by selector.
 */
function getInputValue(selector: string, context: Document = document): string {
  const el = context.querySelector(selector) as HTMLInputElement | HTMLSelectElement | null;
  return el?.value ?? '';
}

/**
 * Set the value of a form field, keeping the Alpine form state in step.
 */
function setInputValue(selector: string, value: string | number): void {
  setBoundValue(document.querySelector<HTMLInputElement>(selector), String(value));
}

/**
 * Set the checked state of a checkbox, keeping the Alpine form state in step.
 */
function setChecked(selector: string, checked: boolean): void {
  setBoundChecked(document.querySelector<HTMLInputElement>(selector), checked);
}

/**
 * Language definition array structure.
 * [0] = Glosbe code (e.g., 'en')
 * [1] = ISO code (e.g., 'en')
 * [2] = Large text size flag (boolean)
 * [3] = Word character regexp
 * [4] = Sentence split regexp
 * [5] = Split each char flag
 * [6] = Remove spaces flag
 * [7] = Right-to-left flag
 * [8] = Parser type (empty or absent leaves it to be inferred)
 */
type LanguageDefinition = [
  string,   // Glosbe code
  string,   // ISO code
  boolean,  // Large text size
  string,   // Word character regexp
  string,   // Sentence split regexp
  boolean,  // Split each char
  boolean,  // Remove spaces
  boolean,  // RTL
  string?   // Parser type
];

/**
 * Configuration for language wizard.
 * Passed from PHP via JSON.
 */
export interface LanguageWizardConfig {
  languageDefs: Record<string, LanguageDefinition>;
}

// Declare globals that are set by the language form
declare global {
  interface Window {
    GGTRANSLATE: string;
    LIBRETRANSLATE: string;
    reloadDictURLs: (sourceLg: string, targetLg: string) => void;
  }
}

/**
 * Language wizard object.
 * Handles the wizard UI for setting up language configurations.
 */
export const languageWizard = {
  /** Language definitions loaded from config */
  langDefs: {} as Record<string, LanguageDefinition>,

  /**
   * Initialize the wizard with language definitions.
   */
  init(config: LanguageWizardConfig): void {
    this.langDefs = config.languageDefs;
  },

  /**
   * Handle L2 (study language) change.
   * Applies language-specific settings immediately.
   */
  onL2Change(): void {
    const l2 = getInputValue('#l2');
    if (l2 === '') return;

    const learningLg = this.langDefs[l2];
    if (!learningLg) return;

    // Set language name
    setInputValue('input[name="LgName"]', l2);

    // Set source language code
    setInputValue('input[name="LgSourceLang"]', learningLg[1]);

    // Set text size based on language needs
    setInputValue('input[name="LgTextSize"]', learningLg[2] ? 200 : 150);

    // Set language parsing rules
    setInputValue('input[name="LgRegexpSplitSentences"]', learningLg[4]);
    setInputValue('input[name="LgRegexpWordCharacters"]', learningLg[3]);
    setChecked('input[name="LgSplitEachChar"]', learningLg[5]);
    setChecked('input[name="LgRemoveSpaces"]', learningLg[6]);
    setChecked('input[name="LgRightToLeft"]', learningLg[7]);
    setInputValue('select[name="LgParserType"]', learningLg[8] ?? '');

    // Also update dictionary URLs if L1 is already set
    this.updateDictionaryUrls();
  },

  /**
   * Handle L1 (native language) change.
   * Updates dictionary and translation URLs.
   */
  onL1Change(): void {
    const l1 = getInputValue('#l1');
    if (l1 === '') return;

    saveSetting('currentnativelanguage', l1);

    const knownLg = this.langDefs[l1];
    if (!knownLg) return;

    // Set target language code
    setInputValue('input[name="LgTargetLang"]', knownLg[1]);

    // Update dictionary URLs if L2 is already set
    this.updateDictionaryUrls();
  },

  /**
   * Update dictionary and translation URLs based on current L1/L2 selection.
   * Only applies when both languages are selected.
   */
  updateDictionaryUrls(): void {
    const l1 = getInputValue('#l1');
    const l2 = getInputValue('#l2');

    if (l1 === '' || l2 === '' || l1 === l2) return;

    const learningLg = this.langDefs[l2];
    const knownLg = this.langDefs[l1];
    if (!learningLg || !knownLg) return;

    // L1 may be preselected from a saved setting, so onL1Change never ran
    setInputValue('input[name="LgTargetLang"]', knownLg[1]);

    // Reload dictionary URLs with the new language codes
    languageForm.reloadDictURLs(learningLg[1], knownLg[1]);

    // Build LibreTranslate URL
    const url = new URL(window.location.href);
    const baseUrl = url.protocol + '//' + url.hostname;

    window.LIBRETRANSLATE = baseUrl + ':5000/?' + buildQueryString({
      lwt_translator: 'libretranslate',
      lwt_translator_ajax: encodeURIComponent(baseUrl + ':5000/translate/?'),
      source: learningLg[1],
      target: knownLg[1],
      q: 'lwt_term'
    });

    // Set dictionary URL (Glosbe) and popup checkbox
    setInputValue(
      'input[name="LgDict1URI"]',
      'https://glosbe.com/' + learningLg[0] + '/' + knownLg[0] + '/lwt_term'
    );
    setChecked('input[name="LgDict1PopUp"]', true);

    // Set translator URL
    window.GGTRANSLATE = 'https://translate.google.com/?' + buildQueryString({
      sl: learningLg[1],
      tl: knownLg[1],
      text: 'lwt_term',
      op: 'translate'
    });
    setInputValue('input[name="LgGoogleTranslateURI"]', window.GGTRANSLATE);
  },

  /**
   * Toggle the wizard zone visibility.
   */
  toggleWizardZone(): void {
    const wizardZone = document.getElementById('wizard_zone');
    if (wizardZone) {
      // Simple toggle without animation (jQuery had 400ms animation)
      wizardZone.style.display = wizardZone.style.display === 'none' ? '' : 'none';
    }
  }
};


/**
 * Initialize language wizard from JSON config element.
 */
export function initLanguageWizard(): void {
  const configEl = document.getElementById('language-wizard-config');
  if (!configEl) return;

  let config: LanguageWizardConfig;
  try {
    config = JSON.parse(configEl.textContent || '{}');
  } catch (e) {
    console.error('Failed to parse language-wizard-config:', e);
    return;
  }

  languageWizard.init(config);

  // Set up event listeners for language selection
  const l2Select = document.getElementById('l2');
  if (l2Select) {
    l2Select.addEventListener('change', () => languageWizard.onL2Change());
  }

  const l1Select = document.getElementById('l1');
  if (l1Select) {
    l1Select.addEventListener('change', () => languageWizard.onL1Change());
  }

  const toggleHeader = document.querySelector('[data-action="wizard-toggle"]');
  if (toggleHeader) {
    toggleHeader.addEventListener('click', () => languageWizard.toggleWizardZone());
  }

  // Set up form check for unsaved changes
  lwtFormCheck.askBeforeExit();
}

// Auto-initialize on DOM ready if config element is present
onDomReady(() => {
  if (document.getElementById('language-wizard-config')) {
    initLanguageWizard();
  }
});
