/**
 * Bulk Translate - Alpine.js component for bulk translation.
 *
 * Handles dictionary lookups, form interactions, and Google Translate
 * integration for bulk translating unknown words.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.0.0
 * @since   3.1.0 Migrated to Alpine.js component
 */

import Alpine from 'alpinejs';
import { createTheDictUrl, openDictionaryPopup } from '@modules/vocabulary/services/dictionary';
import { selectToggle } from '@shared/forms/bulk_actions';
import { setDictionaryLinks } from '@modules/language/stores/language_config';
import { apiGet, apiPost } from '@shared/api/client';
import { tn } from '@shared/i18n/translator';

declare global {
  interface Window {
    WBLINK?: string;
    googleTranslateElementInit?: (() => void) | ((sourceLanguage: string, targetLanguage: string) => void);
  }
  // Google Translate API types
  const google: {
    translate: {
      TranslateElement: {
        new(config: {
          pageLanguage: string;
          layout: unknown;
          includedLanguages: string;
          autoDisplay: boolean;
        }, elementId: string): unknown;
        InlineLayout: {
          SIMPLE: unknown;
        };
      };
    };
  };
}

/** Response from POST /terms/bulk. */
interface BulkSaveResponse {
  success?: boolean;
  saved?: number;
  error?: string;
}

/** One term row as the bulk endpoint expects it. */
interface BulkTerm {
  lg: number;
  text: string;
  status: number;
  trans: string;
}

/**
 * Gather the `term[N][field]` inputs into a list.
 *
 * The translation inputs are injected client-side once Google Translate has
 * populated the cells, so reading the live FormData is what picks them up.
 *
 * @param data Submitted form data
 *
 * @returns Terms with a text and a language, in row order
 */
function collectTerms(data: FormData): BulkTerm[] {
  const rows = new Map<string, Record<string, string>>();

  for (const [key, value] of data.entries()) {
    const match = /^term\[(\d+)\]\[(\w+)\]$/.exec(key);
    if (!match) continue;
    const [, index, field] = match;
    const row = rows.get(index) ?? {};
    row[field] = String(value);
    rows.set(index, row);
  }

  const terms: BulkTerm[] = [];
  for (const row of rows.values()) {
    const text = (row.text ?? '').trim();
    const lg = parseInt(row.lg ?? '0', 10);
    if (text === '' || !Number.isFinite(lg) || lg <= 0) continue;
    terms.push({
      lg,
      text,
      status: parseInt(row.status ?? '1', 10),
      trans: (row.trans ?? '').trim()
    });
  }
  return terms;
}

/**
 * URL of the next batch, or null when this was the last one.
 *
 * Saved terms leave the unknown-word set, so the next offset moves back by
 * however many were just saved — the same arithmetic the controller did.
 *
 * @param form  The submitted form
 * @param saved Number of terms saved
 *
 * @returns Next batch URL, or null when the form carried no offset
 */
function nextBatchUrl(form: HTMLFormElement, saved: number): string | null {
  const data = new FormData(form);
  const rawOffset = data.get('offset');
  if (rawOffset === null) {
    return null;
  }

  const offset = parseInt(String(rawOffset), 10);
  if (!Number.isFinite(offset)) {
    return null;
  }

  const params = new URLSearchParams({
    tid: String(data.get('tid') ?? ''),
    offset: String(Math.max(0, offset - saved))
  });
  const sl = data.get('sl');
  const tl = data.get('tl');
  if (sl !== null) params.set('sl', String(sl));
  if (tl !== null) params.set('tl', String(tl));

  return `/word/bulk-translate?${params.toString()}`;
}

/**
 * Configuration for bulk translate component.
 */
export interface BulkTranslateConfig {
  dictionaries: {
    dict1: string;
    dict2: string;
    translate: string;
  };
  sourceLanguage: string;
  targetLanguage: string;
  textId: number;
  offset: number;
  limit: number;
}

/** One unknown word as GET /terms/unknown-for-translate returns it. */
export interface UnknownTerm {
  word: string;
  Ti2LgID: number;
  pos: number;
}


/**
 * Bulk translate Alpine component data interface.
 */
export interface BulkTranslateData {
  // Config
  dictConfig: {
    dict1: string;
    dict2: string;
    translator: string;
  };
  sourceLanguage: string;
  targetLanguage: string;

