/**
 * Tests for multi-word selection module.
 *
 * @vitest-environment jsdom
 */

import { dismissAlerts, settle } from '../helpers/confirm_answers';
import { describe, it, expect, beforeEach, vi, afterEach } from 'vitest';

// Mock Alpine.js
const mockStore = {
  loadForEdit: vi.fn()
};

vi.mock('alpinejs', () => ({
  default: {
    store: vi.fn(() => mockStore)
  }
}));

// Mock frame management
import {
  handleTextSelection,
  setupMultiWordSelection,
  mwordDragNDrop,
  multiWordDragDropSelect,
  multiWordTouchSelect
} from '../../../src/frontend/js/modules/text/pages/reading/text_multiword_selection';

describe('text_multiword_selection.ts', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    document.body.innerHTML = '';
    // Mock window.location
    Object.defineProperty(window, 'location', {
      value: {
        pathname: '/text/read',
        search: '?start=1'
      },
      writable: true
    });
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  describe('handleTextSelection', () => {
    it('does nothing when selection is empty', () => {
      document.body.innerHTML = `
        <div id="thetext">
          <span id="sent_1">
            <span class="wsty" data_order="1">Hello</span>
            <span class="wsty" data_order="2">World</span>
          </span>
        </div>
      `;
      const container = document.getElementById('thetext')!;

      // Mock empty selection
      const mockSelection = {
        isCollapsed: true,
        rangeCount: 0,
        getRangeAt: vi.fn(),
        removeAllRanges: vi.fn()
      };
      vi.spyOn(window, 'getSelection').mockReturnValue(mockSelection as unknown as Selection);

      handleTextSelection(container);

      expect(mockStore.loadForEdit).not.toHaveBeenCalled();
    });

    it('does nothing when only one word is selected', () => {
      document.body.innerHTML = `
        <div id="thetext">
          <span id="sent_1">
            <span class="wsty" data_order="1">Hello</span>
            <span class="wsty" data_order="2">World</span>
          </span>
        </div>
      `;
      const container = document.getElementById('thetext')!;
      const word1 = container.querySelector('[data_order="1"]')!;

      // Create a mock range that only contains one word
      const mockRange = {
        intersectsNode: (node: Node) => node === word1
      };

      const mockSelection = {
        isCollapsed: false,
        rangeCount: 1,
        getRangeAt: vi.fn().mockReturnValue(mockRange),
        removeAllRanges: vi.fn()
      };
      vi.spyOn(window, 'getSelection').mockReturnValue(mockSelection as unknown as Selection);

      handleTextSelection(container);

      expect(mockStore.loadForEdit).not.toHaveBeenCalled();
    });

    it('opens modal when multiple words are selected', () => {
      // In the real database, words are stored consecutively without space elements
      // The getSelectedText function adds spaces between consecutive word elements
      document.body.innerHTML = `
        <div id="thetext">
          <span id="sent_1">
            <span id="ID-1-1" class="wsty word" data_order="1">Hello</span>
            <span id="ID-2-1" class="wsty word" data_order="2">World</span>
          </span>
        </div>
      `;
      const container = document.getElementById('thetext')!;
      const word1 = container.querySelector('[data_order="1"]')!;
      const word2 = container.querySelector('[data_order="2"]')!;

      // Create a mock range that contains both words
      const mockRange = {
        intersectsNode: (node: Node) => node === word1 || node === word2
      };

      const mockSelection = {
        isCollapsed: false,
        rangeCount: 1,
        getRangeAt: vi.fn().mockReturnValue(mockRange),
        removeAllRanges: vi.fn()
      };
      vi.spyOn(window, 'getSelection').mockReturnValue(mockSelection as unknown as Selection);

      handleTextSelection(container);

      expect(mockStore.loadForEdit).toHaveBeenCalled();
      expect(mockStore.loadForEdit).toHaveBeenCalledWith(
        1, // textId from URL
        1, // position (first word's data_order)
        'Hello World', // text extracted with space added between consecutive words
        2 // word count
      );
    });

    it('uses the visible surface, not the data_hex identity token', () => {
      // Regression: data_hex used to be a reversible hex encoding of the word,
      // so reading it yielded the surface. Since #237 it is a SHA-256 derived
      // identity token, and reading it produced terms literally named
      // "e6967a5826fe441a 75e3090289e2955d".
      document.body.innerHTML = `
        <div id="thetext">
          <span id="sent_1">
            <span id="ID-1-1" class="wsty word" data_order="1"
                  data_hex="e6967a5826fe441a">Hello</span>
            <span id="ID-2-1" class="wsty word" data_order="2"
                  data_hex="75e3090289e2955d">World</span>
          </span>
        </div>
      `;
      const container = document.getElementById('thetext')!;
      const word1 = container.querySelector('[data_order="1"]')!;
      const word2 = container.querySelector('[data_order="2"]')!;

      const mockRange = {
        intersectsNode: (node: Node) => node === word1 || node === word2
      };
      const mockSelection = {
        isCollapsed: false,
        rangeCount: 1,
        getRangeAt: vi.fn().mockReturnValue(mockRange),
        removeAllRanges: vi.fn()
      };
      vi.spyOn(window, 'getSelection').mockReturnValue(mockSelection as unknown as Selection);

      handleTextSelection(container);

      expect(mockStore.loadForEdit).toHaveBeenCalledWith(1, 1, 'Hello World', 2);
    });

    it('shows alert when selected text is too long', async () => {
      // Words are stored consecutively without space elements
      document.body.innerHTML = `
        <div id="thetext">
          <span id="sent_1">
            <span id="ID-1-1" class="wsty" data_order="1">${'A'.repeat(200)}</span>
            <span id="ID-2-1" class="wsty" data_order="2">${'B'.repeat(100)}</span>
          </span>
        </div>
      `;
      const container = document.getElementById('thetext')!;
      const word1 = container.querySelector('[data_order="1"]')!;
      const word2 = container.querySelector('[data_order="2"]')!;

      const mockRange = {
        intersectsNode: (node: Node) => node === word1 || node === word2
      };

      const mockSelection = {
        isCollapsed: false,
        rangeCount: 1,
        getRangeAt: vi.fn().mockReturnValue(mockRange),
        removeAllRanges: vi.fn()
      };
      vi.spyOn(window, 'getSelection').mockReturnValue(mockSelection as unknown as Selection);
      const alertSpy = dismissAlerts();

      handleTextSelection(container);

      await settle();
      expect(alertSpy).toHaveBeenCalledWith('The selected text is too long.');
      expect(mockStore.loadForEdit).not.toHaveBeenCalled();
    });
  });

  describe('setupMultiWordSelection', () => {
    it('attaches mouseup event listener to container', () => {
      document.body.innerHTML = '<div id="thetext"></div>';
      const container = document.getElementById('thetext')!;
      const addEventListenerSpy = vi.spyOn(container, 'addEventListener');

      setupMultiWordSelection(container);

      expect(addEventListenerSpy).toHaveBeenCalledWith('mouseup', expect.any(Function));
    });
  });

  describe('legacy exports', () => {
    it('exports mwordDragNDrop for backwards compatibility', () => {
      expect(mwordDragNDrop).toBeDefined();
      expect(mwordDragNDrop.context).toBeUndefined();
      expect(typeof mwordDragNDrop.stopInteraction).toBe('function');
    });

    it('exports multiWordDragDropSelect function', () => {
      expect(typeof multiWordDragDropSelect).toBe('function');
      // Should be a no-op
      multiWordDragDropSelect();
    });

    it('exports multiWordTouchSelect function', () => {
      expect(typeof multiWordTouchSelect).toBe('function');
      // Should be a no-op
      multiWordTouchSelect();
    });
  });

  describe('getTextIdFromUrl', () => {
    it('extracts text ID from query parameter', () => {
      Object.defineProperty(window, 'location', {
        value: {
          pathname: '/text/read',
          search: '?start=42'
        },
        writable: true
      });

      // Words are stored consecutively without space elements
      document.body.innerHTML = `
        <div id="thetext">
          <span id="sent_1">
            <span id="ID-1-1" class="wsty" data_order="1">Hello</span>
            <span id="ID-2-1" class="wsty" data_order="2">World</span>
          </span>
        </div>
      `;
      const container = document.getElementById('thetext')!;
      const word1 = container.querySelector('[data_order="1"]')!;
      const word2 = container.querySelector('[data_order="2"]')!;

      const mockRange = {
        intersectsNode: (node: Node) => node === word1 || node === word2
      };

      const mockSelection = {
        isCollapsed: false,
        rangeCount: 1,
        getRangeAt: vi.fn().mockReturnValue(mockRange),
        removeAllRanges: vi.fn()
      };
      vi.spyOn(window, 'getSelection').mockReturnValue(mockSelection as unknown as Selection);

      handleTextSelection(container);

      expect(mockStore.loadForEdit).toHaveBeenCalledWith(
        42, // textId extracted from URL
        1,
        'Hello World',
        2
      );
    });
  });
});
