/**
 * Text reading initialization module.
 *
 * Handles initialization of the text reading interface including:
 * - Text-to-speech (TTS) setup
 * - State module configuration
 * - Reading position saving
 * - Audio position saving
 *
 * @license Unlicense <http://unlicense.org/>
 * @since 3.0.0
 */

import { t } from '@shared/i18n/translator';
import { showError } from '@shared/components/confirm_dialog';
import { onDomReady } from '@shared/utils/dom_ready';
import { getLangFromDict } from '@modules/vocabulary/services/dictionary';
import {
  goToLastPosition,
  saveReadingPosition,
  saveAudioPosition,
  saveAudioPositionBeacon,
  readRawTextAloud
} from '@shared/utils/user_interactions';
import { getAudioPlayer } from '@/media/html5_audio_player';
import { initNativeTooltips } from '@shared/components/native_tooltip';
import { resetReadingPosition } from '@modules/text/stores/reading_state';
import {
  initLanguageConfig,
  getDictionaryLinks,
  setTtsVoiceApi,
  getSourceLang
} from '@modules/language/stores/language_config';
import { initTextConfig, getTextId } from '@modules/text/stores/text_config';
import { initSettingsConfig } from '@shared/utils/settings_config';
import { resetAnswer } from '@modules/review/stores/review_state';

// Type definitions for text reader
interface TextReader {
  text: string;
  lang: string;
  rate: number;
}

/**
 * Configuration from text-header-config JSON element.
 */
interface TextHeaderConfig {
  textId: number;
  phoneticText: string;
  languageCode: string;
  voiceApi: string | null;
}

/**
 * Configuration from text-reading-config JSON element.
 */
interface TextReadingConfig {
  language?: Record<string, unknown>;
  text?: Record<string, unknown>;
  settings?: Record<string, unknown>;
}

// Declare the global variables that PHP will set
declare global {
  interface Window {
    // Legacy header view data (for backwards compatibility)
    _lwtPhoneticText?: string;
    _lwtLanguageCode?: string;
    _lwtVoiceApi?: string | null;
    _lwtTextId?: number;
    // LANG global variable
    LANG?: string;
  }
}

// Text reader state for TTS
let text_reader: TextReader | null = null;

/**
 * Load header configuration from JSON element or legacy window variables.
 */
function loadHeaderConfig(): TextHeaderConfig | null {
  // Try new JSON config first
  const configEl = document.getElementById('text-header-config');
  if (configEl) {
    try {
      return JSON.parse(configEl.textContent || '{}') as TextHeaderConfig;
    } catch (e) {
      console.error('Failed to parse text-header-config:', e);
    }
  }

  // Fall back to legacy window variables
  if (typeof window._lwtPhoneticText !== 'undefined') {
    return {
      textId: window._lwtTextId || 0,
      phoneticText: window._lwtPhoneticText || '',
      languageCode: window._lwtLanguageCode || '',
      voiceApi: window._lwtVoiceApi || null
    };
  }

  return null;
}

/**
 * Initialize TTS (Text-to-Speech) after Vite bundle is loaded.
 * Sets up the text_reader object with phonetic text and language settings.
 */
export function initTTS(): void {
  const config = loadHeaderConfig();
  if (!config) {
    return;
  }

  // Prefer sourceLang from config, fall back to parsing translator URL
  const dictLinks = getDictionaryLinks();
  const sourceLang = getSourceLang();
  const langFromDict = sourceLang || (typeof getLangFromDict === 'function'
    ? getLangFromDict(dictLinks.translator || '')
    : '');

  text_reader = {
    text: config.phoneticText || '',
    lang: langFromDict || config.languageCode || '',
    rate: 0.8
  };

  // Update TTS voice API in language config
  setTtsVoiceApi(config.voiceApi || '');

  // Store textId for later use
  window._lwtTextId = config.textId;
}

/**
 * Check browser compatibility and start reading.
 */
