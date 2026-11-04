/**
 * Tests for word_modal.ts - Alpine.js component for Bulma word edit modal.
 */
import { answerConfirms, confirmPrompts, settle } from '../helpers/confirm_answers';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import Alpine from 'alpinejs';

// Use vi.hoisted to ensure mock function is available during hoisting
const mockSpeechDispatcher = vi.hoisted(() => vi.fn());

// In-app confirmation
const mockConfirmDiscard = vi.hoisted(() => vi.fn());
vi.mock('../../../src/frontend/js/shared/components/confirm_dialog', async (importOriginal) => ({
  ...await importOriginal<object>(),
  confirmDiscardChanges: mockConfirmDiscard
}));

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
  wordModalData,
  initWordModalAlpine,
} from '../../../src/frontend/js/modules/vocabulary/components/word_modal';

describe('word_modal.ts', () => {
  let mockWordStore: ReturnType<typeof createMockWordStore>;
  let mockFormStore: ReturnType<typeof createMockFormStore>;

  function createMockWordStore(overrides = {}) {
    return {
      isEditModalOpen: false,
      isLoading: false,
      textId: 1,
      langId: 5,
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
      closeEditModal: vi.fn(),
      setStatus: vi.fn().mockResolvedValue(undefined),
      createQuickWord: vi.fn().mockResolvedValue(undefined),
      deleteWord: vi.fn().mockResolvedValue(undefined),
      getDictUrl: vi.fn((which: string) => `https://dict.example.com/${which}`),
      ...overrides
    };
  }

  function createMockFormStore(overrides = {}) {
    return {
      isLoading: false,
      isNewWord: false,
      isDirty: false,
      shouldCloseModal: false,
      shouldReturnToInfo: false,
      loadForEdit: vi.fn().mockResolvedValue(undefined),
      reset: vi.fn(),
      ...overrides
    };
  }

  beforeEach(() => {
    vi.clearAllMocks();
    mockWordStore = createMockWordStore();
    mockFormStore = createMockFormStore();

    // Setup Alpine.store mock to return appropriate store
    vi.mocked(Alpine.store).mockImplementation((storeName: string) => {
      if (storeName === 'words') return mockWordStore;
      if (storeName === 'wordForm') return mockFormStore;
      return undefined;
    });

    // Mock requestAnimationFrame
    global.requestAnimationFrame = vi.fn((callback) => {
      callback(0);
      return 0;
    });

    // Mock confirm
    answerConfirms(true);
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  describe('wordModalData', () => {
    it('returns component data object with all required properties', () => {
      const component = wordModalData();

      expect(component).toBeDefined();
      expect(component.viewMode).toBe('info');
      expect(typeof component.init).toBe('function');
      expect(typeof component.close).toBe('function');
      expect(typeof component.speakWord).toBe('function');
      expect(typeof component.setStatus).toBe('function');
      expect(typeof component.markWellKnown).toBe('function');
      expect(typeof component.markIgnored).toBe('function');
      expect(typeof component.deleteWord).toBe('function');
    });

    describe('store getter', () => {
      it('returns Alpine store for words', () => {
        const component = wordModalData();

        const store = component.store;

        expect(Alpine.store).toHaveBeenCalledWith('words');
        expect(store).toBe(mockWordStore);
      });
    });

    describe('formStore getter', () => {
      it('returns Alpine store for wordForm', () => {
        const component = wordModalData();

        const store = component.formStore;

        expect(Alpine.store).toHaveBeenCalledWith('wordForm');
        expect(store).toBe(mockFormStore);
      });
    });

    describe('word getter', () => {
      it('returns selected word from store', () => {
        const component = wordModalData();

        const word = component.word;

        expect(mockWordStore.getSelectedWord).toHaveBeenCalled();
        expect(word?.text).toBe('hello');
      });
    });

    describe('isOpen getter', () => {
      it('returns true when store.isEditModalOpen is true', () => {
        mockWordStore.isEditModalOpen = true;
        const component = wordModalData();

        expect(component.isOpen).toBe(true);
      });

      it('returns false when store.isEditModalOpen is false', () => {
        mockWordStore.isEditModalOpen = false;
        const component = wordModalData();

        expect(component.isOpen).toBe(false);
      });
    });

    describe('isLoading getter', () => {
      it('returns true when wordStore is loading', () => {
        mockWordStore.isLoading = true;
        mockFormStore.isLoading = false;
        const component = wordModalData();

        expect(component.isLoading).toBe(true);
      });

      it('returns true when formStore is loading', () => {
        mockWordStore.isLoading = false;
        mockFormStore.isLoading = true;
        const component = wordModalData();

        expect(component.isLoading).toBe(true);
      });

      it('returns false when neither store is loading', () => {
        mockWordStore.isLoading = false;
        mockFormStore.isLoading = false;
        const component = wordModalData();

        expect(component.isLoading).toBe(false);
      });
    });

    describe('isUnknown getter', () => {
      it('returns true when word is null', () => {
        mockWordStore.getSelectedWord = vi.fn().mockReturnValue(null);
        const component = wordModalData();

        expect(component.isUnknown).toBe(true);
      });

      it('returns true when word status is 0', () => {
        mockWordStore.getSelectedWord = vi.fn().mockReturnValue({
          hex: 'test',
          text: 'test',
          status: 0
        });
        const component = wordModalData();

        expect(component.isUnknown).toBe(true);
      });

      it('returns false when word has non-zero status', () => {
        mockWordStore.getSelectedWord = vi.fn().mockReturnValue({
          hex: 'test',
          text: 'test',
          status: 1
        });
        const component = wordModalData();

        expect(component.isUnknown).toBe(false);
      });
    });

    describe('modalTitle getter', () => {
      it('returns "Add Term" when in edit mode with new word', () => {
        mockFormStore.isNewWord = true;
        const component = wordModalData();
        component.viewMode = 'edit';

        expect(component.modalTitle).toBe('Add Term');
      });

      it('returns "Edit Term" when in edit mode with existing word', () => {
        mockFormStore.isNewWord = false;
        const component = wordModalData();
        component.viewMode = 'edit';

        expect(component.modalTitle).toBe('Edit Term');
      });

      it('returns "New Word" when in info mode with unknown word', () => {
        mockWordStore.getSelectedWord = vi.fn().mockReturnValue(null);
        const component = wordModalData();
        component.viewMode = 'info';

        expect(component.modalTitle).toBe('New Word');
      });

      it('returns "Word" when in info mode with known word', () => {
        const component = wordModalData();
        component.viewMode = 'info';

        expect(component.modalTitle).toBe('Word');
      });
    });

    describe('statuses getter', () => {
      it('returns array of 7 status options', () => {
        const component = wordModalData();

        expect(component.statuses).toHaveLength(7);
        expect(component.statuses[0]).toEqual({
          value: 1,
          label: 'Learning (1)',
          abbr: '1',
          class: 'is-danger'
        });
        expect(component.statuses[6]).toEqual({
          value: 98,
          label: 'Ignored',
          abbr: 'Ignored',
          class: 'is-light'
        });
      });
    });

    describe('close', () => {
      it('closes modal without confirmation when form is not dirty', async () => {
        mockFormStore.isDirty = false;

        await wordModalData().close();

        expect(mockConfirmDiscard).not.toHaveBeenCalled();
        expect(mockWordStore.closeEditModal).toHaveBeenCalled();
      });

      it('resets form and closes modal when user confirms', async () => {
        mockFormStore.isDirty = true;
        mockConfirmDiscard.mockResolvedValue(true);

        await wordModalData().close();

        expect(confirmPrompts).not.toHaveBeenCalled();
        expect(mockFormStore.reset).toHaveBeenCalled();
        expect(mockWordStore.closeEditModal).toHaveBeenCalled();
      });

      it('does not close modal when user cancels', async () => {
        mockFormStore.isDirty = true;
        mockConfirmDiscard.mockResolvedValue(false);

        await wordModalData().close();

        expect(mockFormStore.reset).not.toHaveBeenCalled();
        expect(mockWordStore.closeEditModal).not.toHaveBeenCalled();
      });
    });

    describe('speakWord', () => {
      it('calls speechDispatcher with word text and language', () => {
        const component = wordModalData();

        component.speakWord();

        expect(mockSpeechDispatcher).toHaveBeenCalledWith('hello', 5);
      });

      it('does nothing when word is null', () => {
        mockWordStore.getSelectedWord = vi.fn().mockReturnValue(null);
        const component = wordModalData();

        component.speakWord();

        expect(mockSpeechDispatcher).not.toHaveBeenCalled();
      });

      it('does nothing when langId is not set', () => {
        mockWordStore.langId = 0;
        const component = wordModalData();

        component.speakWord();

        expect(mockSpeechDispatcher).not.toHaveBeenCalled();
      });
    });

    describe('setStatus', () => {
      it('calls store.setStatus with word hex and status', async () => {
        const component = wordModalData();

        await component.setStatus(3);

        expect(mockWordStore.setStatus).toHaveBeenCalledWith('abc123', 3);
      });

      it('does nothing when word is null', async () => {
        mockWordStore.getSelectedWord = vi.fn().mockReturnValue(null);
        const component = wordModalData();

        await component.setStatus(3);

        expect(mockWordStore.setStatus).not.toHaveBeenCalled();
      });
    });

    describe('markWellKnown', () => {
      it('calls store.createQuickWord with status 99', async () => {
        const component = wordModalData();

        await component.markWellKnown();

        expect(mockWordStore.createQuickWord).toHaveBeenCalledWith('abc123', 10, 99);
      });

      it('does nothing when word is null', async () => {
        mockWordStore.getSelectedWord = vi.fn().mockReturnValue(null);
        const component = wordModalData();

        await component.markWellKnown();

        expect(mockWordStore.createQuickWord).not.toHaveBeenCalled();
      });
    });

    describe('markIgnored', () => {
      it('calls store.createQuickWord with status 98', async () => {
        const component = wordModalData();

        await component.markIgnored();

        expect(mockWordStore.createQuickWord).toHaveBeenCalledWith('abc123', 10, 98);
      });

      it('does nothing when word is null', async () => {
        mockWordStore.getSelectedWord = vi.fn().mockReturnValue(null);
        const component = wordModalData();

        await component.markIgnored();

        expect(mockWordStore.createQuickWord).not.toHaveBeenCalled();
      });
    });

    describe('deleteWord', () => {
      it('confirms and calls store.deleteWord when user confirms', async () => {
        answerConfirms(true);
        const component = wordModalData();

        await component.deleteWord();

        await settle();
        expect(confirmPrompts).toHaveBeenCalledWith('Delete this term?');
        expect(mockWordStore.deleteWord).toHaveBeenCalledWith('abc123');
      });

      it('does not delete when user cancels', async () => {
        answerConfirms(false);
        const component = wordModalData();

        await component.deleteWord();

        await settle();
        expect(mockWordStore.deleteWord).not.toHaveBeenCalled();
      });

      it('does nothing when word is null', async () => {
        mockWordStore.getSelectedWord = vi.fn().mockReturnValue(null);
        const component = wordModalData();

        await component.deleteWord();

        expect(confirmPrompts).not.toHaveBeenCalled();
        expect(mockWordStore.deleteWord).not.toHaveBeenCalled();
      });
    });


    describe('getDictUrl', () => {
      it('delegates to store.getDictUrl', () => {
        const component = wordModalData();

        const url = component.getDictUrl('dict1');

        expect(mockWordStore.getDictUrl).toHaveBeenCalledWith('dict1');
        expect(url).toBe('https://dict.example.com/dict1');
      });
    });

    describe('isCurrentStatus', () => {
      it('returns true when status matches word status', () => {
        const component = wordModalData();

        expect(component.isCurrentStatus(1)).toBe(true);
      });

      it('returns false when status does not match', () => {
        const component = wordModalData();

        expect(component.isCurrentStatus(2)).toBe(false);
      });

      it('returns false when word is null', () => {
        mockWordStore.getSelectedWord = vi.fn().mockReturnValue(null);
        const component = wordModalData();

        expect(component.isCurrentStatus(1)).toBe(false);
      });
    });

    describe('getStatusButtonClass', () => {
      it('returns active class for current status', () => {
        const component = wordModalData();

        const className = component.getStatusButtonClass(1);

        expect(className).toBe('button is-small is-danger');
      });

      it('returns outlined class for non-current status', () => {
        const component = wordModalData();

        const className = component.getStatusButtonClass(2);

        expect(className).toBe('button is-small is-outlined is-warning');
      });
    });

    describe('showEditForm', () => {
      it('loads form data and sets view mode to edit', async () => {
        const component = wordModalData();

        await component.showEditForm();

        expect(mockFormStore.loadForEdit).toHaveBeenCalledWith(1, 10, 100);
        expect(component.viewMode).toBe('edit');
      });

      it('does nothing when word is null', async () => {
        mockWordStore.getSelectedWord = vi.fn().mockReturnValue(null);
        const component = wordModalData();
        component.viewMode = 'info';

        await component.showEditForm();

        expect(mockFormStore.loadForEdit).not.toHaveBeenCalled();
        expect(component.viewMode).toBe('info');
      });
    });

    describe('hideEditForm', () => {
      it('resets form and closes modal', () => {
        const component = wordModalData();

        component.hideEditForm();

        expect(mockFormStore.reset).toHaveBeenCalled();
        expect(mockWordStore.closeEditModal).toHaveBeenCalled();
      });
    });

    describe('onFormSaved', () => {
      it('resets form and closes modal', () => {
        const component = wordModalData();

        component.onFormSaved();

        expect(mockFormStore.reset).toHaveBeenCalled();
        expect(mockWordStore.closeEditModal).toHaveBeenCalled();
      });
    });

    describe('onFormCancelled', () => {
      it('resets form and closes modal', () => {
        const component = wordModalData();

        component.onFormCancelled();

        expect(mockFormStore.reset).toHaveBeenCalled();
        expect(mockWordStore.closeEditModal).toHaveBeenCalled();
      });
    });

    describe('init', () => {
      it('sets up Alpine effects', () => {
        const component = wordModalData();

        component.init();

        // Should have called effect 3 times for the 3 watchers
        expect(Alpine.effect).toHaveBeenCalled();
      });

      it('handles shouldCloseModal signal', () => {
        mockFormStore.shouldCloseModal = true;
        const component = wordModalData();

        component.init();

        // After effect runs, shouldCloseModal should be reset
        expect(mockFormStore.shouldCloseModal).toBe(false);
        expect(mockFormStore.reset).toHaveBeenCalled();
        expect(mockWordStore.closeEditModal).toHaveBeenCalled();
      });

      it('handles shouldReturnToInfo signal', () => {
        mockFormStore.shouldReturnToInfo = true;
        const component = wordModalData();

        component.init();

        expect(mockFormStore.shouldReturnToInfo).toBe(false);
        expect(mockFormStore.reset).toHaveBeenCalled();
        expect(mockWordStore.closeEditModal).toHaveBeenCalled();
      });
    });
  });

  describe('initWordModalAlpine', () => {
    it('registers component with Alpine.data', () => {
      initWordModalAlpine();

      expect(Alpine.data).toHaveBeenCalledWith('wordModal', wordModalData);
    });
  });

  describe('window global', () => {
    it('exposes wordModalData on window', () => {
      expect(window.wordModalData).toBe(wordModalData);
    });
  });
});
