/**
 * Tests for multi_word_modal.ts - Alpine.js component for multi-word expression editing.
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import Alpine from 'alpinejs';

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

// Import after mocks are set up
import { multiWordModalData, registerMultiWordModal } from '../../../src/frontend/js/modules/vocabulary/components/multi_word_modal';

describe('multi_word_modal.ts', () => {
  let mockStore: ReturnType<typeof createMockStore>;

  function createMockStore(overrides = {}) {
    return {
      isVisible: false,
      isLoading: false,
      isSubmitting: false,
      isNewWord: true,
      formData: {
        text: 'test expression',
        textLc: 'test expression',
        translation: '',
        romanization: '',
        sentence: '',
        status: 1,
        wordCount: 2
      },
      errors: {
        translation: null,
        romanization: null,
        sentence: null,
        general: null
      },
      close: vi.fn(),
      reset: vi.fn(),
      save: vi.fn().mockResolvedValue({ success: true }),
      ...overrides
    };
  }

  beforeEach(() => {
    vi.clearAllMocks();
    mockStore = createMockStore();

    // Setup Alpine.store mock to return our mock store
    vi.mocked(Alpine.store).mockReturnValue(mockStore);

    // Mock requestAnimationFrame
    global.requestAnimationFrame = vi.fn((callback) => {
      callback(0);
      return 0;
    });
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  describe('multiWordModalData', () => {
    it('returns component data object', () => {
      const component = multiWordModalData();

      expect(component).toBeDefined();
      expect(typeof component.init).toBe('function');
      expect(typeof component.close).toBe('function');
      expect(typeof component.save).toBe('function');
      expect(typeof component.setStatus).toBe('function');
      expect(typeof component.isCurrentStatus).toBe('function');
      expect(typeof component.getStatusButtonClass).toBe('function');
    });

    describe('store getter', () => {
      it('returns Alpine store for multiWordForm', () => {
        const component = multiWordModalData();

        const store = component.store;

        expect(Alpine.store).toHaveBeenCalledWith('multiWordForm');
        expect(store).toBe(mockStore);
      });
    });

    describe('isOpen getter', () => {
      it('returns true when store.isVisible is true', () => {
        mockStore.isVisible = true;
        const component = multiWordModalData();

        expect(component.isOpen).toBe(true);
      });

      it('returns false when store.isVisible is false', () => {
        mockStore.isVisible = false;
        const component = multiWordModalData();

        expect(component.isOpen).toBe(false);
      });
    });

    describe('isLoading getter', () => {
      it('returns true when store.isLoading is true', () => {
        mockStore.isLoading = true;
        const component = multiWordModalData();

        expect(component.isLoading).toBe(true);
      });

      it('returns false when store.isLoading is false', () => {
        mockStore.isLoading = false;
        const component = multiWordModalData();

        expect(component.isLoading).toBe(false);
      });
    });

    describe('isSubmitting getter', () => {
      it('returns true when store.isSubmitting is true', () => {
        mockStore.isSubmitting = true;
        const component = multiWordModalData();

        expect(component.isSubmitting).toBe(true);
      });

      it('returns false when store.isSubmitting is false', () => {
        mockStore.isSubmitting = false;
        const component = multiWordModalData();

        expect(component.isSubmitting).toBe(false);
      });
    });

    describe('modalTitle getter', () => {
      it('returns "New Multi-Word Expression" title for new word', () => {
        mockStore.isNewWord = true;
        mockStore.formData.wordCount = 3;
        const component = multiWordModalData();

        expect(component.modalTitle).toBe('New Multi-Word Expression (3 words)');
      });

      it('returns "Edit Multi-Word Expression" title for existing word', () => {
        mockStore.isNewWord = false;
        mockStore.formData.wordCount = 2;
        const component = multiWordModalData();

        expect(component.modalTitle).toBe('Edit Multi-Word Expression (2 words)');
      });
    });

    describe('statuses getter', () => {
      it('returns array of 5 status options', () => {
        const component = multiWordModalData();

        expect(component.statuses).toHaveLength(5);
        expect(component.statuses[0]).toEqual({
          value: 1,
          label: 'Learning (1)',
          abbr: '1',
          class: 'is-danger'
        });
        // The shared store labels every learning stage with its level, 5
        // included; this file's own table stopped at "Learned" (#238).
        expect(component.statuses[4]).toEqual({
          value: 5,
          label: 'Learned (5)',
          abbr: '5',
          class: 'is-success'
        });
      });
    });

    describe('close', () => {
      it('calls store.close()', () => {
        const component = multiWordModalData();

        component.close();

        expect(mockStore.close).toHaveBeenCalled();
      });
    });

    describe('save', () => {
      it('calls store.save() and resets on success', async () => {
        mockStore.save = vi.fn().mockResolvedValue({ success: true });
        const component = multiWordModalData();

        await component.save();

        expect(mockStore.save).toHaveBeenCalled();
        expect(mockStore.reset).toHaveBeenCalled();
      });

      it('does not reset on failure', async () => {
        mockStore.save = vi.fn().mockResolvedValue({ success: false, error: 'Some error' });
        const component = multiWordModalData();

        await component.save();

        expect(mockStore.save).toHaveBeenCalled();
        expect(mockStore.reset).not.toHaveBeenCalled();
      });
    });

    describe('setStatus', () => {
      it('sets store.formData.status to provided value', () => {
        const component = multiWordModalData();

        component.setStatus(3);

        expect(mockStore.formData.status).toBe(3);
      });

      it('can set any status value 1-5', () => {
        const component = multiWordModalData();

        for (const status of [1, 2, 3, 4, 5]) {
          component.setStatus(status);
          expect(mockStore.formData.status).toBe(status);
        }
      });
    });

    describe('isCurrentStatus', () => {
      it('returns true when status matches current', () => {
        mockStore.formData.status = 2;
        const component = multiWordModalData();

        expect(component.isCurrentStatus(2)).toBe(true);
      });

      it('returns false when status does not match current', () => {
        mockStore.formData.status = 2;
        const component = multiWordModalData();

        expect(component.isCurrentStatus(1)).toBe(false);
        expect(component.isCurrentStatus(3)).toBe(false);
      });
    });

    describe('getStatusButtonClass', () => {
      it('returns active class for current status', () => {
        mockStore.formData.status = 1;
        const component = multiWordModalData();

        const className = component.getStatusButtonClass(1);

        expect(className).toBe('button is-small is-danger');
        expect(className).not.toContain('is-outlined');
      });

      it('returns outlined class for non-current status', () => {
        mockStore.formData.status = 1;
        const component = multiWordModalData();

        const className = component.getStatusButtonClass(2);

        expect(className).toBe('button is-small is-outlined is-warning');
      });

      it('returns correct color class for each status', () => {
        mockStore.formData.status = 0; // Set to non-matching status
        const component = multiWordModalData();

        expect(component.getStatusButtonClass(1)).toContain('is-danger');
        expect(component.getStatusButtonClass(2)).toContain('is-warning');
        expect(component.getStatusButtonClass(3)).toContain('is-info');
        expect(component.getStatusButtonClass(4)).toContain('is-primary');
        expect(component.getStatusButtonClass(5)).toContain('is-success');
      });

      it('handles unknown status gracefully', () => {
        mockStore.formData.status = 0;
        const component = multiWordModalData();

        const className = component.getStatusButtonClass(99);

        expect(className).toBe('button is-small is-outlined ');
      });
    });

    describe('init', () => {
      it('sets up Alpine effect for icon initialization', () => {
        const component = multiWordModalData();

        component.init();

        expect(Alpine.effect).toHaveBeenCalled();
      });

      it('calls initIcons when store becomes visible', async () => {
        const { initIcons } = await import('../../../src/frontend/js/shared/icons/lucide_icons');

        mockStore.isVisible = true;
        const component = multiWordModalData();

        component.init();

        // Effect runs immediately in our mock
        expect(requestAnimationFrame).toHaveBeenCalled();
        expect(initIcons).toHaveBeenCalled();
      });
    });
  });

  describe('registerMultiWordModal', () => {
    it('registers component with Alpine.data', () => {
      registerMultiWordModal();

      expect(Alpine.data).toHaveBeenCalledWith('multiWordModal', multiWordModalData);
    });
  });

  describe('window global', () => {
    it('exposes multiWordModalData on window', () => {
      expect(window.multiWordModalData).toBe(multiWordModalData);
    });
  });
});
