/**
 * Tests for multi_word_form_store.ts - Multi-word expression form store
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

// In-app confirmation
const mockConfirmDiscard = vi.hoisted(() => vi.fn());
vi.mock('../../../../src/frontend/js/shared/components/confirm_dialog', () => ({
  confirmDiscardChanges: mockConfirmDiscard
}));

// Mock Alpine.js
const mockAlpineStore = vi.fn();
vi.mock('alpinejs', () => ({
  default: {
    store: mockAlpineStore
  }
}));

// Mock TermsApi
const mockGetMultiWord = vi.fn();
const mockCreateMultiWord = vi.fn();
const mockUpdateMultiWord = vi.fn();
vi.mock('../../../../src/frontend/js/modules/vocabulary/api/terms_api', () => ({
  TermsApi: {
    getMultiWord: (...args: unknown[]) => mockGetMultiWord(...args),
    createMultiWord: (...args: unknown[]) => mockCreateMultiWord(...args),
    updateMultiWord: (...args: unknown[]) => mockUpdateMultiWord(...args)
  }
}));

// Mock text_renderer
const mockUpdateWordStatusInDOM = vi.fn();
vi.mock('../../../../src/frontend/js/modules/text/pages/reading/text_renderer', () => ({
  updateWordStatusInDOM: (...args: unknown[]) => mockUpdateWordStatusInDOM(...args)
}));

// Import after mocks - need to reset modules to get fresh store
describe('multi_word_form_store.ts', () => {
  let storeData: ReturnType<typeof getStoreData>;

  // Helper to get the store data that was registered with Alpine
  function getStoreData() {
    // The module auto-registers a store, capture it
    const calls = mockAlpineStore.mock.calls;
    const registerCall = calls.find(call => call[0] === 'multiWordForm' && call.length === 2);
    if (registerCall) {
      return registerCall[1];
    }
    return null;
  }

  beforeEach(async () => {
    vi.clearAllMocks();
    vi.resetModules();

    // Re-import the module to get fresh store registration
    await import('../../../../src/frontend/js/modules/vocabulary/stores/multi_word_form_store');

    storeData = getStoreData();
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  // ===========================================================================
  // Initial State Tests
  // ===========================================================================

  describe('initial state', () => {
    it('has correct default formData', () => {
      expect(storeData.formData.text).toBe('');
      expect(storeData.formData.textLc).toBe('');
      expect(storeData.formData.translation).toBe('');
      expect(storeData.formData.romanization).toBe('');
      expect(storeData.formData.sentence).toBe('');
      expect(storeData.formData.status).toBe(1);
      expect(storeData.formData.wordCount).toBe(0);
    });

    it('has null originalData', () => {
      expect(storeData.originalData).toBeNull();
    });

    it('has correct default context values', () => {
      expect(storeData.wordId).toBeNull();
      expect(storeData.textId).toBe(0);
      expect(storeData.position).toBe(0);
      expect(storeData.hex).toBe('');
      expect(storeData.isNewWord).toBe(true);
    });

    it('has correct default UI state', () => {
      expect(storeData.isVisible).toBe(false);
      expect(storeData.isSubmitting).toBe(false);
      expect(storeData.isLoading).toBe(false);
    });

    it('has no validation errors initially', () => {
      expect(storeData.errors.translation).toBeNull();
      expect(storeData.errors.romanization).toBeNull();
      expect(storeData.errors.sentence).toBeNull();
      expect(storeData.errors.general).toBeNull();
    });
  });

  // ===========================================================================
  // isDirty Computed Tests
  // ===========================================================================

  describe('isDirty', () => {
    it('returns false when originalData is null', () => {
      storeData.originalData = null;
      expect(storeData.isDirty).toBe(false);
    });

    it('returns false when no changes made', () => {
      storeData.originalData = {
        text: 'test',
        textLc: 'test',
        translation: 'translation',
        romanization: 'roman',
        sentence: 'sentence',
        status: 1,
        wordCount: 1
      };
      storeData.formData = { ...storeData.originalData };
      expect(storeData.isDirty).toBe(false);
    });

    it('returns true when translation changed', () => {
      storeData.originalData = {
        text: 'test',
        textLc: 'test',
        translation: 'original',
        romanization: '',
        sentence: '',
        status: 1,
        wordCount: 1
      };
      storeData.formData.translation = 'changed';
      expect(storeData.isDirty).toBe(true);
    });

    it('returns true when romanization changed', () => {
      storeData.originalData = {
        text: 'test',
        textLc: 'test',
        translation: '',
        romanization: 'original',
        sentence: '',
        status: 1,
        wordCount: 1
      };
      storeData.formData.romanization = 'changed';
      expect(storeData.isDirty).toBe(true);
    });

    it('returns true when sentence changed', () => {
      storeData.originalData = {
        text: 'test',
        textLc: 'test',
        translation: '',
        romanization: '',
        sentence: 'original',
        status: 1,
        wordCount: 1
      };
      storeData.formData.sentence = 'changed';
      expect(storeData.isDirty).toBe(true);
    });

    it('returns true when status changed', () => {
      storeData.originalData = {
        text: 'test',
        textLc: 'test',
        translation: '',
        romanization: '',
        sentence: '',
        status: 1,
        wordCount: 1
      };
      storeData.formData.status = 2;
      expect(storeData.isDirty).toBe(true);
    });
  });

  // ===========================================================================
  // isValid Computed Tests
  // ===========================================================================

  describe('isValid', () => {
    it('returns true when no errors', () => {
      expect(storeData.isValid).toBe(true);
    });

    it('returns false when translation error exists', () => {
      storeData.errors.translation = 'Error';
      expect(storeData.isValid).toBe(false);
    });

    it('returns false when romanization error exists', () => {
      storeData.errors.romanization = 'Error';
      expect(storeData.isValid).toBe(false);
    });

    it('returns false when sentence error exists', () => {
      storeData.errors.sentence = 'Error';
      expect(storeData.isValid).toBe(false);
    });

    it('returns false when general error exists', () => {
      storeData.errors.general = 'Error';
      expect(storeData.isValid).toBe(false);
    });
  });

  // ===========================================================================
  // canSubmit Computed Tests
  // ===========================================================================

  describe('canSubmit', () => {
    it('returns true when valid and not submitting or loading', () => {
      expect(storeData.canSubmit).toBe(true);
    });

    it('returns false when submitting', () => {
      storeData.isSubmitting = true;
      expect(storeData.canSubmit).toBe(false);
    });

    it('returns false when loading', () => {
      storeData.isLoading = true;
      expect(storeData.canSubmit).toBe(false);
    });

    it('returns false when invalid', () => {
      storeData.errors.translation = 'Error';
      expect(storeData.canSubmit).toBe(false);
    });
  });

  // ===========================================================================
  // reset() Tests
  // ===========================================================================

  describe('reset()', () => {
    it('resets all form data to defaults', () => {
      storeData.formData.text = 'test';
      storeData.formData.translation = 'translation';
      storeData.wordId = 123;
      storeData.isVisible = true;

      storeData.reset();

      expect(storeData.formData.text).toBe('');
      expect(storeData.formData.translation).toBe('');
      expect(storeData.wordId).toBeNull();
      expect(storeData.isVisible).toBe(false);
    });

    it('resets context values', () => {
      storeData.textId = 10;
      storeData.position = 5;
      storeData.hex = 'ABC123';
      storeData.isNewWord = false;

      storeData.reset();

      expect(storeData.textId).toBe(0);
      expect(storeData.position).toBe(0);
      expect(storeData.hex).toBe('');
      expect(storeData.isNewWord).toBe(true);
    });

    it('resets UI state', () => {
      storeData.isSubmitting = true;
      storeData.isLoading = true;

      storeData.reset();

      expect(storeData.isSubmitting).toBe(false);
      expect(storeData.isLoading).toBe(false);
    });

    it('clears errors', () => {
      storeData.errors.translation = 'Error';
      storeData.errors.general = 'General error';

      storeData.reset();

      expect(storeData.errors.translation).toBeNull();
      expect(storeData.errors.general).toBeNull();
    });
  });

  // ===========================================================================
  // close() Tests
  // ===========================================================================

  describe('close()', () => {
    it('calls reset when not dirty', () => {
      storeData.formData.text = 'test';
      storeData.isVisible = true;
      storeData.originalData = null;

      storeData.close();

      expect(storeData.formData.text).toBe('');
      expect(storeData.isVisible).toBe(false);
    });

    it('prompts confirmation when dirty and resets on confirm', async () => {
      storeData.originalData = {
        text: 'test',
        textLc: 'test',
        translation: 'original',
        romanization: '',
        sentence: '',
        status: 1,
        wordCount: 1
      };
      storeData.formData.translation = 'changed';
      storeData.isVisible = true;

      mockConfirmDiscard.mockResolvedValue(true);

      await expect(storeData.close()).resolves.toBe(true);

      expect(mockConfirmDiscard).toHaveBeenCalled();
      expect(storeData.isVisible).toBe(false);
    });

    it('does not close when dirty and user cancels', async () => {
      storeData.originalData = {
        text: 'test',
        textLc: 'test',
        translation: 'original',
        romanization: '',
        sentence: '',
        status: 1,
        wordCount: 1
      };
      storeData.formData.translation = 'changed';
      storeData.isVisible = true;

      mockConfirmDiscard.mockResolvedValue(false);

      await expect(storeData.close()).resolves.toBe(false);

      expect(mockConfirmDiscard).toHaveBeenCalled();
      expect(storeData.isVisible).toBe(true);
    });
  });

  // ===========================================================================
  // validateField() Tests
  // ===========================================================================

  describe('validateField()', () => {
    describe('translation validation', () => {
      it('passes when translation is under limit', () => {
        storeData.formData.translation = 'Short translation';
        storeData.validateField('translation');
        expect(storeData.errors.translation).toBeNull();
      });

      it('fails when translation exceeds 500 characters', () => {
        storeData.formData.translation = 'a'.repeat(501);
        storeData.validateField('translation');
        expect(storeData.errors.translation).toBe('Translation must be 500 characters or less');
      });

      it('passes when translation is exactly 500 characters', () => {
        storeData.formData.translation = 'a'.repeat(500);
        storeData.validateField('translation');
        expect(storeData.errors.translation).toBeNull();
      });
    });

    describe('romanization validation', () => {
      it('passes when romanization is under limit', () => {
        storeData.formData.romanization = 'Short romanization';
        storeData.validateField('romanization');
        expect(storeData.errors.romanization).toBeNull();
      });

      it('fails when romanization exceeds 100 characters', () => {
        storeData.formData.romanization = 'a'.repeat(101);
        storeData.validateField('romanization');
        expect(storeData.errors.romanization).toBe('Romanization must be 100 characters or less');
      });

      it('passes when romanization is exactly 100 characters', () => {
        storeData.formData.romanization = 'a'.repeat(100);
        storeData.validateField('romanization');
        expect(storeData.errors.romanization).toBeNull();
      });
    });

    describe('sentence validation', () => {
      it('passes when sentence is under limit', () => {
        storeData.formData.sentence = 'Short sentence';
        storeData.validateField('sentence');
        expect(storeData.errors.sentence).toBeNull();
      });

      it('fails when sentence exceeds 1000 characters', () => {
        storeData.formData.sentence = 'a'.repeat(1001);
        storeData.validateField('sentence');
        expect(storeData.errors.sentence).toBe('Sentence must be 1000 characters or less');
      });

      it('passes when sentence is exactly 1000 characters', () => {
        storeData.formData.sentence = 'a'.repeat(1000);
        storeData.validateField('sentence');
        expect(storeData.errors.sentence).toBeNull();
      });
    });

    it('handles unknown fields gracefully', () => {
      // Should not throw
      storeData.validateField('text' as 'translation');
      expect(storeData.errors.translation).toBeNull();
    });
  });

  // ===========================================================================
  // validate() Tests
  // ===========================================================================

  describe('validate()', () => {
    it('returns true when all fields valid', () => {
      storeData.formData.translation = 'Valid';
      storeData.formData.romanization = 'Valid';
      storeData.formData.sentence = 'Valid';

      expect(storeData.validate()).toBe(true);
    });

    it('returns false when any field invalid', () => {
      storeData.formData.translation = 'a'.repeat(501);

      expect(storeData.validate()).toBe(false);
    });

    it('validates all fields', () => {
      storeData.formData.translation = 'a'.repeat(501);
      storeData.formData.romanization = 'a'.repeat(101);
      storeData.formData.sentence = 'a'.repeat(1001);

      storeData.validate();

      expect(storeData.errors.translation).not.toBeNull();
      expect(storeData.errors.romanization).not.toBeNull();
      expect(storeData.errors.sentence).not.toBeNull();
    });
  });

  // ===========================================================================
  // loadForEdit() Tests
  // ===========================================================================

  describe('loadForEdit()', () => {
    it('loads data for new multi-word expression', async () => {
      mockGetMultiWord.mockResolvedValue({
        data: {
          id: null,
          text: 'hello world',
          textLc: 'hello world',
          translation: '',
          romanization: '',
          sentence: 'Say hello world to everyone',
          status: 1,
          wordCount: 2,
          isNew: true,
          langId: 1
        }
      });

      await storeData.loadForEdit(10, 5, 'hello world', 2);

      expect(mockGetMultiWord).toHaveBeenCalledWith(10, 5, 'hello world', undefined);
      expect(storeData.textId).toBe(10);
      expect(storeData.position).toBe(5);
      expect(storeData.isNewWord).toBe(true);
      expect(storeData.formData.text).toBe('hello world');
      expect(storeData.isVisible).toBe(true);
      expect(storeData.isLoading).toBe(false);
    });

    it('loads data for existing multi-word expression', async () => {
      mockGetMultiWord.mockResolvedValue({
        data: {
          id: 123,
          text: 'hello world',
          textLc: 'hello world',
          translation: 'greeting',
          romanization: '',
          sentence: 'Say {hello world} to everyone',
          status: 2,
          wordCount: 2,
          isNew: false,
          langId: 1
        }
      });

      await storeData.loadForEdit(10, 5, 'hello world', 2, 123);

      expect(mockGetMultiWord).toHaveBeenCalledWith(10, 5, 'hello world', 123);
      expect(storeData.wordId).toBe(123);
      expect(storeData.isNewWord).toBe(false);
      expect(storeData.formData.translation).toBe('greeting');
      expect(storeData.formData.status).toBe(2);
    });

    it('handles API error response', async () => {
      mockGetMultiWord.mockResolvedValue({
        error: 'Failed to fetch'
      });

      await storeData.loadForEdit(10, 5, 'hello world', 2);

      expect(storeData.errors.general).toBe('Failed to fetch');
      expect(storeData.isLoading).toBe(false);
      expect(storeData.isVisible).toBe(false);
    });

    it('handles data error response', async () => {
      mockGetMultiWord.mockResolvedValue({
        data: {
          error: 'Text not found'
        }
      });

      await storeData.loadForEdit(10, 5, 'hello world', 2);

      expect(storeData.errors.general).toBe('Text not found');
      expect(storeData.isLoading).toBe(false);
    });

    it('handles exception during load', async () => {
      const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
      mockGetMultiWord.mockRejectedValue(new Error('Network error'));

      await storeData.loadForEdit(10, 5, 'hello world', 2);

      expect(storeData.errors.general).toBe('Failed to load multi-word data');
      expect(storeData.isLoading).toBe(false);
      expect(consoleSpy).toHaveBeenCalled();
    });

    it('replaces asterisk translation with empty string', async () => {
      mockGetMultiWord.mockResolvedValue({
        data: {
          id: null,
          text: 'hello',
          textLc: 'hello',
          translation: '*',
          romanization: '',
          sentence: '',
          status: 1,
          wordCount: 1,
          isNew: true,
          langId: 1
        }
      });

      await storeData.loadForEdit(10, 5, 'hello', 1);

      expect(storeData.formData.translation).toBe('');
    });

    it('adds curly braces around term in sentence', async () => {
      mockGetMultiWord.mockResolvedValue({
        data: {
          id: null,
          text: 'hello world',
          textLc: 'hello world',
          translation: '',
          romanization: '',
          sentence: 'Say hello world to everyone',
          status: 1,
          wordCount: 2,
          isNew: true,
          langId: 1
        }
      });

      await storeData.loadForEdit(10, 5, 'hello world', 2);

      expect(storeData.formData.sentence).toBe('Say {hello world} to everyone');
    });

    it('shows romanization when existing data has romanization', async () => {
      mockGetMultiWord.mockResolvedValue({
        data: {
          id: 123,
          text: 'hello',
          textLc: 'hello',
          translation: 'greeting',
          romanization: 'hɛˈloʊ',
          sentence: '',
          status: 1,
          wordCount: 1,
          isNew: false,
          langId: 1
        }
      });

      await storeData.loadForEdit(10, 5, 'hello', 1, 123);

      expect(storeData.showRomanization).toBe(true);
    });
  });

  // ===========================================================================
  // save() Tests
  // ===========================================================================

  describe('save()', () => {
    describe('creating new multi-word expression', () => {
      beforeEach(() => {
        storeData.isNewWord = true;
        storeData.textId = 10;
        storeData.position = 5;
        storeData.formData = {
          text: 'hello world',
          textLc: 'hello world',
          translation: 'greeting',
          romanization: '',
          sentence: 'Say {hello world}',
          status: 2,
          wordCount: 2
        };
      });

      it('creates new expression successfully', async () => {
        mockCreateMultiWord.mockResolvedValue({
          data: {
            term_id: 999,
            term_lc: 'abc123'
          }
        });

        const result = await storeData.save();

        expect(mockCreateMultiWord).toHaveBeenCalledWith({
          textId: 10,
          position: 5,
          text: 'hello world',
          wordCount: 2,
          translation: 'greeting',
          romanization: '',
          sentence: 'Say {hello world}',
          status: 2
        });
        expect(result.success).toBe(true);
        expect(result.wordId).toBe(999);
        expect(result.hex).toBe('abc123');
        expect(storeData.wordId).toBe(999);
        expect(storeData.hex).toBe('abc123');
        expect(storeData.isNewWord).toBe(false);
      });

      it('updates DOM after successful create', async () => {
        mockCreateMultiWord.mockResolvedValue({
          data: {
            term_id: 999,
            term_lc: 'abc123'
          }
        });

        await storeData.save();

        expect(mockUpdateWordStatusInDOM).toHaveBeenCalledWith('abc123', 2, 999);
      });

      it('handles API error on create', async () => {
        mockCreateMultiWord.mockResolvedValue({
          error: 'Create failed'
        });

        const result = await storeData.save();

        expect(result.success).toBe(false);
        expect(result.error).toBe('Create failed');
        expect(storeData.errors.general).toBe('Create failed');
        expect(storeData.isSubmitting).toBe(false);
      });

      it('handles data error on create', async () => {
        mockCreateMultiWord.mockResolvedValue({
          data: {
            error: 'Duplicate term'
          }
        });

        const result = await storeData.save();

        expect(result.success).toBe(false);
        expect(result.error).toBe('Duplicate term');
      });
    });

    describe('updating existing multi-word expression', () => {
      beforeEach(() => {
        storeData.isNewWord = false;
        storeData.wordId = 123;
        storeData.hex = 'existinghex';
        storeData.formData = {
          text: 'hello world',
          textLc: 'hello world',
          translation: 'updated greeting',
          romanization: 'roman',
          sentence: 'Updated sentence',
          status: 3,
          wordCount: 2
        };
      });

      it('updates expression successfully', async () => {
        mockUpdateMultiWord.mockResolvedValue({
          data: {
            success: true
          }
        });

        const result = await storeData.save();

        expect(mockUpdateMultiWord).toHaveBeenCalledWith(123, {
          translation: 'updated greeting',
          romanization: 'roman',
          sentence: 'Updated sentence',
          status: 3
        });
        expect(result.success).toBe(true);
        expect(result.wordId).toBe(123);
      });

      it('updates DOM after successful update', async () => {
        mockUpdateMultiWord.mockResolvedValue({
          data: { success: true }
        });

        await storeData.save();

        expect(mockUpdateWordStatusInDOM).toHaveBeenCalledWith('existinghex', 3, 123);
      });

      it('returns error when wordId is null', async () => {
        storeData.wordId = null;

        const result = await storeData.save();

        expect(result.success).toBe(false);
        expect(result.error).toBe('Word ID is missing');
      });

      it('handles API error on update', async () => {
        mockUpdateMultiWord.mockResolvedValue({
          error: 'Update failed'
        });

        const result = await storeData.save();

        expect(result.success).toBe(false);
        expect(result.error).toBe('Update failed');
      });

      it('handles data error on update', async () => {
        mockUpdateMultiWord.mockResolvedValue({
          data: {
            error: 'Term not found'
          }
        });

        const result = await storeData.save();

        expect(result.success).toBe(false);
        expect(result.error).toBe('Term not found');
      });
    });

    it('returns validation error when form invalid', async () => {
      storeData.formData.translation = 'a'.repeat(501);

      const result = await storeData.save();

      expect(result.success).toBe(false);
      expect(result.error).toBe('Please fix validation errors');
    });

    it('handles exception during save', async () => {
      const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
      storeData.isNewWord = true;
      storeData.textId = 10;
      storeData.position = 5;
      mockCreateMultiWord.mockRejectedValue(new Error('Network error'));

      const result = await storeData.save();

      expect(result.success).toBe(false);
      expect(result.error).toBe('Failed to save multi-word');
      expect(storeData.isSubmitting).toBe(false);
      expect(consoleSpy).toHaveBeenCalled();
    });
  });

  // ===========================================================================
  // initMultiWordFormStore Tests
  // ===========================================================================

  describe('initMultiWordFormStore', () => {
    it('registers store with Alpine', async () => {
      // Store was registered on module import
      expect(mockAlpineStore).toHaveBeenCalledWith('multiWordForm', expect.any(Object));
    });
  });

  // ===========================================================================
  // getMultiWordFormStore Tests
  // ===========================================================================

  describe('getMultiWordFormStore', () => {
    it('returns store from Alpine', async () => {
      const mockStore = { formData: {} };
      mockAlpineStore.mockReturnValue(mockStore);

      const { getMultiWordFormStore } = await import(
        '../../../../src/frontend/js/modules/vocabulary/stores/multi_word_form_store'
      );

      const store = getMultiWordFormStore();

      expect(mockAlpineStore).toHaveBeenCalledWith('multiWordForm');
      expect(store).toBe(mockStore);
    });
  });

  // ===========================================================================
  // Global Window Exposure Tests
  // ===========================================================================

  describe('global window exposure', () => {
    it('exposes getMultiWordFormStore on window', async () => {
      await import(
        '../../../../src/frontend/js/modules/vocabulary/stores/multi_word_form_store'
      );

      expect(typeof window.getMultiWordFormStore).toBe('function');
    });
  });
});