function initReading(): void {
  if (!('speechSynthesis' in window)) {
    void showError(t('common.dialog.no_speech'));
    return;
  }
  if (!text_reader) {
    initTTS();
  }
  if (!text_reader) {
    return;
  }
  // Prefer sourceLang from config, fall back to parsing translator URL
  const dictLinks = getDictionaryLinks();
  const sourceLang = getSourceLang();
  const langFromDict = sourceLang || (typeof getLangFromDict === 'function'
    ? getLangFromDict(dictLinks.translator || '')
    : '');
  const lang = langFromDict || text_reader.lang;
  if (typeof readRawTextAloud === 'function') {
    readRawTextAloud(text_reader.text, lang);
  }
}

/**
 * Start and stop the reading feature (TTS toggle).
 */
export function toggleReading(): void {
  const synth = window.speechSynthesis;
  if (synth === undefined) {
    void showError(t('common.dialog.no_speech'));
    return;
  }
  if (synth.speaking) {
    synth.cancel();
  } else {
    initReading();
  }
}

/**
 * Save text status (audio position) — used on unload/pagehide so we
 * route through sendBeacon, which is the only transport mobile
 * browsers don't cancel during teardown.
 */
export function saveTextStatus(): void {
  const textId = window._lwtTextId;
  if (typeof textId === 'undefined') {
    return;
  }

  if (typeof getAudioPlayer === 'function') {
    const player = getAudioPlayer();
    if (player) {
      // pagehide path: beacon survives close/navigate; on the rare
      // platform without beacon support, fall back to a best-effort
      // fetch and surface its rejection so we don't lose progress
      // silently.
      const queued = saveAudioPositionBeacon(textId, player.getCurrentTime());
      if (!queued) {
        saveAudioPosition(textId, player.getCurrentTime()).catch(err => {
          console.warn('saveAudioPosition fetch failed:', err);
        });
      }
    }
  }
}

/**
 * Throttle interval between periodic audio-position saves (ms).
 *
 * Save every 5s of real time, not every `timeupdate` (which fires
 * 4-66×/s depending on the browser). Five seconds is small enough
 * that an unexpected tab kill only loses a handful of seconds of
 * progress, large enough that a long reading session doesn't
 * hammer the API.
 */
const AUDIO_SAVE_INTERVAL_MS = 5000;

/**
 * Attach a debounced periodic audio-position save to the player.
 * Complements the pagehide beacon — if the user keeps the tab open
 * for hours we still want progress checkpointed.
 */
function startPeriodicAudioSave(textId: number): void {
  if (typeof getAudioPlayer !== 'function') {
    return;
  }
  const player = getAudioPlayer();
  if (!player) {
    return;
  }
  let lastSaveAt = 0;
  player.onTimeUpdateCallback((currentTime: number) => {
    const now = Date.now();
    if (now - lastSaveAt < AUDIO_SAVE_INTERVAL_MS) {
      return;
    }
    lastSaveAt = now;
    saveAudioPosition(textId, currentTime).catch(err => {
      console.warn('saveAudioPosition fetch failed:', err);
    });
  });
}

/**
 * Save the current reading position.
 */
function saveCurrentPosition(): void {
  let pos = 0;
  // First position from the top - find first visible word
  const visibleWords = document.querySelectorAll<HTMLElement>('.wsty:not(.hide)');
  if (visibleWords.length === 0) {
    return;
  }
  const firstWord = visibleWords[0];
  const topPos = window.scrollY - (firstWord.offsetHeight || 0);

  for (const word of visibleWords) {
    const rect = word.getBoundingClientRect();
    const offsetTop = rect.top + window.scrollY;
    if (offsetTop >= topPos) {
      const dataPos = word.getAttribute('data_pos');
      pos = parseInt(dataPos || '0', 10);
      break;
    }
  }
  saveReadingPosition(getTextId(), pos);
}

/**
 * Load text reading configuration from JSON element.
 */
function loadTextReadingConfig(): TextReadingConfig | null {
  const configEl = document.getElementById('text-reading-config');
  if (configEl) {
    try {
      return JSON.parse(configEl.textContent || '{}') as TextReadingConfig;
    } catch (e) {
      console.error('Failed to parse text-reading-config:', e);
    }
  }

  return null;
}