  // State
  isGoogleTranslateReady: boolean;
  submitButtonText: string;
  isSaving: boolean;
  savedCount: number;
  saveError: string;
  isDone(): boolean;
  hasSaveError(): boolean;
  savedMessage(): string;
  saveButtonClass(): string;
  submitTerms(event: Event): Promise<void>;
  hasOffset: boolean;

  // Terms fetched from /api/v1/terms/unknown-for-translate
  terms: UnknownTerm[];
  isLoadingTerms: boolean;
  termsError: string;
  textId: number;
  offset: number;
  limit: number;
  nextOffset: number | null;
  loadTerms(): Promise<void>;
  rowIndex(index: number): number;
  markedName(index: number): string;
  statusName(index: number): string;
  textName(index: number): string;
  langName(index: number): string;
  termCellId(index: number): string;
  transCellId(index: number): string;
  statusFieldId(index: number): string;
  textFieldId(index: number): string;
  lowercaseOf(term: UnknownTerm): string;

  // Methods
  init(): void;
  setupFormSubmission(): void;
  setupInteractions(): void;
  markAll(): void;
  markNone(): void;
  handleTermToggle(termId: number, checked: boolean): void;
  handleTermToggles(action: string): void;
  clickDictionary(element: HTMLElement): void;
  deleteTranslation(termId: number): void;
  setToLowercase(termId: number): void;
  updateSubmitButton(): void;
  setupGoogleTranslateCallback(): void;
}

/**
 * Alpine.js component for bulk translate functionality.
 */
