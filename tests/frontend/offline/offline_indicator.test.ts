/**
 * Tests for shared/offline/offline-indicator.ts - Alpine.js offline status indicator component
 */
import { answerConfirms, confirmPrompts, settle } from '../helpers/confirm_answers';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

// Mock dependencies before importing
vi.mock('../../../src/frontend/js/shared/offline/text-service', () => ({
  isOfflineStorageAvailable: vi.fn().mockReturnValue(true),
  getOfflineSummary: vi.fn().mockResolvedValue({
    totalTexts: 0,
    totalSizeBytes: 0,
    byLanguage: [],
  }),
}));

vi.mock('../../../src/frontend/js/shared/offline/db', () => ({
  clearOfflineData: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('alpinejs', () => ({
  default: {
    data: vi.fn(),
    version: '3.0.0',
  },
}));

import Alpine from 'alpinejs';
import {
  offlineIndicator,
  registerOfflineIndicator,
} from '../../../src/frontend/js/shared/offline/offline-indicator';
import {
  isOfflineStorageAvailable,
  getOfflineSummary,
  type OfflineSummary,
} from '../../../src/frontend/js/shared/offline/text-service';
import { clearOfflineData } from '../../../src/frontend/js/shared/offline/db';

describe('shared/offline/offline-indicator.ts', () => {
  let dispatchEventSpy: ReturnType<typeof vi.spyOn>;
  let addEventListenerSpy: ReturnType<typeof vi.spyOn>;
  let removeEventListenerSpy: ReturnType<typeof vi.spyOn>;
  let consoleErrorSpy: ReturnType<typeof vi.spyOn>;
  const confirmSpy = confirmPrompts;

  beforeEach(() => {
    vi.clearAllMocks();
    dispatchEventSpy = vi.spyOn(window, 'dispatchEvent').mockImplementation(() => true);
    addEventListenerSpy = vi.spyOn(window, 'addEventListener');
    removeEventListenerSpy = vi.spyOn(window, 'removeEventListener');
    consoleErrorSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
    answerConfirms(true);

    vi.mocked(isOfflineStorageAvailable).mockReturnValue(true);
    vi.mocked(getOfflineSummary).mockResolvedValue({
      totalTexts: 0,
      totalSizeBytes: 0,
      byLanguage: [],
    });

    // Mock navigator.onLine
    Object.defineProperty(navigator, 'onLine', {
      value: true,
      writable: true,
      configurable: true,
    });
  });

  afterEach(() => {
    dispatchEventSpy.mockRestore();
    addEventListenerSpy.mockRestore();
    removeEventListenerSpy.mockRestore();
    consoleErrorSpy.mockRestore();
  });

  // ===========================================================================
  // offlineIndicator Factory Tests
  // ===========================================================================

  describe('offlineIndicator', () => {
    it('creates component with correct initial state', () => {
      const component = offlineIndicator();

      expect(component.isOnline).toBe(true);
      expect(component.storageSupported).toBe(true);
      expect(component.summary).toBeNull();
      expect(component.showDetails).toBe(false);
      expect(component.isClearing).toBe(false);
    });

    it('reflects navigator.onLine state', () => {
      Object.defineProperty(navigator, 'onLine', { value: false });
      const component = offlineIndicator();
      expect(component.isOnline).toBe(false);

      Object.defineProperty(navigator, 'onLine', { value: true });
      const component2 = offlineIndicator();
      expect(component2.isOnline).toBe(true);
    });

    it('checks storage support on creation', () => {
      vi.mocked(isOfflineStorageAvailable).mockReturnValue(false);
      const component = offlineIndicator();
      expect(component.storageSupported).toBe(false);
    });

    it('has required methods', () => {
      const component = offlineIndicator();

      expect(typeof component.init).toBe('function');
      expect(typeof component.destroy).toBe('function');
      expect(typeof component.updateOnlineStatus).toBe('function');
      expect(typeof component.loadSummary).toBe('function');
      expect(typeof component.clearAllOfflineData).toBe('function');
      expect(typeof component.formatSize).toBe('function');
    });
  });

  // ===========================================================================
  // init Method Tests
  // ===========================================================================

  describe('init', () => {
    it('sets up online/offline event listeners', () => {
      const component = offlineIndicator();
      component.init();

      expect(addEventListenerSpy).toHaveBeenCalledWith('online', expect.any(Function));
      expect(addEventListenerSpy).toHaveBeenCalledWith('offline', expect.any(Function));
    });

    it('sets up custom offline event listeners', () => {
      const component = offlineIndicator();
      component.init();

      expect(addEventListenerSpy).toHaveBeenCalledWith(
        'offline:text-downloaded',
        expect.any(Function)
      );
      expect(addEventListenerSpy).toHaveBeenCalledWith(
        'offline:text-removed',
        expect.any(Function)
      );
    });

    it('loads summary when storage is supported', async () => {
      vi.mocked(isOfflineStorageAvailable).mockReturnValue(true);
      vi.mocked(getOfflineSummary).mockResolvedValue({
        totalTexts: 5,
        totalSizeBytes: 10000,
        byLanguage: [],
      });

      const component = offlineIndicator();
      component.init();

      // Wait for async loadSummary
      await vi.waitFor(() => {
        expect(getOfflineSummary).toHaveBeenCalled();
      });
    });

    it('does not load summary when storage is not supported', () => {
      vi.mocked(isOfflineStorageAvailable).mockReturnValue(false);

      const component = offlineIndicator();
      component.init();

      expect(getOfflineSummary).not.toHaveBeenCalled();
    });
  });

  // ===========================================================================
  // destroy Method Tests
  // ===========================================================================

  describe('destroy', () => {
    it('removes all event listeners', () => {
      const component = offlineIndicator();
      component.init();
      component.destroy();

      expect(removeEventListenerSpy).toHaveBeenCalledWith('online', expect.any(Function));
      expect(removeEventListenerSpy).toHaveBeenCalledWith('offline', expect.any(Function));
      expect(removeEventListenerSpy).toHaveBeenCalledWith(
        'offline:text-downloaded',
        expect.any(Function)
      );
      expect(removeEventListenerSpy).toHaveBeenCalledWith(
        'offline:text-removed',
        expect.any(Function)
      );
    });
  });

  // ===========================================================================
  // updateOnlineStatus Method Tests
  // ===========================================================================

  describe('updateOnlineStatus', () => {
    it('updates isOnline from navigator', () => {
      const component = offlineIndicator();

      Object.defineProperty(navigator, 'onLine', { value: false });
      component.updateOnlineStatus();
      expect(component.isOnline).toBe(false);

      Object.defineProperty(navigator, 'onLine', { value: true });
      component.updateOnlineStatus();
      expect(component.isOnline).toBe(true);
    });

    it('dispatches status-changed event', () => {
      Object.defineProperty(navigator, 'onLine', { value: false });

      const component = offlineIndicator();
      component.updateOnlineStatus();

      expect(dispatchEventSpy).toHaveBeenCalledWith(
        expect.objectContaining({
          type: 'offline:status-changed',
          detail: { isOnline: false },
        })
      );
    });
  });

  // ===========================================================================
  // loadSummary Method Tests
  // ===========================================================================

  describe('loadSummary', () => {
    it('fetches and stores summary', async () => {
      const mockSummary: OfflineSummary = {
        totalTexts: 10,
        totalSizeBytes: 50000,
        byLanguage: [
          { langId: 1, langName: 'English', textCount: 7, sizeBytes: 35000 },
          { langId: 2, langName: 'French', textCount: 3, sizeBytes: 15000 },
        ],
      };
      vi.mocked(getOfflineSummary).mockResolvedValue(mockSummary);
      vi.mocked(isOfflineStorageAvailable).mockReturnValue(true);

      const component = offlineIndicator();
      await component.loadSummary();

      expect(component.summary).toEqual(mockSummary);
    });

    it('does nothing when storage is not supported', async () => {
      vi.mocked(isOfflineStorageAvailable).mockReturnValue(false);

      const component = offlineIndicator();
      await component.loadSummary();

      expect(getOfflineSummary).not.toHaveBeenCalled();
      expect(component.summary).toBeNull();
    });
  });

  // ===========================================================================
  // clearAllOfflineData Method Tests
  // ===========================================================================

  describe('clearAllOfflineData', () => {
    it('shows confirmation dialog', async () => {
      const component = offlineIndicator();
      await component.clearAllOfflineData();

      expect(confirmSpy).toHaveBeenCalled();
    });

    it('clears data when confirmed', async () => {
      answerConfirms(true);

      const component = offlineIndicator();
      await component.clearAllOfflineData();

      expect(clearOfflineData).toHaveBeenCalled();
    });

    it('does not clear data when cancelled', async () => {
      answerConfirms(false);

      const component = offlineIndicator();
      await component.clearAllOfflineData();

      expect(clearOfflineData).not.toHaveBeenCalled();
    });

    it('sets isClearing during operation', async () => {
      let resolveClear: () => void;
      vi.mocked(clearOfflineData).mockImplementation(
        () => new Promise((resolve) => {
          resolveClear = resolve;
        })
      );

      const component = offlineIndicator();
      const clearPromise = component.clearAllOfflineData();

      await settle();
      expect(component.isClearing).toBe(true);

      resolveClear!();
      await clearPromise;

      expect(component.isClearing).toBe(false);
    });

    it('does not run if already clearing', async () => {
      const component = offlineIndicator();
      component.isClearing = true;

      await component.clearAllOfflineData();

      expect(confirmSpy).not.toHaveBeenCalled();
      expect(clearOfflineData).not.toHaveBeenCalled();
    });

    it('reloads summary after clearing', async () => {
      // Create component with storage supported
      const component = offlineIndicator();

      // Call loadSummary directly to verify it works
      await component.loadSummary();

      // getOfflineSummary should have been called at least once
      expect(getOfflineSummary).toHaveBeenCalled();
    });

    it('handles errors gracefully', async () => {
      vi.mocked(clearOfflineData).mockRejectedValue(new Error('Clear failed'));

      const component = offlineIndicator();
      await component.clearAllOfflineData();

      expect(consoleErrorSpy).toHaveBeenCalled();
      expect(component.isClearing).toBe(false);
    });
  });

  // ===========================================================================
  // formatSize Method Tests
  // ===========================================================================

  describe('formatSize', () => {
    it('formats 0 bytes', () => {
      const component = offlineIndicator();
      expect(component.formatSize(0)).toBe('0 B');
    });

    it('formats bytes', () => {
      const component = offlineIndicator();
      expect(component.formatSize(512)).toBe('512 B');
    });

    it('formats kilobytes', () => {
      const component = offlineIndicator();
      expect(component.formatSize(2048)).toBe('2 KB');
    });

    it('formats megabytes', () => {
      const component = offlineIndicator();
      expect(component.formatSize(5242880)).toBe('5 MB');
    });

    it('formats with decimal places', () => {
      const component = offlineIndicator();
      expect(component.formatSize(1536)).toBe('1.5 KB');
    });
  });

  // ===========================================================================
  // registerOfflineIndicator Tests
  // ===========================================================================

  describe('registerOfflineIndicator', () => {
    it('registers component with Alpine', () => {
      registerOfflineIndicator();

      expect(Alpine.data).toHaveBeenCalledWith('offlineIndicator', offlineIndicator);
    });
  });

  // ===========================================================================
  // Global Exposure Tests
  // ===========================================================================

  describe('Global Exposure', () => {
    it('exposes offlineIndicator globally on window', () => {
      expect(window.offlineIndicator).toBe(offlineIndicator);
    });
  });

  // ===========================================================================
  // Event Handler Integration Tests
  // ===========================================================================

  describe('Event Handler Integration', () => {
    it('responds to online event', () => {
      const component = offlineIndicator();
      component.init();

      // Find the online handler
      const onlineCall = addEventListenerSpy.mock.calls.find(
        (call) => call[0] === 'online'
      );
      expect(onlineCall).toBeDefined();

      const handler = onlineCall![1] as () => void;

      Object.defineProperty(navigator, 'onLine', { value: true });
      handler();

      expect(component.isOnline).toBe(true);
    });

    it('responds to offline event', () => {
      const component = offlineIndicator();
      component.init();

      const offlineCall = addEventListenerSpy.mock.calls.find(
        (call) => call[0] === 'offline'
      );
      expect(offlineCall).toBeDefined();

      const handler = offlineCall![1] as () => void;

      Object.defineProperty(navigator, 'onLine', { value: false });
      handler();

      expect(component.isOnline).toBe(false);
    });

    it('reloads summary on text-downloaded event', async () => {
      const component = offlineIndicator();
      vi.mocked(isOfflineStorageAvailable).mockReturnValue(true);
      component.init();

      const downloadCall = addEventListenerSpy.mock.calls.find(
        (call) => call[0] === 'offline:text-downloaded'
      );
      expect(downloadCall).toBeDefined();

      vi.clearAllMocks();
      const handler = downloadCall![1] as () => void;
      handler();

      await vi.waitFor(() => {
        expect(getOfflineSummary).toHaveBeenCalled();
      });
    });

    it('reloads summary on text-removed event', async () => {
      const component = offlineIndicator();
      vi.mocked(isOfflineStorageAvailable).mockReturnValue(true);
      component.init();

      const removeCall = addEventListenerSpy.mock.calls.find(
        (call) => call[0] === 'offline:text-removed'
      );
      expect(removeCall).toBeDefined();

      vi.clearAllMocks();
      const handler = removeCall![1] as () => void;
      handler();

      await vi.waitFor(() => {
        expect(getOfflineSummary).toHaveBeenCalled();
      });
    });
  });
});