/**
 * Initialize the text reading interface.
 * Called after LWT Vite bundle is loaded.
 */
export function initTextReading(): void {
  // Load config and initialize state modules
  const config = loadTextReadingConfig();
  if (config) {
    // Initialize language config
    if (config.language) {
      const lang = config.language;
      initLanguageConfig({
        id: (lang.id as number) || 0,
        dictLink1: (lang.dict_link1 as string) || '',
        dictLink2: (lang.dict_link2 as string) || '',
        translatorLink: (lang.translator_link as string) || '',
        delimiter: (lang.delimiter as string) || '',
        wordParsing: lang.word_parsing as number | string || '',
        rtl: (lang.rtl as boolean) || false,
        ttsVoiceApi: (lang.ttsVoiceApi as string) || ''
      });
    }

    // Initialize text config
    if (config.text) {
      const text = config.text;
      initTextConfig({
        id: (text.id as number) || 0,
        annotations: text.annotations as Record<string, [unknown, string, string]> | number || 0
      });
    }

    // Initialize settings config
    if (config.settings) {
      const settings = config.settings;
      initSettingsConfig({
        hts: (settings.hts as number) || 0,
        wordStatusFilter: (settings.word_status_filter as string) || '',
        annotationsMode: (settings.annotations_mode as number) || 1
      });
    }
  }

  // Set LANG global - prefer sourceLang from config
  const dictLinks = getDictionaryLinks();
  const sourceLang = getSourceLang();
  if (sourceLang) {
    window.LANG = sourceLang;
  } else if (typeof getLangFromDict === 'function' && dictLinks.translator) {
    window.LANG = getLangFromDict(dictLinks.translator);
  }

  // Reset reading position (will be set by goToLastPosition)
  resetReadingPosition();

  // Initialize test answer state
  resetAnswer();

  // Set the language of the current frame
  if (window.LANG && window.LANG !== dictLinks.translator) {
    document.documentElement.setAttribute('lang', window.LANG);
  }

  // Initialize native tooltips (always enabled now that jQuery UI tooltips are removed)
  const thetext = document.getElementById('thetext');
  if (thetext) {
    initNativeTooltips(thetext);
  }

  // Set up reading position handling.
  goToLastPosition();
  window.addEventListener('beforeunload', saveCurrentPosition);
}

/**
 * Initialize the text reading header (TTS button, audio save).
 * Called when the header view is ready.
 */
export function initTextReadingHeader(): void {
  // pagehide is the modern, mobile-safe equivalent of beforeunload —
  // beforeunload often doesn't fire on Safari/Chrome mobile when the
  // user navigates away or backgrounds the tab, so progress was being
  // lost. pagehide also fires for the bfcache, so we still catch
  // back/forward navigation.
  window.addEventListener('pagehide', saveTextStatus);

  // Periodic checkpoint: covers the case where the user reads for an
  // hour with the tab open and the browser then crashes — without
  // this we'd have nothing to restore to.
  const textId = window._lwtTextId;
  if (typeof textId === 'number') {
    startPeriodicAudioSave(textId);
  }

  // Initialize TTS
  initTTS();

  // Bind click handler for TTS button
  const readTextButton = document.getElementById('readTextButton');
  if (readTextButton) {
    readTextButton.addEventListener('click', toggleReading);
  }
}

/**
 * Auto-initialize based on page context.
 * Detects which page we're on and initializes accordingly.
 */
export function autoInit(): void {
  // Check if we're on the text reading page
  const hasTextReadingConfig = document.getElementById('text-reading-config') !== null;
  const thetext = document.getElementById('thetext');
  if (thetext && hasTextReadingConfig) {
    initTextReading();
  }

  // Check if we have header TTS data
  const hasHeaderConfig = document.getElementById('text-header-config') !== null;
  if (hasHeaderConfig || typeof window._lwtPhoneticText !== 'undefined') {
    initTextReadingHeader();
  }
}

// Auto-initialize when DOM is ready (if Vite is already loaded)
onDomReady(() => {
  if (window.LWT_VITE_LOADED) {
    autoInit();
  }
});