export function bulkTranslateApp(config: BulkTranslateConfig = {
  dictionaries: { dict1: '', dict2: '', translate: '' },
  sourceLanguage: 'en',
  targetLanguage: 'en',
  textId: 0,
  offset: 0,
  limit: 100
}): BulkTranslateData {
  return {
    // Config
    dictConfig: {
      dict1: config.dictionaries.dict1,
      dict2: config.dictionaries.dict2,
      translator: config.dictionaries.translate
    },
    sourceLanguage: config.sourceLanguage,
    targetLanguage: config.targetLanguage,

    // State
    isGoogleTranslateReady: false,
    submitButtonText: 'Save',
    isSaving: false,
    savedCount: -1,
    saveError: '',
    hasOffset: false,

    terms: [],
    isLoadingTerms: true,
    termsError: '',
    textId: config.textId,
    offset: config.offset,
    limit: config.limit,
    nextOffset: null,

    /**
     * Initialize the component.
     */
    init(): void {
      // Read config from JSON script tag if available
      const configEl = document.getElementById('bulk-translate-config');
      if (configEl) {
        try {
          const jsonConfig: BulkTranslateConfig = JSON.parse(configEl.textContent || '{}');
          this.dictConfig = {
            dict1: jsonConfig.dictionaries?.dict1 ?? '',
            dict2: jsonConfig.dictionaries?.dict2 ?? '',
            translator: jsonConfig.dictionaries?.translate ?? ''
          };
          this.sourceLanguage = jsonConfig.sourceLanguage ?? 'en';
          this.targetLanguage = jsonConfig.targetLanguage ?? 'en';
          this.textId = Number(jsonConfig.textId) || 0;
          this.offset = Number(jsonConfig.offset) || 0;
          this.limit = Number(jsonConfig.limit) || 100;
        } catch {
          // Invalid JSON, use defaults
        }
      }

      // Terms arrive from the API; the rows are rendered by x-for, and the
      // hidden inputs they carry keep the existing FormData save path intact.
      void this.loadTerms();

      // Set dictionary links in language config for legacy support
      setDictionaryLinks(this.dictConfig);

      // Mark headers as not translatable
      document.querySelectorAll('h3, h4, title').forEach(el => {
        el.classList.add('notranslate');
      });

      // Setup Google Translate callback
      this.setupGoogleTranslateCallback();

      // Set up form submission handler
      this.setupFormSubmission();

      // Set up interactions when page is fully loaded
      window.addEventListener('load', () => this.setupInteractions());
    },

    /**
     * Fetch this text's still-unknown words for the current page.
     */
    async loadTerms(): Promise<void> {
      this.isLoadingTerms = true;
      this.termsError = '';

      const response = await apiGet<{
        terms: UnknownTerm[];
        has_more: boolean;
        next_offset: number | null;
      }>('/terms/unknown-for-translate', {
        text_id: this.textId,
        offset: this.offset,
        limit: this.limit
      });

      this.isLoadingTerms = false;

      if (response.error || !response.data) {
        this.termsError = response.error || 'Could not load terms';
        this.terms = [];
        return;
      }

      this.terms = response.data.terms ?? [];
      this.nextOffset = response.data.next_offset;
      // The save handler reads this to decide whether another batch follows.
      this.hasOffset = this.nextOffset !== null;

      // Google Translate and the dictionary handlers bind to rows, so they
      // can only run once the rows exist.
      void Alpine.nextTick(() => this.setupInteractions());
    },

    /**
     * Row numbers are 1-based: the field names the save path parses were
     * generated by a PHP counter starting at 1.
     *
     * @param index Zero-based x-for index
     *
     * @returns The 1-based row number
     */
    rowIndex(index: number): number {
      return index + 1;
    },

    markedName(index: number): string {
      return `marked[${index + 1}]`;
    },

    statusName(index: number): string {
      return `term[${index + 1}][status]`;
    },

    textName(index: number): string {
      return `term[${index + 1}][text]`;
    },

    langName(index: number): string {
      return `term[${index + 1}][lg]`;
    },

    termCellId(index: number): string {
      return `Term_${index + 1}`;
    },

    transCellId(index: number): string {
      return `Trans_${index + 1}`;
    },

    statusFieldId(index: number): string {
      return `Stat_${index + 1}`;
    },

    textFieldId(index: number): string {
      return `Text_${index + 1}`;
    },

    /**
     * The pre-filled translation cell starts as the lowercased term, matching
     * what the server-rendered table used to emit.
     *
     * @param term Term row
     *
     * @returns The term lowercased
     */
    lowercaseOf(term: UnknownTerm): string {
      return (term.word ?? '').toLowerCase();
    },


    /**
     * Whether a save has completed and there is no further page of terms.
     *
     * @returns True once the final batch has been saved
     */
    isDone(): boolean {
      return this.savedCount >= 0;
    },

    /**
     * Whether the last save failed.
     *
     * @returns True when an error should be shown
     */
    hasSaveError(): boolean {
      return this.saveError !== '';
    },

    /**
     * Confirmation text for the final batch.
     *
     * @returns Localised "saved N terms" message
     */
    savedMessage(): string {
      return tn('vocabulary.result.bulk_saved', this.savedCount);
    },

    /**
     * Loading modifier for the save button.
     *
     * @returns Bulma class list
     */
    saveButtonClass(): string {
      return this.isSaving ? 'is-loading' : '';
    },

    /**
     * Save the batch through the API instead of posting the form.
     *
     * The server used to save, echo a confirmation, and render the next batch
     * in the same response. Now the save is an API call and the next batch is
     * a plain GET, so no HTML comes back from the write.
     *
     * @param event Submit event
     */
    async submitTerms(event: Event): Promise<void> {
      event.preventDefault();

      const form = event.target as HTMLFormElement | null;
      if (!form || this.isSaving) return;

      // A term row being edited holds its real name in data_name.
      const currentTranslation = document.querySelector<HTMLElement>('[name="WoTranslation"]');
      if (currentTranslation) {
        currentTranslation.setAttribute('name', currentTranslation.getAttribute('data_name') ?? '');
      }

      const terms = collectTerms(new FormData(form));
      if (terms.length === 0) {
        this.saveError = 'No terms to save';
        return;
      }

      this.isSaving = true;
      this.saveError = '';

      const response = await apiPost<BulkSaveResponse>('/terms/bulk', { terms });
      const payload = response.data;

      if (response.error || !payload || payload.success !== true) {
        this.saveError = response.error || payload?.error || 'Failed to save terms';
        this.isSaving = false;
        return;
      }

      const saved = payload.saved ?? terms.length;
      const nextUrl = nextBatchUrl(form, saved);

      if (nextUrl !== null) {
        window.location.href = nextUrl;
        return;
      }

      // Last batch: report it here rather than on a server-rendered page.
      this.savedCount = saved;
      this.isSaving = false;
    },

    /**
     * Setup form submission handler.
     */
    setupFormSubmission(): void {
      // Submission is bound in the template via @submit; nothing to do here.
    },

    /**
     * Setup interactions after Google Translate populates.
     */
    setupInteractions(): void {
      // Wait for Google Translate to populate the .trans elements with <font> tags
      const displayTranslations = setInterval(() => {
        const transElements = document.querySelectorAll('.trans');
        const transFontElements = document.querySelectorAll('.trans>font');

        if (transFontElements.length === transElements.length) {
          // Convert translated text to input fields
          transElements.forEach(trans => {
            const txt = trans.textContent || '';
            const cnt = (trans.id || '').replace('Trans_', '');

            trans.classList.add('notranslate');

            // Built through the DOM, not a markup string: the translation is
            // third-party text, and a quote in it would otherwise close the
            // value attribute and let the rest inject attributes.
            const input = document.createElement('input');
            input.type = 'text';
            input.name = `term[${cnt}][trans]`;
            input.value = txt;
            input.maxLength = 100;
            input.className = 'respinput';

            const delTrans = document.createElement('div');
            delTrans.className = 'del_trans';

            trans.replaceChildren(input, delTrans);
          });

          // Add dictionary links after each term
          document.querySelectorAll<HTMLElement>('.term').forEach(term => {
            const parent = term.parentElement;
            if (parent) {
              parent.style.position = 'relative';
            }

            const dictLinksHtml =
              '<div class="dict">' +
              (this.dictConfig.dict1 ? '<span class="dict1">D1</span>' : '') +
              (this.dictConfig.dict2 ? '<span class="dict2">D2</span>' : '') +
              (this.dictConfig.translator ? '<span class="dict3">Tr</span>' : '') +
              '</div>';

            term.insertAdjacentHTML('afterend', dictLinksHtml);
          });

          // Clean up Google Translate elements
          document.querySelectorAll('iframe, #google_translate_element').forEach(el => el.remove());

          // Enable all checkboxes and inputs
          selectToggle(true, 'form1');
          document.querySelectorAll<HTMLInputElement | HTMLSelectElement>('[name^=term]').forEach(el => {
            el.disabled = false;
          });

          this.isGoogleTranslateReady = true;
          clearInterval(displayTranslations);
        }
      }, 300);
    },

    /**
     * Mark all terms for saving.
     */
    markAll(): void {
      this.submitButtonText = 'Save';
      const submitBtn = document.querySelector<HTMLInputElement>('input[type="submit"]');
      if (submitBtn) {
        submitBtn.value = 'Save';
      }
      selectToggle(true, 'form1');
      document.querySelectorAll<HTMLInputElement | HTMLSelectElement>('[name^=term]').forEach(el => {
        el.disabled = false;
      });
    },

    /**
     * Unmark all terms.
     */
    markNone(): void {
      this.submitButtonText = this.hasOffset ? 'Next' : 'End';
      const submitBtn = document.querySelector<HTMLInputElement>('input[type="submit"]');
      if (submitBtn) {
        submitBtn.value = this.submitButtonText;
      }
      selectToggle(false, 'form1');
      document.querySelectorAll<HTMLInputElement | HTMLSelectElement>('[name^=term]').forEach(el => {
        el.disabled = true;
      });
    },

    /**
     * Handle individual term checkbox toggle.
     */
    handleTermToggle(termId: number, checked: boolean): void {
      // Select all inputs related to this term
      const relatedInputs = document.querySelectorAll<HTMLInputElement | HTMLSelectElement>(
        `[name="term[${termId}][text]"], [name="term[${termId}][lg]"], [name="term[${termId}][status]"]`
      );
      relatedInputs.forEach(input => {
        input.disabled = !checked;
      });

      const transInput = document.querySelector<HTMLInputElement>(`#Trans_${termId} input`);
      if (transInput) {
        transInput.disabled = !checked;
      }

      this.updateSubmitButton();
    },

    /**
     * Handle bulk term toggles (status changes, lowercase, delete translation).
     */
    handleTermToggles(action: string): void {
      if (action === '6') {
        // Set to lowercase
        document.querySelectorAll<HTMLInputElement>('.markcheck:checked').forEach(checkbox => {
          const checkboxValue = checkbox.value;
          const termSpan = document.querySelector<HTMLElement>(`#Term_${checkboxValue} .term`);
          if (termSpan) {
            const lowerText = (termSpan.textContent || '').toLowerCase();
            termSpan.textContent = lowerText;
            const textInput = document.querySelector<HTMLInputElement>(`#Text_${checkboxValue}`);
            if (textInput) {
              textInput.value = lowerText;
            }
          }
        });
        return;
      }

      if (action === '7') {
        // Delete translation (set to *)
        document.querySelectorAll<HTMLInputElement>('.markcheck:checked').forEach(checkbox => {
          const checkboxValue = checkbox.value;
          const transInput = document.querySelector<HTMLInputElement>(`#Trans_${checkboxValue} input`);
          if (transInput) {
            transInput.value = '*';
          }
        });
        return;
      }

      // Set status for all checked terms
      document.querySelectorAll<HTMLInputElement>('.markcheck:checked').forEach(checkbox => {
        const checkboxValue = checkbox.value;
        const statSelect = document.querySelector<HTMLSelectElement>(`#Stat_${checkboxValue}`);
        if (statSelect) {
          statSelect.value = action;
        }
      });
    },

    /**
     * Handle click on a dictionary link.
     */
    clickDictionary(element: HTMLElement): void {
      let dictLink: string;

      if (element.classList.contains('dict1')) {
        dictLink = this.dictConfig.dict1;
      } else if (element.classList.contains('dict2')) {
        dictLink = this.dictConfig.dict2;
      } else if (element.classList.contains('dict3')) {
        dictLink = this.dictConfig.translator;
      } else {
        return;
      }

      window.WBLINK = dictLink;

      // Strip leading * (popup marker) if present
      if (dictLink.startsWith('*')) {
        dictLink = dictLink.substring(1);
      }

      const parent = element.parentElement;
      const prevSibling = parent?.previousElementSibling;
      const termText = prevSibling?.textContent || '';
      const dictUrl = createTheDictUrl(dictLink, termText);

      openDictionaryPopup(dictUrl);

      // Swap WoTranslation name attributes to track current input
      const currentTranslation = document.querySelector<HTMLElement>('[name="WoTranslation"]');
      if (currentTranslation) {
        currentTranslation.setAttribute('name', currentTranslation.getAttribute('data_name') ?? '');
      }

      const grandparent = parent?.parentElement;
      const nextRow = grandparent?.nextElementSibling;
      const el = nextRow?.firstElementChild as HTMLElement | null;
      if (el) {
        el.setAttribute('data_name', el.getAttribute('name') ?? '');
        el.setAttribute('name', 'WoTranslation');
      }
    },

    /**
     * Delete translation for a term.
     */
    deleteTranslation(termId: number): void {
      const transInput = document.querySelector<HTMLInputElement>(`#Trans_${termId} input`);
      if (transInput) {
        transInput.value = '';
        transInput.focus();
      }
    },

    /**
     * Set term to lowercase.
     */
    setToLowercase(termId: number): void {
      const termSpan = document.querySelector<HTMLElement>(`#Term_${termId} .term`);
      if (termSpan) {
        const lowerText = (termSpan.textContent || '').toLowerCase();
        termSpan.textContent = lowerText;
        const textInput = document.querySelector<HTMLInputElement>(`#Text_${termId}`);
        if (textInput) {
          textInput.value = lowerText;
        }
      }
    },

    /**
     * Update submit button text based on checkbox state.
     */
    updateSubmitButton(): void {
      const checkedCheckboxes = document.querySelectorAll('input[type="checkbox"]:checked');
      if (checkedCheckboxes.length) {
        this.submitButtonText = 'Save';
      } else {
        this.submitButtonText = this.hasOffset ? 'Next' : 'End';
      }
      const submitBtn = document.querySelector<HTMLInputElement>('input[type="submit"]');
      if (submitBtn) {
        submitBtn.value = this.submitButtonText;
      }
    },

    /**
     * Setup Google Translate callback.
     */
    setupGoogleTranslateCallback(): void {
      window.googleTranslateElementInit = () => {
        if (typeof google !== 'undefined' && google.translate) {
          new google.translate.TranslateElement({
            pageLanguage: this.sourceLanguage,
            layout: google.translate.TranslateElement.InlineLayout.SIMPLE,
            includedLanguages: this.targetLanguage,
            autoDisplay: false
          }, 'google_translate_element');
        }
      };
    }
  };
}

// Register Alpine component
if (typeof Alpine !== 'undefined') {
  Alpine.data('bulkTranslateApp', bulkTranslateApp);
}
