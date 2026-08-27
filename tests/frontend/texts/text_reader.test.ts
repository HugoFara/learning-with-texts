/**
 * Tests for text_reader.ts - Text reading view Alpine component
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

// Mock Alpine.js
vi.mock('alpinejs', () => ({
  default: {
    data: vi.fn(),
    store: vi.fn()
  }
}));

// Mock text renderer
vi.mock('../../../src/frontend/js/modules/text/pages/reading/text_renderer', () => ({
  renderText: vi.fn(() => '<div>rendered text</div>'),
  renderParseWarning: vi.fn(() => ''),
  updateWordStatusInDOM: vi.fn()
}));

// Mock multiword selection
vi.mock('../../../src/frontend/js/modules/text/pages/reading/text_multiword_selection', () => ({
  setupMultiWordSelection: vi.fn()
}));

// Mock TextsApi
vi.mock('../../../src/frontend/js/modules/text/api/texts_api', () => ({
  TextsApi: {
    markAllWellKnown: vi.fn().mockResolvedValue({ data: { words: [] } }),
    markAllIgnored: vi.fn().mockResolvedValue({ data: { words: [] } })
  }
}));

// Create mock word store
const mockWordStore = {
  textId: 123,
  title: 'Test Text',
  isInitialized: true,
  rightToLeft: false,
  textSize: 100,
  showLearning: true,
  displayStatTrans: true,
  modeTrans: 1,
  annTextSize: 75,
  words: [],
  isPopoverOpen: false,
  isEditModalOpen: false,
  showAll: false,
  showTranslations: true,
  loadText: vi.fn().mockResolvedValue(undefined),
  selectWord: vi.fn(),
  updateWordInStore: vi.fn()
};

import Alpine from 'alpinejs';
import { textReaderData, initTextReaderAlpine } from '../../../src/frontend/js/modules/text/components/text_reader';
import { renderText, updateWordStatusInDOM } from '../../../src/frontend/js/modules/text/pages/reading/text_renderer';
import { setupMultiWordSelection } from '../../../src/frontend/js/modules/text/pages/reading/text_multiword_selection';
import { TextsApi } from '../../../src/frontend/js/modules/text/api/texts_api';

describe('text_reader.ts', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
    vi.clearAllMocks();

    // Mock Alpine.store to return our mock word store
    (Alpine.store as ReturnType<typeof vi.fn>).mockReturnValue(mockWordStore);

    // Reset mock store state
    mockWordStore.isInitialized = true;
    mockWordStore.textId = 123;
    mockWordStore.isPopoverOpen = false;
    mockWordStore.isEditModalOpen = false;
  });

  afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
  });

  // ===========================================================================
  // textReaderData Factory Tests
  // ===========================================================================

  describe('textReaderData', () => {
    it('creates component with default values', () => {
      const component = textReaderData();

      expect(component.isLoading).toBe(true);
      expect(component.showAll).toBe(false);
      expect(component.showTranslations).toBe(true);
      expect(component.error).toBe(null);
    });

    it('accesses store via Alpine.store', () => {
      const component = textReaderData();

      expect(component.store).toBe(mockWordStore);
      expect(Alpine.store).toHaveBeenCalledWith('words');
    });

    it('gets textId from store', () => {
      const component = textReaderData();

      expect(component.textId).toBe(123);
    });

    it('gets title from store', () => {
      const component = textReaderData();

      expect(component.title).toBe('Test Text');
    });

    it('gets isInitialized from store', () => {
      const component = textReaderData();

      expect(component.isInitialized).toBe(true);
    });
  });

  // ===========================================================================
  // getTextIdFromUrl Tests
  // ===========================================================================

  describe('getTextIdFromUrl', () => {
    it('extracts text ID from path /text/read/123', () => {
      Object.defineProperty(window, 'location', {
        value: { pathname: '/text/read/456', search: '' },
        writable: true
      });

      const component = textReaderData();
      const textId = component.getTextIdFromUrl();

      expect(textId).toBe(456);
    });

    it('extracts text ID from query ?text=789', () => {
      Object.defineProperty(window, 'location', {
        value: { pathname: '/', search: '?text=789' },
        writable: true
      });

      const component = textReaderData();
      const textId = component.getTextIdFromUrl();

      expect(textId).toBe(789);
    });

    it('extracts text ID from query ?tid=111', () => {
      Object.defineProperty(window, 'location', {
        value: { pathname: '/', search: '?tid=111' },
        writable: true
      });

      const component = textReaderData();
      const textId = component.getTextIdFromUrl();

      expect(textId).toBe(111);
    });

    it('extracts text ID from query ?start=222', () => {
      Object.defineProperty(window, 'location', {
        value: { pathname: '/', search: '?start=222' },
        writable: true
      });

      const component = textReaderData();
      const textId = component.getTextIdFromUrl();

      expect(textId).toBe(222);
    });

    it('returns 0 when no text ID found', () => {
      Object.defineProperty(window, 'location', {
        value: { pathname: '/other/page', search: '' },
        writable: true
      });

      const component = textReaderData();
      const textId = component.getTextIdFromUrl();

      expect(textId).toBe(0);
    });
  });

  // ===========================================================================
  // getRenderSettings Tests
  // ===========================================================================

  describe('getRenderSettings', () => {
    it('returns correct render settings', () => {
      const component = textReaderData();
      const settings = component.getRenderSettings();

      expect(settings.showAll).toBe(false);
      expect(settings.showTranslations).toBe(true);
      expect(settings.rightToLeft).toBe(false);
      expect(settings.textSize).toBe(100);
    });

    it('includes annotation settings', () => {
      const component = textReaderData();
      const settings = component.getRenderSettings();

      expect(settings.showLearning).toBe(true);
      expect(settings.displayStatTrans).toBe(true);
      expect(settings.modeTrans).toBe(1);
      expect(settings.annTextSize).toBe(75);
    });
  });

  // ===========================================================================
  // renderTextContent Tests
  // ===========================================================================

  describe('renderTextContent', () => {
    it('renders text to container', () => {
      document.body.innerHTML = '<div id="thetext"></div>';

      const component = textReaderData();
      component.renderTextContent();

      expect(renderText).toHaveBeenCalled();
      const container = document.getElementById('thetext');
      expect(container?.innerHTML).toBe('<div>rendered text</div>');
    });

    it('logs error when container not found', () => {
      const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});

      const component = textReaderData();
      component.renderTextContent();

      expect(consoleSpy).toHaveBeenCalledWith('Text container not found');
    });

    it('applies RTL styling when enabled', () => {
      document.body.innerHTML = '<div id="thetext"></div>';
      mockWordStore.rightToLeft = true;

      const component = textReaderData();
      component.renderTextContent();

      const container = document.getElementById('thetext');
      expect(container?.style.direction).toBe('rtl');

      mockWordStore.rightToLeft = false;
    });

    it('applies text size when not 100%', () => {
      document.body.innerHTML = '<div id="thetext"></div>';
      mockWordStore.textSize = 125;

      const component = textReaderData();
      component.renderTextContent();

      const container = document.getElementById('thetext');
      expect(container?.style.fontSize).toBe('125%');

      mockWordStore.textSize = 100;
    });
  });

  // ===========================================================================
  // setupEventListeners Tests
  // ===========================================================================

  describe('setupEventListeners', () => {
    it('adds click listener to container', () => {
      document.body.innerHTML = '<div id="thetext"></div>';

      const container = document.getElementById('thetext')!;
      const addEventListenerSpy = vi.spyOn(container, 'addEventListener');

      const component = textReaderData();
      component.setupEventListeners();

      expect(addEventListenerSpy).toHaveBeenCalledWith('click', expect.any(Function));
    });

    it('adds keydown listener to document', () => {
      document.body.innerHTML = '<div id="thetext"></div>';

      const addEventListenerSpy = vi.spyOn(document, 'addEventListener');

      const component = textReaderData();
      component.setupEventListeners();

      expect(addEventListenerSpy).toHaveBeenCalledWith('keydown', expect.any(Function));
    });

    it('calls setupMultiWordSelection', () => {
      document.body.innerHTML = '<div id="thetext"></div>';

      const component = textReaderData();
      component.setupEventListeners();

      expect(setupMultiWordSelection).toHaveBeenCalled();
    });

    it('handles missing container gracefully', () => {
      const component = textReaderData();

      expect(() => component.setupEventListeners()).not.toThrow();
    });
  });

  // ===========================================================================
  // toggleShowAll Tests
  // ===========================================================================

  describe('toggleShowAll', () => {
    it('toggles showAll state', () => {
      document.body.innerHTML = '<div id="thetext"></div>';

      const component = textReaderData();
      expect(component.showAll).toBe(false);

      component.toggleShowAll();
      expect(component.showAll).toBe(true);

      component.toggleShowAll();
      expect(component.showAll).toBe(false);
    });

    it('updates store showAll', () => {
      document.body.innerHTML = '<div id="thetext"></div>';

      const component = textReaderData();
      component.toggleShowAll();

      expect(mockWordStore.showAll).toBe(true);
    });

    it('re-renders text content', () => {
      document.body.innerHTML = '<div id="thetext"></div>';

      const component = textReaderData();
      component.toggleShowAll();

      expect(renderText).toHaveBeenCalled();
    });
  });

  // ===========================================================================
  // toggleTranslations Tests
  // ===========================================================================

  describe('toggleTranslations', () => {
    it('toggles showTranslations state', () => {
      document.body.innerHTML = '<div id="thetext"></div>';

      const component = textReaderData();
      expect(component.showTranslations).toBe(true);

      component.toggleTranslations();
      expect(component.showTranslations).toBe(false);

      component.toggleTranslations();
      expect(component.showTranslations).toBe(true);
    });

    it('toggles hide-translations class on container', () => {
      document.body.innerHTML = '<div id="thetext"></div>';

      const component = textReaderData();
      component.toggleTranslations();

      const container = document.getElementById('thetext');
      expect(container?.classList.contains('hide-translations')).toBe(true);

      component.toggleTranslations();
      expect(container?.classList.contains('hide-translations')).toBe(false);
    });
  });

  // ===========================================================================
  // handleKeydown Tests
  // ===========================================================================

  describe('handleKeydown', () => {
    it('does nothing when popover is open', () => {
      mockWordStore.isPopoverOpen = true;

      const component = textReaderData();
      const event = new KeyboardEvent('keydown', { key: 'ArrowRight' });

      expect(() => component.handleKeydown(event)).not.toThrow();
    });

    it('does nothing when edit modal is open', () => {
      mockWordStore.isEditModalOpen = true;

      const component = textReaderData();
      const event = new KeyboardEvent('keydown', { key: 'ArrowRight' });

      expect(() => component.handleKeydown(event)).not.toThrow();
    });
  });

  // ===========================================================================
  // goBack Tests
  // ===========================================================================

  describe('goBack', () => {
    it('calls window.history.back', () => {
      const backSpy = vi.spyOn(window.history, 'back').mockImplementation(() => {});

      const component = textReaderData();
      component.goBack();

      expect(backSpy).toHaveBeenCalled();
    });
  });

  // ===========================================================================
  // updateWordDisplay Tests
  // ===========================================================================

  describe('updateWordDisplay', () => {
    it('calls updateWordStatusInDOM', () => {
      const component = textReaderData();
      component.updateWordDisplay('ABC123', 5, 999);

      expect(updateWordStatusInDOM).toHaveBeenCalledWith('ABC123', 5, 999);
    });
  });

  // ===========================================================================
  // markAllWellKnown Tests
  // ===========================================================================

  describe('markAllWellKnown', () => {
    it('shows confirmation dialog', async () => {
      const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);

      const component = textReaderData();
      await component.markAllWellKnown();

      expect(confirmSpy).toHaveBeenCalled();
    });

    it('does nothing when cancelled', async () => {
      vi.spyOn(window, 'confirm').mockReturnValue(false);

      const component = textReaderData();
      await component.markAllWellKnown();

      expect(TextsApi.markAllWellKnown).not.toHaveBeenCalled();
    });

    it('calls API when confirmed', async () => {
      vi.spyOn(window, 'confirm').mockReturnValue(true);

      const component = textReaderData();
      await component.markAllWellKnown();

      expect(TextsApi.markAllWellKnown).toHaveBeenCalledWith(123);
    });
  });

  // ===========================================================================
  // markAllIgnored Tests
  // ===========================================================================

  describe('markAllIgnored', () => {
    it('shows confirmation dialog', async () => {
      const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);

      const component = textReaderData();
      await component.markAllIgnored();

      expect(confirmSpy).toHaveBeenCalled();
    });

    it('does nothing when cancelled', async () => {
      vi.spyOn(window, 'confirm').mockReturnValue(false);

      const component = textReaderData();
      await component.markAllIgnored();

      expect(TextsApi.markAllIgnored).not.toHaveBeenCalled();
    });

    it('calls API when confirmed', async () => {
      vi.spyOn(window, 'confirm').mockReturnValue(true);

      const component = textReaderData();
      await component.markAllIgnored();

      expect(TextsApi.markAllIgnored).toHaveBeenCalledWith(123);
    });
  });

  // ===========================================================================
  // initTextReaderAlpine Tests
  // ===========================================================================

  describe('initTextReaderAlpine', () => {
    it('registers textReader component with Alpine', () => {
      initTextReaderAlpine();

      expect(Alpine.data).toHaveBeenCalledWith('textReader', textReaderData);
    });
  });

  // ===========================================================================
  // Global Window Exposure Tests
  // ===========================================================================

  describe('global window exposure', () => {
    it('exposes textReaderData on window', () => {
      expect(typeof window.textReaderData).toBe('function');
    });
  });

  // ===========================================================================
  // init method Tests
  // ===========================================================================

  describe('init method', () => {
    it('returns early when no text ID in URL', async () => {
      Object.defineProperty(window, 'location', {
        value: { pathname: '/', search: '' },
        writable: true
      });

      const component = textReaderData();
      await component.init();

      expect(component.isLoading).toBe(false);
      expect(mockWordStore.loadText).not.toHaveBeenCalled();
    });

    it('returns early when text ID is 0', async () => {
      Object.defineProperty(window, 'location', {
        value: { pathname: '/', search: '?text=0' },
        writable: true
      });

      const component = textReaderData();
      await component.init();

      expect(component.isLoading).toBe(false);
      expect(mockWordStore.loadText).not.toHaveBeenCalled();
    });

    it('loads text and renders content on success', async () => {
      document.body.innerHTML = '<div id="thetext"></div>';
      Object.defineProperty(window, 'location', {
        value: { pathname: '/text/read/123', search: '' },
        writable: true
      });

      const component = textReaderData();
      await component.init();

      expect(mockWordStore.loadText).toHaveBeenCalledWith(123);
      expect(renderText).toHaveBeenCalled();
      expect(component.isLoading).toBe(false);
    });

    it('sets error when store fails to initialize', async () => {
      Object.defineProperty(window, 'location', {
        value: { pathname: '/text/read/123', search: '' },
        writable: true
      });
      mockWordStore.isInitialized = false;

      const component = textReaderData();
      await component.init();

      expect(component.error).toBe('Failed to load text');
      expect(component.isLoading).toBe(false);

      mockWordStore.isInitialized = true;
    });

    it('handles exceptions gracefully', async () => {
      const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
      Object.defineProperty(window, 'location', {
        value: { pathname: '/text/read/123', search: '' },
        writable: true
      });
      mockWordStore.loadText.mockRejectedValue(new Error('Network error'));

      const component = textReaderData();
      await component.init();

      expect(consoleSpy).toHaveBeenCalled();
      expect(component.error).toBe('An error occurred while loading the text');
      expect(component.isLoading).toBe(false);

      mockWordStore.loadText.mockResolvedValue(undefined);
    });
  });

  // ===========================================================================
  // handleWordClick Tests
  // ===========================================================================

  describe('handleWordClick', () => {
    it('does nothing when clicking non-word element', () => {
      document.body.innerHTML = '<div id="thetext"><p>Regular text</p></div>';

      const component = textReaderData();
      const event = new MouseEvent('click', { bubbles: true });
      Object.defineProperty(event, 'target', { value: document.querySelector('p') });

      component.handleWordClick(event);

      expect(mockWordStore.selectWord).not.toHaveBeenCalled();
    });

    it('selects word when clicking .word element', () => {
      document.body.innerHTML = `
        <div id="thetext">
          <span class="word" data_hex="ABC123" data_order="5">hello</span>
        </div>
      `;

      const component = textReaderData();
      const wordEl = document.querySelector('.word')!;
      const event = new MouseEvent('click', { bubbles: true });
      Object.defineProperty(event, 'target', { value: wordEl });

      component.handleWordClick(event);

      expect(mockWordStore.selectWord).toHaveBeenCalledWith('ABC123', 5, wordEl);
    });

    it('selects word when clicking .mword element', () => {
      document.body.innerHTML = `
        <div id="thetext">
          <span class="mword" data_hex="DEF456" data_pos="10">multi word</span>
        </div>
      `;

      const component = textReaderData();
      const wordEl = document.querySelector('.mword')!;
      const event = new MouseEvent('click', { bubbles: true });
      Object.defineProperty(event, 'target', { value: wordEl });

      component.handleWordClick(event);

      expect(mockWordStore.selectWord).toHaveBeenCalledWith('DEF456', 10, wordEl);
    });

    it('does nothing when hex cannot be determined', () => {
      document.body.innerHTML = `
        <div id="thetext">
          <span class="word" data_order="1">test</span>
        </div>
      `;

      const component = textReaderData();
      const wordEl = document.querySelector('.word')!;
      const event = new MouseEvent('click', { bubbles: true });
      Object.defineProperty(event, 'target', { value: wordEl });

      component.handleWordClick(event);

      expect(mockWordStore.selectWord).not.toHaveBeenCalled();
    });

    it('prevents default and stops propagation', () => {
      document.body.innerHTML = `
        <div id="thetext">
          <span class="word" data_hex="ABC" data_order="1">hello</span>
        </div>
      `;

      const component = textReaderData();
      const wordEl = document.querySelector('.word')!;
      const event = new MouseEvent('click', { bubbles: true, cancelable: true });
      Object.defineProperty(event, 'target', { value: wordEl });
      const preventSpy = vi.spyOn(event, 'preventDefault');
      const stopSpy = vi.spyOn(event, 'stopPropagation');

      component.handleWordClick(event);

      expect(preventSpy).toHaveBeenCalled();
      expect(stopSpy).toHaveBeenCalled();
    });

    it('handles click on child element of word', () => {
      document.body.innerHTML = `
        <div id="thetext">
          <span class="word" data_hex="ABC" data_order="1">
            <ruby>hello<rt>annotation</rt></ruby>
          </span>
        </div>
      `;

      const component = textReaderData();
      const rubyEl = document.querySelector('ruby')!;
      const event = new MouseEvent('click', { bubbles: true });
      Object.defineProperty(event, 'target', { value: rubyEl });

      component.handleWordClick(event);

      // closest() should find the parent .word element
      expect(mockWordStore.selectWord).toHaveBeenCalledWith('ABC', 1, expect.any(Element));
    });
  });

  // ===========================================================================
  // markAllWellKnown edge cases Tests
  // ===========================================================================

  describe('markAllWellKnown edge cases', () => {
    it('handles API error response', async () => {
      const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
      vi.spyOn(window, 'confirm').mockReturnValue(true);

      vi.mocked(TextsApi.markAllWellKnown).mockResolvedValue({
        error: 'API Error',
        data: undefined
      });

      const component = textReaderData();
      await component.markAllWellKnown();

      expect(consoleSpy).toHaveBeenCalledWith('Failed to mark all well-known:', 'API Error');
      expect(component.statusMessage).toBe('Failed to mark words as well-known.');
    });

    it('updates words in DOM and store on success', async () => {
      vi.spyOn(window, 'confirm').mockReturnValue(true);

      vi.mocked(TextsApi.markAllWellKnown).mockResolvedValue({
        data: {
          words: [
            { hex: 'ABC123', wid: 1 },
            { hex: 'DEF456', wid: 2 }
          ]
        },
        error: undefined
      });

      const component = textReaderData();
      await component.markAllWellKnown();

      expect(updateWordStatusInDOM).toHaveBeenCalledWith('ABC123', 99, 1);
      expect(updateWordStatusInDOM).toHaveBeenCalledWith('DEF456', 99, 2);
      expect(mockWordStore.updateWordInStore).toHaveBeenCalledTimes(2);
      expect(component.statusMessage).toBe('Marked 2 words as Well Known.');
    });

    it('handles exception during API call', async () => {
      const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
      vi.spyOn(window, 'confirm').mockReturnValue(true);

      vi.mocked(TextsApi.markAllWellKnown).mockRejectedValue(new Error('Network error'));

      const component = textReaderData();
      await component.markAllWellKnown();

      expect(consoleSpy).toHaveBeenCalledWith('Error marking all well-known:', expect.any(Error));
      expect(component.statusMessage).toBe('Error marking words as well-known.');
    });
  });

  // ===========================================================================
  // markAllIgnored edge cases Tests
  // ===========================================================================

  describe('markAllIgnored edge cases', () => {
    it('handles API error response', async () => {
      const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
      vi.spyOn(window, 'confirm').mockReturnValue(true);

      vi.mocked(TextsApi.markAllIgnored).mockResolvedValue({
        error: 'API Error',
        data: undefined
      });

      const component = textReaderData();
      await component.markAllIgnored();

      expect(consoleSpy).toHaveBeenCalledWith('Failed to mark all ignored:', 'API Error');
      expect(component.statusMessage).toBe('Failed to mark words as ignored.');
    });

    it('updates words in DOM and store on success', async () => {
      vi.spyOn(window, 'confirm').mockReturnValue(true);

      vi.mocked(TextsApi.markAllIgnored).mockResolvedValue({
        data: {
          words: [
            { hex: 'ABC123', wid: 1 },
            { hex: 'DEF456', wid: 2 }
          ]
        },
        error: undefined
      });

      const component = textReaderData();
      await component.markAllIgnored();

      expect(updateWordStatusInDOM).toHaveBeenCalledWith('ABC123', 98, 1);
      expect(updateWordStatusInDOM).toHaveBeenCalledWith('DEF456', 98, 2);
      expect(mockWordStore.updateWordInStore).toHaveBeenCalledTimes(2);
      expect(component.statusMessage).toBe('Marked 2 words as Ignored.');
    });

    it('handles exception during API call', async () => {
      const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
      vi.spyOn(window, 'confirm').mockReturnValue(true);

      vi.mocked(TextsApi.markAllIgnored).mockRejectedValue(new Error('Network error'));

      const component = textReaderData();
      await component.markAllIgnored();

      expect(consoleSpy).toHaveBeenCalledWith('Error marking all ignored:', expect.any(Error));
      expect(component.statusMessage).toBe('Error marking words as ignored.');
    });
  });

  // ===========================================================================
  // goNext Tests
  // ===========================================================================

  describe('goNext', () => {
    it('does not throw when called', () => {
      const component = textReaderData();

      // goNext is a placeholder that doesn't do anything yet
      expect(() => component.goNext()).not.toThrow();
    });
  });
});
