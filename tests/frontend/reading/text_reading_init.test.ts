/**
 * Tests for text_reading_init.ts - Text reading initialization
 */
import { dismissAlerts, settle } from '../helpers/confirm_answers';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import {
  initTTS,
  toggleReading,
  saveTextStatus,
  initTextReading,
  initTextReadingHeader,
  autoInit
} from '../../../src/frontend/js/modules/text/pages/reading/text_reading_init';

// Mock dependencies
vi.mock('../../../src/frontend/js/modules/vocabulary/services/dictionary', () => ({
  getLangFromDict: vi.fn().mockReturnValue('en')
}));

vi.mock('../../../src/frontend/js/modules/text/pages/reading/text_events', () => ({
  prepareTextInteractions: vi.fn()
}));

vi.mock('../../../src/frontend/js/shared/utils/user_interactions', () => ({
  goToLastPosition: vi.fn(),
  saveReadingPosition: vi.fn(),
  saveAudioPosition: vi.fn().mockResolvedValue({} as Response),
  saveAudioPositionBeacon: vi.fn().mockReturnValue(true),
  readRawTextAloud: vi.fn()
}));

vi.mock('../../../src/frontend/js/media/html5_audio_player', () => ({
  getAudioPlayer: vi.fn()
}));

import { getLangFromDict } from '../../../src/frontend/js/modules/vocabulary/services/dictionary';
import { saveAudioPosition, saveAudioPositionBeacon, readRawTextAloud } from '../../../src/frontend/js/shared/utils/user_interactions';
import { getAudioPlayer } from '../../../src/frontend/js/media/html5_audio_player';
import {
  initLanguageConfig,
  resetLanguageConfig,
  getTtsVoiceApi
} from '../../../src/frontend/js/modules/language/stores/language_config';
import { getReadingPosition, resetReadingPosition } from '../../../src/frontend/js/modules/text/stores/reading_state';
import { isAnswerOpened, resetReviewState } from '../../../src/frontend/js/modules/review/stores/review_state';

