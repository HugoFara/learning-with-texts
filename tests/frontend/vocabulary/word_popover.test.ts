/**
 * Tests for word_popover.ts - Alpine.js component for non-blocking word info display.
 */
import { answerConfirms, confirmPrompts, settle } from '../helpers/confirm_answers';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import Alpine from 'alpinejs';

// Use vi.hoisted to ensure mock function is available during hoisting
const mockSpeechDispatcher = vi.hoisted(() => vi.fn());

// Mock Alpine.js
vi.mock('alpinejs', () => ({
  default: {
    store: vi.fn(),
    data: vi.fn(),
    effect: vi.fn((callback: () => void) => {
      // Execute the effect callback for testing
      callback();
    })
  }
}));

// Mock lucide_icons
vi.mock('../../../src/frontend/js/shared/icons/lucide_icons', () => ({
  initIcons: vi.fn()
}));

// Mock user_interactions
vi.mock('../../../src/frontend/js/shared/utils/user_interactions', () => ({
  speechDispatcher: mockSpeechDispatcher
}));

// Import after mocks are set up
import {
  wordPopoverData,
  initWordPopoverAlpine,
} from '../../../src/frontend/js/modules/vocabulary/components/word_popover';

describe('word_popover.ts', () => {
  let mockStore: ReturnType<typeof createMockStore>;
  let mockTargetElement: HTMLElement;

  function createMockStore(overrides = {}) {
    return {
      isPopoverOpen: false,
      isLoading: false,
      textId: 1,
      langId: 5,
      popoverTargetElement: null as HTMLElement | null,
      getSelectedWord: vi.fn().mockReturnValue({
        hex: 'abc123',
        text: 'hello',
        textLc: 'hello',
        status: 1,
        position: 10,
        wordId: 100,
        translation: 'hola',
        romanization: '',
        sentence: 'Hello world.'
      }),
      closePopover: vi.fn(),
      openEditModal: vi.fn(),
      setStatus: vi.fn().mockResolvedValue(undefined),
      createQuickWord: vi.fn().mockResolvedValue(undefined),
      deleteWord: vi.fn().mockResolvedValue(undefined),
      getDictUrl: vi.fn((which: string) => `https://dict.example.com/${which}`),
      ...overrides
    };
  }

  beforeEach(() => {
    vi.clearAllMocks();
    document.body.innerHTML = '';
    mockStore = createMockStore();

    // Create a mock target element
    mockTargetElement = document.createElement('span');
    mockTargetElement.className = 'word';
    mockTargetElement.textContent = 'hello';
    mockTargetElement.getBoundingClientRect = vi.fn().mockReturnValue({
      top: 100,
      bottom: 120,
      left: 200,
      right: 250,
      width: 50,
      height: 20
    });
    document.body.appendChild(mockTargetElement);

    // Setup Alpine.store mock to return appropriate store
    vi.mocked(Alpine.store).mockReturnValue(mockStore);

    // Mock requestAnimationFrame
    global.requestAnimationFrame = vi.fn((callback) => {
      callback(0);
      return 0;
    });

    // Mock confirm
    answerConfirms(true);

    // Mock window dimensions
    Object.defineProperty(window, 'innerHeight', { value: 768, writable: true });
    Object.defineProperty(window, 'innerWidth', { value: 1024, writable: true });
    Object.defineProperty(window, 'scrollY', { value: 0, writable: true });
    Object.defineProperty(window, 'scrollX', { value: 0, writable: true });
  });

  afterEach(() => {
    document.body.innerHTML = '';
    vi.restoreAllMocks();
  });

  describe('wordPopoverData', () => {
    it('returns component data object with all required properties', () => {
      const component = wordPopoverData();

      expect(component).toBeDefined();
      expect(component.position).toEqual({ top: 0, left: 0, placement: 'below' });
      expect(component.popoverEl).toBeNull();
      expect(typeof component.init).toBe('function');
      expect(typeof component.close).toBe('function');
      expect(typeof component.speakWord).toBe('function');
      expect(typeof component.setStatus).toBe('function');
    });

    describe('store getter', () => {
      it('returns Alpine store for words', () => {
        const component = wordPopoverData();

        const store = component.store;

        expect(Alpine.store).toHaveBeenCalledWith('words');
        expect(store).toBe(mockStore);
      });
    });

    describe('word getter', () => {
      it('returns selected word from store', () => {
        const component = wordPopoverData();

        const word = component.word;

        expect(mockStore.getSelectedWord).toHaveBeenCalled();
        expect(word?.text).toBe('hello');
      });
    });

    describe('isOpen getter', () => {
      it('returns true when store.isPopoverOpen is true', () => {
        mockStore.isPopoverOpen = true;
        const component = wordPopoverData();

        expect(component.isOpen).toBe(true);
      });

      it('returns false when store.isPopoverOpen is false', () => {
        mockStore.isPopoverOpen = false;
        const component = wordPopoverData();

        expect(component.isOpen).toBe(false);
      });
    });

    describe('isLoading getter', () => {
      it('returns true when store is loading', () => {
        mockStore.isLoading = true;
        const component = wordPopoverData();

        expect(component.isLoading).toBe(true);
      });

      it('returns false when store is not loading', () => {
        mockStore.isLoading = false;
        const component = wordPopoverData();

        expect(component.isLoading).toBe(false);
      });
    });

    describe('isUnknown getter', () => {
      it('returns true when word is null', () => {
        mockStore.getSelectedWord = vi.fn().mockReturnValue(null);
        const component = wordPopoverData();

        expect(component.isUnknown).toBe(true);
      });

      it('returns true when word status is 0', () => {
        mockStore.getSelectedWord = vi.fn().mockReturnValue({
          hex: 'test',
          text: 'test',
          status: 0
        });
        const component = wordPopoverData();

        expect(component.isUnknown).toBe(true);
      });

      it('returns false when word has non-zero status', () => {
        const component = wordPopoverData();

        expect(component.isUnknown).toBe(false);
      });
    });

    describe('statuses getter', () => {
      it('returns array of 7 status options', () => {
        const component = wordPopoverData();

        expect(component.statuses).toHaveLength(7);
        expect(component.statuses[0]).toEqual({
          value: 1,
          label: 'Learning (1)',
          abbr: '1',
          class: 'is-danger'
        });
      });
    });

    describe('calculatePosition', () => {
      it('positions popover below target by default', () => {
        mockStore.popoverTargetElement = mockTargetElement;
        const component = wordPopoverData();

        component.calculatePosition();

        expect(component.position.placement).toBe('below');
        expect(component.position.top).toBe(128); // 120 (bottom) + 8 (offset)
        expect(component.position.left).toBe(200);
      });

      it('positions popover above when not enough space below', () => {
        mockStore.popoverTargetElement = mockTargetElement;
        mockTargetElement.getBoundingClientRect = vi.fn().mockReturnValue({
          top: 600,
          bottom: 620,
          left: 200,
          right: 250,
          width: 50,
          height: 20
        });
        const component = wordPopoverData();

        component.calculatePosition();

        expect(component.position.placement).toBe('above');
      });

      it('adjusts horizontal position when overflowing right edge', () => {
        mockStore.popoverTargetElement = mockTargetElement;
        mockTargetElement.getBoundingClientRect = vi.fn().mockReturnValue({
          top: 100,
          bottom: 120,
          left: 900,
          right: 950,
          width: 50,
          height: 20
        });
        const component = wordPopoverData();

        component.calculatePosition();

        // Should be adjusted to fit within viewport
        expect(component.position.left).toBeLessThan(900);
      });

      it('adjusts horizontal position when overflowing left edge', () => {
        mockStore.popoverTargetElement = mockTargetElement;
        mockTargetElement.getBoundingClientRect = vi.fn().mockReturnValue({
          top: 100,
          bottom: 120,
          left: -50,
          right: 50,
          width: 100,
          height: 20
        });
        const component = wordPopoverData();

        component.calculatePosition();

        expect(component.position.left).toBe(10);
      });

      it('does nothing when no target element', () => {
        mockStore.popoverTargetElement = null;
        const component = wordPopoverData();
        component.position = { top: 0, left: 0, placement: 'below' };

        component.calculatePosition();

        expect(component.position).toEqual({ top: 0, left: 0, placement: 'below' });
      });
    });

    describe('getPositionStyle', () => {
      it('returns CSS position string', () => {
        const component = wordPopoverData();
        component.position = { top: 100, left: 200, placement: 'below' };

        const style = component.getPositionStyle();

        expect(style).toBe('top: 100px; left: 200px;');
      });
    });

    describe('close', () => {
      it('calls store.closePopover', () => {
        const component = wordPopoverData();

        component.close();

        expect(mockStore.closePopover).toHaveBeenCalled();
      });
    });

    describe('speakWord', () => {
      it('calls speechDispatcher with word text and language', () => {
        const component = wordPopoverData();

        component.speakWord();

        expect(mockSpeechDispatcher).toHaveBeenCalledWith('hello', 5);
      });

      it('does nothing when word is null', () => {
        mockStore.getSelectedWord = vi.fn().mockReturnValue(null);
        const component = wordPopoverData();

        component.speakWord();

        expect(mockSpeechDispatcher).not.toHaveBeenCalled();
      });
    });

    describe('setStatus', () => {
      it('calls store.setStatus with word hex and status', async () => {
        const component = wordPopoverData();

        await component.setStatus(3);

        expect(mockStore.setStatus).toHaveBeenCalledWith('abc123', 3);
      });

      it('does nothing when word is null', async () => {
        mockStore.getSelectedWord = vi.fn().mockReturnValue(null);
        const component = wordPopoverData();

        await component.setStatus(3);

        expect(mockStore.setStatus).not.toHaveBeenCalled();
      });
    });

    describe('markWellKnown', () => {
      it('calls store.createQuickWord with status 99', async () => {
        const component = wordPopoverData();

        await component.markWellKnown();

        expect(mockStore.createQuickWord).toHaveBeenCalledWith('abc123', 10, 99);
      });

      it('does nothing when word is null', async () => {
        mockStore.getSelectedWord = vi.fn().mockReturnValue(null);
        const component = wordPopoverData();

        await component.markWellKnown();

        expect(mockStore.createQuickWord).not.toHaveBeenCalled();
      });
    });

    describe('markIgnored', () => {
      it('calls store.createQuickWord with status 98', async () => {
        const component = wordPopoverData();

        await component.markIgnored();

        expect(mockStore.createQuickWord).toHaveBeenCalledWith('abc123', 10, 98);
      });

      it('does nothing when word is null', async () => {
        mockStore.getSelectedWord = vi.fn().mockReturnValue(null);
        const component = wordPopoverData();

        await component.markIgnored();

        expect(mockStore.createQuickWord).not.toHaveBeenCalled();
      });
    });

    describe('deleteWord', () => {
      it('confirms and calls store.deleteWord when user confirms', async () => {
        answerConfirms(true);
        const component = wordPopoverData();

        await component.deleteWord();

        await settle();
        expect(confirmPrompts).toHaveBeenCalledWith('Delete this term?');
        expect(mockStore.deleteWord).toHaveBeenCalledWith('abc123');
      });

      it('does not delete when user cancels', async () => {
        answerConfirms(false);
        const component = wordPopoverData();

        await component.deleteWord();

        await settle();
        expect(mockStore.deleteWord).not.toHaveBeenCalled();
      });

      it('does nothing when word is null', async () => {
        mockStore.getSelectedWord = vi.fn().mockReturnValue(null);
        const component = wordPopoverData();

        await component.deleteWord();

        expect(confirmPrompts).not.toHaveBeenCalled();
      });
    });

    describe('openEditForm', () => {
      it('calls store.openEditModal', () => {
        const component = wordPopoverData();

        component.openEditForm();

        expect(mockStore.openEditModal).toHaveBeenCalled();
      });
    });

    describe('getDictUrl', () => {
      it('delegates to store.getDictUrl', () => {
        const component = wordPopoverData();

        const url = component.getDictUrl('dict1');

        expect(mockStore.getDictUrl).toHaveBeenCalledWith('dict1');
        expect(url).toBe('https://dict.example.com/dict1');
      });
    });

    describe('isCurrentStatus', () => {
      it('returns true when status matches word status', () => {
        const component = wordPopoverData();

        expect(component.isCurrentStatus(1)).toBe(true);
      });

      it('returns false when status does not match', () => {
        const component = wordPopoverData();

        expect(component.isCurrentStatus(2)).toBe(false);
      });

      it('returns false when word is null', () => {
        mockStore.getSelectedWord = vi.fn().mockReturnValue(null);
        const component = wordPopoverData();

        expect(component.isCurrentStatus(1)).toBe(false);
      });
    });

    describe('getStatusButtonClass', () => {
      it('returns active class for current status', () => {
        const component = wordPopoverData();

        const className = component.getStatusButtonClass(1);

        expect(className).toBe('button is-small is-danger');
      });

      it('returns outlined class for non-current status', () => {
        const component = wordPopoverData();

        const className = component.getStatusButtonClass(2);

        expect(className).toBe('button is-small is-outlined is-warning');
      });
    });

    describe('handleClickOutside', () => {
      it('closes popover when clicking outside', () => {
        mockStore.isPopoverOpen = true;
        // Create popover element so the logic can check if click is inside/outside
        const popoverEl = document.createElement('div');
        popoverEl.className = 'word-popover';
        document.body.appendChild(popoverEl);

        const component = wordPopoverData();

        // Create a click event on body (outside popover)
        const outsideDiv = document.createElement('div');
        outsideDiv.className = 'some-other-element';
        document.body.appendChild(outsideDiv);

        const event = new MouseEvent('click', { bubbles: true });
        Object.defineProperty(event, 'target', { value: outsideDiv });

        component.handleClickOutside(event);

        expect(mockStore.closePopover).toHaveBeenCalled();
      });

      it('does not close when clicking inside popover', () => {
        mockStore.isPopoverOpen = true;
        const popoverEl = document.createElement('div');
        popoverEl.className = 'word-popover';
        const innerButton = document.createElement('button');
        popoverEl.appendChild(innerButton);
        document.body.appendChild(popoverEl);

        const component = wordPopoverData();

        const event = new MouseEvent('click', { bubbles: true });
        Object.defineProperty(event, 'target', { value: innerButton });

        component.handleClickOutside(event);

        expect(mockStore.closePopover).not.toHaveBeenCalled();
      });

      it('does not close when clicking on word element', () => {
        mockStore.isPopoverOpen = true;
        const wordEl = document.createElement('span');
        wordEl.className = 'word';
        document.body.appendChild(wordEl);

        const component = wordPopoverData();

        const event = new MouseEvent('click', { bubbles: true });
        Object.defineProperty(event, 'target', { value: wordEl });

        component.handleClickOutside(event);

        expect(mockStore.closePopover).not.toHaveBeenCalled();
      });

      it('does not close when popover is not open', () => {
        mockStore.isPopoverOpen = false;
        const component = wordPopoverData();

        const event = new MouseEvent('click', { bubbles: true });
        Object.defineProperty(event, 'target', { value: document.body });

        component.handleClickOutside(event);

        expect(mockStore.closePopover).not.toHaveBeenCalled();
      });
    });

    describe('handleEscape', () => {
      it('closes popover when Escape is pressed', () => {
        mockStore.isPopoverOpen = true;
        const component = wordPopoverData();

        const event = new KeyboardEvent('keydown', { key: 'Escape' });
        component.handleEscape(event);

        expect(mockStore.closePopover).toHaveBeenCalled();
      });

      it('does not close on other keys', () => {
        mockStore.isPopoverOpen = true;
        const component = wordPopoverData();

        const event = new KeyboardEvent('keydown', { key: 'Enter' });
        component.handleEscape(event);

        expect(mockStore.closePopover).not.toHaveBeenCalled();
      });

      it('does not close when popover is not open', () => {
        mockStore.isPopoverOpen = false;
        const component = wordPopoverData();

        const event = new KeyboardEvent('keydown', { key: 'Escape' });
        component.handleEscape(event);

        expect(mockStore.closePopover).not.toHaveBeenCalled();
      });
    });

    describe('init', () => {
      it('sets up Alpine effect for position calculation', () => {
        const component = wordPopoverData();

        component.init();

        expect(Alpine.effect).toHaveBeenCalled();
      });

      it('adds click and keydown event listeners', () => {
        const addEventListenerSpy = vi.spyOn(document, 'addEventListener');
        const component = wordPopoverData();

        component.init();

        expect(addEventListenerSpy).toHaveBeenCalledWith('click', expect.any(Function));
        expect(addEventListenerSpy).toHaveBeenCalledWith('keydown', expect.any(Function));
      });
    });
  });

  describe('initWordPopoverAlpine', () => {
    it('registers component with Alpine.data', () => {
      initWordPopoverAlpine();

      expect(Alpine.data).toHaveBeenCalledWith('wordPopover', wordPopoverData);
    });
  });

  describe('window global', () => {
    it('exposes wordPopoverData on window', () => {
      expect(window.wordPopoverData).toBe(wordPopoverData);
    });
  });
});