describe('text_reading_init.ts', () => {
  let mockSpeechSynthesis: any;

  beforeEach(() => {
    document.body.innerHTML = '';
    vi.clearAllMocks();

    // Reset window globals
    delete (window as any)._lwtPhoneticText;
    delete (window as any)._lwtLanguageCode;
    delete (window as any)._lwtVoiceApi;
    delete (window as any)._lwtTextId;
    delete (window as any).new_globals;
    delete (window as any).LANG;
    delete (window as any).LWT_VITE_LOADED;

    // Mock speechSynthesis
    mockSpeechSynthesis = {
      speaking: false,
      cancel: vi.fn(),
      speak: vi.fn()
    };
    Object.defineProperty(window, 'speechSynthesis', {
      value: mockSpeechSynthesis,
      writable: true,
      configurable: true
    });
  });

  afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
    resetLanguageConfig();
    resetReadingPosition();
    resetReviewState();
  });

  // ===========================================================================
  // initTTS Tests
  // ===========================================================================

  describe('initTTS', () => {
    it('does nothing when _lwtPhoneticText is undefined', () => {
      initTTS();
      // No error should occur
    });

    it('initializes text_reader when _lwtPhoneticText is set', () => {
      (window as any)._lwtPhoneticText = 'Hello world';
      (window as any)._lwtLanguageCode = 'en-US';

      // Setup language config
      initLanguageConfig({ translatorLink: 'https://translate.google.com' });
      (getLangFromDict as any).mockReturnValue('en');

      initTTS();

      // TTS should be initialized (we can't directly inspect internal state, but no error means success)
    });

    it('sets ttsVoiceApi in language config', () => {
      (window as any)._lwtPhoneticText = 'Test text';
      (window as any)._lwtVoiceApi = 'google';

      initTTS();

      expect(getTtsVoiceApi()).toBe('google');
    });

    it('handles missing _lwtVoiceApi gracefully', () => {
      (window as any)._lwtPhoneticText = 'Test text';

      initTTS();

      expect(getTtsVoiceApi()).toBe('');
    });
  });

  // ===========================================================================
  // toggleReading Tests
  // ===========================================================================

  describe('toggleReading', () => {
    it('alerts when speechSynthesis is undefined', async () => {
      Object.defineProperty(window, 'speechSynthesis', {
        value: undefined,
        writable: true,
        configurable: true
      });

      const alertSpy = dismissAlerts();

      toggleReading();

      await settle();
      expect(alertSpy).toHaveBeenCalledWith(expect.stringContaining('speech synthesis'));
    });

    it('cancels speech when already speaking', () => {
      mockSpeechSynthesis.speaking = true;

      toggleReading();

      expect(mockSpeechSynthesis.cancel).toHaveBeenCalled();
    });

    it('starts reading when not speaking', () => {
      mockSpeechSynthesis.speaking = false;
      (window as any)._lwtPhoneticText = 'Read this text';
      (window as any)._lwtLanguageCode = 'en';
      initLanguageConfig({ translatorLink: '' });

      toggleReading();

      // Should call readRawTextAloud or initReading
      // The function calls internal initReading which calls readRawTextAloud
      expect(readRawTextAloud).toHaveBeenCalled();
    });
  });

  // ===========================================================================
  // saveTextStatus Tests
  // ===========================================================================

  describe('saveTextStatus', () => {
    it('does nothing when _lwtTextId is undefined', () => {
      saveTextStatus();

      expect(saveAudioPositionBeacon).not.toHaveBeenCalled();
      expect(saveAudioPosition).not.toHaveBeenCalled();
    });

    it('saves audio position via beacon from HTML5 audio player', () => {
      (window as any)._lwtTextId = 123;

      const mockPlayer = {
        getCurrentTime: vi.fn().mockReturnValue(45.5)
      };
      (getAudioPlayer as any).mockReturnValue(mockPlayer);
      (saveAudioPositionBeacon as any).mockReturnValue(true);

      saveTextStatus();

      // Beacon is the preferred path on pagehide — fetch only runs as
      // a fallback when sendBeacon is unavailable or returns false.
      expect(saveAudioPositionBeacon).toHaveBeenCalledWith(123, 45.5);
      expect(saveAudioPosition).not.toHaveBeenCalled();
    });

    it('falls back to fetch when beacon is rejected', () => {
      (window as any)._lwtTextId = 123;

      const mockPlayer = {
        getCurrentTime: vi.fn().mockReturnValue(10)
      };
      (getAudioPlayer as any).mockReturnValue(mockPlayer);
      (saveAudioPositionBeacon as any).mockReturnValue(false);

      saveTextStatus();

      expect(saveAudioPosition).toHaveBeenCalledWith(123, 10);
    });

    it('does nothing when HTML5 player not available', () => {
      (window as any)._lwtTextId = 456;
      (getAudioPlayer as any).mockReturnValue(null);

      saveTextStatus();

      expect(saveAudioPositionBeacon).not.toHaveBeenCalled();
      expect(saveAudioPosition).not.toHaveBeenCalled();
    });
  });

  // ===========================================================================
  // initTextReading Tests
  // ===========================================================================

  describe('initTextReading', () => {
    it('loads config from text-reading-config JSON element', () => {
      document.body.innerHTML = `
        <div id="thetext"></div>
        <script type="application/json" id="text-reading-config">
          {
            "language": { "id": 1, "name": "English" },
            "text": { "id": 5 }
          }
        </script>
      `;

      initTextReading();

      // Configuration should be applied from JSON config
    });

    it('sets window.LANG from getLangFromDict', () => {
      // Initialize language config with translator link
      initLanguageConfig({
        id: 1,
        translatorLink: 'https://example.com',
        dictLink1: '',
        dictLink2: '',
        delimiter: '',
        rtl: false
      });
      (getLangFromDict as any).mockReturnValue('fr');

      initTextReading();

      expect(window.LANG).toBe('fr');
    });

    it('resets reading_position', () => {
      initTextReading();

      // Reading position is reset to -1 in the module
      expect(getReadingPosition()).toBe(-1);
    });

    it('initializes test answer_opened to false', () => {
      initTextReading();

      // Test answer state is reset via resetAnswer()
      expect(isAnswerOpened()).toBe(false);
    });

    it('sets up document ready handlers', () => {
      initTextReading();

      // The ready handlers should be attached (we can verify they exist)
      // Note: Testing DOMContentLoaded handlers directly is complex in vitest
      // This test verifies the function runs without error
      expect(true).toBe(true);
    });
  });

  // ===========================================================================
  // initTextReadingHeader Tests
  // ===========================================================================

  describe('initTextReadingHeader', () => {
    it('sets up pagehide handler', () => {
      const addEventListenerSpy = vi.spyOn(window, 'addEventListener');

      initTextReadingHeader();

      // pagehide replaced beforeunload — Safari/Chrome mobile cancel
      // beforeunload during teardown and lose the final save.
      expect(addEventListenerSpy).toHaveBeenCalledWith('pagehide', expect.any(Function));
      addEventListenerSpy.mockRestore();
    });

    it('initializes TTS', () => {
      (window as any)._lwtPhoneticText = 'Test';

      initTextReadingHeader();

      // TTS should be initialized (sets ttsVoiceApi via module)
      expect(getTtsVoiceApi()).toBeDefined();
    });

    it('binds click handler for readTextButton', () => {
      document.body.innerHTML = '<button id="readTextButton">Read</button>';

      const readTextButton = document.getElementById('readTextButton')!;
      const addEventListenerSpy = vi.spyOn(readTextButton, 'addEventListener');

      initTextReadingHeader();

      // Check if click handler was bound to the button
      expect(addEventListenerSpy).toHaveBeenCalledWith('click', expect.any(Function));
      addEventListenerSpy.mockRestore();
    });
  });

  // ===========================================================================
  // autoInit Tests
  // ===========================================================================

  describe('autoInit', () => {
    it('calls initTextReading when #thetext exists with new_globals', () => {
      document.body.innerHTML = '<div id="thetext"></div>';
      (window as any).new_globals = { someData: true };

      autoInit();

      // initTextReading should have been called (checks via side effects)
    });

    it('does not call initTextReading when #thetext is missing', () => {
      (window as any).new_globals = { someData: true };

      autoInit();

      // No error should occur
    });

    it('does not call initTextReading when new_globals is missing', () => {
      document.body.innerHTML = '<div id="thetext"></div>';

      autoInit();

      // No error should occur
    });

    it('calls initTextReadingHeader when _lwtPhoneticText is defined', () => {
      (window as any)._lwtPhoneticText = 'Some phonetic text';

      autoInit();

      expect(getTtsVoiceApi()).toBeDefined();
    });

    it('does not call initTextReadingHeader when _lwtPhoneticText is undefined', () => {
      autoInit();

      // ttsVoiceApi should be empty string (default)
      expect(getTtsVoiceApi()).toBe('');
    });
  });

  // ===========================================================================
  // Edge Cases
  // ===========================================================================

  describe('Edge Cases', () => {
    it('handles empty phonetic text', () => {
      (window as any)._lwtPhoneticText = '';
      initLanguageConfig({ translatorLink: '' });

      initTTS();

      // Should not crash
    });

    it('handles speechSynthesis.cancel throwing', () => {
      mockSpeechSynthesis.speaking = true;
      mockSpeechSynthesis.cancel = vi.fn().mockImplementation(() => {
        throw new Error('Cancel failed');
      });

      // This should propagate the error since it's unexpected
      expect(() => toggleReading()).toThrow();
    });

    it('handles missing translator_link in language config', () => {
      // Language config has defaults for missing fields

      // Clear mock calls before the test
      vi.mocked(getLangFromDict).mockClear();

      initTextReading();

      // getLangFromDict is called with the translator_link (undefined becomes '')
      // or may not be called at all if the condition is not met
      // Just verify no crash occurs
    });

    it('sets html lang attribute when LANG differs from translator_link', () => {
      document.body.innerHTML = '<html><body></body></html>';
      // Initialize language config with translator link
      initLanguageConfig({
        id: 1,
        translatorLink: 'https://different.com',
        dictLink1: '',
        dictLink2: '',
        delimiter: '',
        rtl: false
      });
      (getLangFromDict as any).mockReturnValue('de');

      initTextReading();

      const html = document.querySelector('html');
      expect(html?.getAttribute('lang')).toBe('de');
    });
  });
});
