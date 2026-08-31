/**
 * Tests for word_edit_form.ts - Word edit form Alpine component
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

// Mock Alpine.js
vi.mock('alpinejs', () => ({
  default: {
    data: vi.fn(),
    store: vi.fn()
  }
}));

// Mock text_renderer
vi.mock('../../../../src/frontend/js/modules/text/pages/reading/text_renderer', () => ({
  updateWordStatusInDOM: vi.fn(),
  updateWordTranslationInDOM: vi.fn()
}));

// Create mock form store
const mockFormStore = {
  isLoading: false,
  isSubmitting: false,
  isDirty: false,
  isValid: true,
  isNewWord: true,
  showRomanization: false,
  formData: {
    text: 'test',
    textLc: 'test',
    translation: '',
    romanization: '',
    sentence: '',
    status: 1,
    tags: [] as string[]
  },
  allTags: ['tag1', 'tag2', 'tag3', 'news', 'sports'],
  errors: {
    lemma: null,
    translation: null,
    romanization: null,
    sentence: null,
    notes: null,
    general: null
  } as Record<string, string | null>,
  shouldCloseModal: false,
  shouldReturnToInfo: false,
  validateField: vi.fn(),
  save: vi.fn(),
  copyTranslationFromSimilar: vi.fn()
};

// Create mock word store
const mockWordStore = {
  updateWordInStore: vi.fn()
};

import Alpine from 'alpinejs';
import { wordEditFormData, initWordEditFormAlpine } from '../../../../src/frontend/js/modules/vocabulary/components/word_edit_form';
import { updateWordStatusInDOM, updateWordTranslationInDOM } from '../../../../src/frontend/js/modules/text/pages/reading/text_renderer';

describe('word_edit_form.ts', () => {
  beforeEach(() => {
    vi.clearAllMocks();

    // Mock Alpine.store to return our mock stores
    (Alpine.store as ReturnType<typeof vi.fn>).mockImplementation((name: string) => {
      if (name === 'wordForm') return mockFormStore;
      if (name === 'words') return mockWordStore;
      return {};
    });

    // Reset mock store state
    mockFormStore.isLoading = false;
    mockFormStore.isSubmitting = false;
    mockFormStore.isDirty = false;
    mockFormStore.isValid = true;
    mockFormStore.isNewWord = true;
    mockFormStore.showRomanization = false;
    mockFormStore.formData.tags = [];
    mockFormStore.allTags = ['tag1', 'tag2', 'tag3', 'news', 'sports'];
    mockFormStore.errors = {
      lemma: null,
      translation: null,
      romanization: null,
      sentence: null,
      notes: null,
      general: null
    };
    mockFormStore.shouldCloseModal = false;
    mockFormStore.shouldReturnToInfo = false;
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  // ===========================================================================
  // wordEditFormData Factory Tests
  // ===========================================================================

  describe('wordEditFormData', () => {
    it('creates component with default values', () => {
      const component = wordEditFormData();

      expect(component.tagInput).toBe('');
      expect(component.showTagSuggestions).toBe(false);
      expect(component.filteredTags).toEqual([]);
    });

    it('has formStore getter', () => {
      const component = wordEditFormData();

      expect(component.formStore).toBe(mockFormStore);
    });

    it('has wordStore getter', () => {
      const component = wordEditFormData();

      expect(component.wordStore).toBe(mockWordStore);
    });
  });

  // ===========================================================================
  // Computed Properties Tests
  // ===========================================================================

  describe('computed properties', () => {
    it('isLoading returns formStore value', () => {
      mockFormStore.isLoading = true;
      const component = wordEditFormData();

      expect(component.isLoading).toBe(true);
    });

    it('isSubmitting returns formStore value', () => {
      mockFormStore.isSubmitting = true;
      const component = wordEditFormData();

      expect(component.isSubmitting).toBe(true);
    });

    it('isDirty returns formStore value', () => {
      mockFormStore.isDirty = true;
      const component = wordEditFormData();

      expect(component.isDirty).toBe(true);
    });

    it('isValid returns formStore value', () => {
      mockFormStore.isValid = false;
      const component = wordEditFormData();

      expect(component.isValid).toBe(false);
    });

    it('isNewWord returns formStore value', () => {
      mockFormStore.isNewWord = false;
      const component = wordEditFormData();

      expect(component.isNewWord).toBe(false);
    });

    it('showRomanization returns formStore value', () => {
      mockFormStore.showRomanization = true;
      const component = wordEditFormData();

      expect(component.showRomanization).toBe(true);
    });

    it('statuses returns status definitions', () => {
      const component = wordEditFormData();

      expect(component.statuses).toHaveLength(7);
      expect(component.statuses[0]).toEqual({ value: 1, label: 'Learning (1)', abbr: '1' });
      expect(component.statuses[6]).toEqual({ value: 98, label: 'Ignored', abbr: 'Ignored' });
    });
  });

  // ===========================================================================
  // validateField Tests
  // ===========================================================================

  describe('validateField', () => {
    it('calls formStore.validateField', () => {
      const component = wordEditFormData();

      component.validateField('translation');

      expect(mockFormStore.validateField).toHaveBeenCalledWith('translation');
    });
  });

  // ===========================================================================
  // save Tests
  // ===========================================================================

  describe('save', () => {
    it('calls formStore.save', async () => {
      mockFormStore.save.mockResolvedValue({ success: false });

      const component = wordEditFormData();
      await component.save();

      expect(mockFormStore.save).toHaveBeenCalled();
    });

    it('updates word store on success', async () => {
      mockFormStore.save.mockResolvedValue({
        success: true,
        hex: 'ABC123',
        wordId: 999
      });
      mockFormStore.formData.status = 2;
      mockFormStore.formData.translation = 'test translation';
      mockFormStore.formData.romanization = 'test roman';
      mockFormStore.formData.tags = ['tag1'];

      const component = wordEditFormData();
      await component.save();

      expect(mockWordStore.updateWordInStore).toHaveBeenCalledWith('ABC123', {
        wordId: 999,
        status: 2,
        translation: 'test translation',
        romanization: 'test roman',
        tags: 'tag1'
      });
    });

    it('updates DOM on success', async () => {
      mockFormStore.save.mockResolvedValue({
        success: true,
        hex: 'ABC123',
        wordId: 999
      });
      mockFormStore.formData.status = 3;
      mockFormStore.formData.translation = 'translation';
      mockFormStore.formData.romanization = 'roman';

      const component = wordEditFormData();
      await component.save();

      expect(updateWordStatusInDOM).toHaveBeenCalledWith('ABC123', 3, 999);
      expect(updateWordTranslationInDOM).toHaveBeenCalledWith('ABC123', 'translation', 'roman');
    });

    it('calls onSaved callback if set', async () => {
      mockFormStore.save.mockResolvedValue({
        success: true,
        hex: 'ABC123',
        wordId: 999
      });

      const component = wordEditFormData();
      const onSaved = vi.fn();
      component.onSaved = onSaved;

      await component.save();

      expect(onSaved).toHaveBeenCalledWith({
        success: true,
        hex: 'ABC123',
        wordId: 999
      });
    });

    it('sets shouldCloseModal when no callback', async () => {
      mockFormStore.save.mockResolvedValue({
        success: true,
        hex: 'ABC123'
      });

      const component = wordEditFormData();
      await component.save();

      expect(mockFormStore.shouldCloseModal).toBe(true);
    });

    it('does not update on failure', async () => {
      mockFormStore.save.mockResolvedValue({ success: false, error: 'Error' });

      const component = wordEditFormData();
      await component.save();

      expect(mockWordStore.updateWordInStore).not.toHaveBeenCalled();
      expect(updateWordStatusInDOM).not.toHaveBeenCalled();
    });

    it('jumps to the tab holding the invalid field on failure', async () => {
      mockFormStore.save.mockResolvedValue({ success: false, error: 'Error' });
      mockFormStore.errors.sentence = 'Sentence must be 1000 characters or less';

      const component = wordEditFormData();
      component.activeTab = 'tags';
      await component.save();

      expect(component.activeTab).toBe('example');
    });

    it('leaves activeTab untouched when save fails with no field errors', async () => {
      mockFormStore.save.mockResolvedValue({ success: false, error: 'Failed to save term' });

      const component = wordEditFormData();
      component.activeTab = 'tags';
      await component.save();

      expect(component.activeTab).toBe('tags');
    });
  });

  // ===========================================================================
  // Tab Tests
  // ===========================================================================

  describe('tabs', () => {
    it('defaults activeTab to translate', () => {
      const component = wordEditFormData();
      expect(component.activeTab).toBe('translate');
    });

    it('setActiveTab switches the active tab', () => {
      const component = wordEditFormData();
      component.setActiveTab('notes');
      expect(component.activeTab).toBe('notes');
    });

    it('tabHasError reports translation/romanization errors on the translate tab', () => {
      const component = wordEditFormData();
      expect(component.tabHasError('translate')).toBe(false);

      mockFormStore.errors.translation = 'Translation must be 500 characters or less';
      expect(component.tabHasError('translate')).toBe(true);

      mockFormStore.errors.translation = null;
      mockFormStore.errors.romanization = 'Romanization must be 100 characters or less';
      expect(component.tabHasError('translate')).toBe(true);
    });

    it('tabHasError reports sentence errors on the example tab', () => {
      const component = wordEditFormData();
      mockFormStore.errors.sentence = 'Sentence must be 1000 characters or less';
      expect(component.tabHasError('example')).toBe(true);
    });

    it('tabHasError reports notes errors on the notes tab', () => {
      const component = wordEditFormData();
      mockFormStore.errors.notes = 'Notes must be 1000 characters or less';
      expect(component.tabHasError('notes')).toBe(true);
    });

    it('tabHasError is always false for the tags tab', () => {
      const component = wordEditFormData();
      expect(component.tabHasError('tags')).toBe(false);
    });
  });

  // ===========================================================================
  // cancel Tests
  // ===========================================================================

  describe('cancel', () => {
    it('calls onCancelled callback if set', () => {
      const component = wordEditFormData();
      const onCancelled = vi.fn();
      component.onCancelled = onCancelled;

      component.cancel();

      expect(onCancelled).toHaveBeenCalled();
    });

    it('sets shouldReturnToInfo when no callback', () => {
      const component = wordEditFormData();

      component.cancel();

      expect(mockFormStore.shouldReturnToInfo).toBe(true);
    });

    it('shows confirmation when dirty', () => {
      mockFormStore.isDirty = true;
      const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);

      const component = wordEditFormData();
      component.cancel();

      expect(confirmSpy).toHaveBeenCalled();
    });

    it('does not cancel if user declines confirmation', () => {
      mockFormStore.isDirty = true;
      vi.spyOn(window, 'confirm').mockReturnValue(false);

      const component = wordEditFormData();
      component.cancel();

      expect(mockFormStore.shouldReturnToInfo).toBe(false);
    });

    it('cancels if user confirms', () => {
      mockFormStore.isDirty = true;
      vi.spyOn(window, 'confirm').mockReturnValue(true);

      const component = wordEditFormData();
      component.cancel();

      expect(mockFormStore.shouldReturnToInfo).toBe(true);
    });
  });

  // ===========================================================================
  // Tag Methods Tests
  // ===========================================================================

  describe('tag methods', () => {
    describe('addTag', () => {
      it('adds tag to formData.tags', () => {
        const component = wordEditFormData();
        mockFormStore.formData.tags = [];

        component.addTag('newtag');

        expect(mockFormStore.formData.tags).toContain('newtag');
      });

      it('trims whitespace from tag', () => {
        const component = wordEditFormData();
        mockFormStore.formData.tags = [];

        component.addTag('  newtag  ');

        expect(mockFormStore.formData.tags).toContain('newtag');
      });

      it('does not add duplicate tags', () => {
        const component = wordEditFormData();
        mockFormStore.formData.tags = ['existing'];

        component.addTag('existing');

        expect(mockFormStore.formData.tags).toHaveLength(1);
      });

      it('does not add empty tags', () => {
        const component = wordEditFormData();
        mockFormStore.formData.tags = [];

        component.addTag('');

        expect(mockFormStore.formData.tags).toHaveLength(0);
      });

      it('clears tagInput and hides suggestions', () => {
        const component = wordEditFormData();
        component.tagInput = 'test';
        component.showTagSuggestions = true;

        component.addTag('newtag');

        expect(component.tagInput).toBe('');
        expect(component.showTagSuggestions).toBe(false);
      });
    });

    describe('removeTag', () => {
      it('removes tag from formData.tags', () => {
        const component = wordEditFormData();
        mockFormStore.formData.tags = ['tag1', 'tag2', 'tag3'];

        component.removeTag('tag2');

        expect(mockFormStore.formData.tags).toEqual(['tag1', 'tag3']);
      });

      it('does nothing if tag not found', () => {
        const component = wordEditFormData();
        mockFormStore.formData.tags = ['tag1', 'tag2'];

        component.removeTag('nonexistent');

        expect(mockFormStore.formData.tags).toEqual(['tag1', 'tag2']);
      });
    });

    describe('filterTags', () => {
      it('filters tags starting with input', () => {
        const component = wordEditFormData();
        mockFormStore.formData.tags = [];
        component.tagInput = 'ta';

        component.filterTags();

        expect(component.filteredTags).toContain('tag1');
        expect(component.filteredTags).toContain('tag2');
        expect(component.filteredTags).toContain('tag3');
        expect(component.filteredTags).not.toContain('news');
      });

      it('excludes already selected tags', () => {
        const component = wordEditFormData();
        mockFormStore.formData.tags = ['tag1'];
        component.tagInput = 'ta';

        component.filterTags();

        expect(component.filteredTags).not.toContain('tag1');
        expect(component.filteredTags).toContain('tag2');
      });

      it('limits to 8 suggestions', () => {
        const component = wordEditFormData();
        mockFormStore.allTags = Array.from({ length: 20 }, (_, i) => `tag${i}`);
        mockFormStore.formData.tags = [];
        component.tagInput = 'tag';

        component.filterTags();

        expect(component.filteredTags.length).toBeLessThanOrEqual(8);
      });

      it('hides suggestions when input empty', () => {
        const component = wordEditFormData();
        component.tagInput = '';
        component.showTagSuggestions = true;

        component.filterTags();

        expect(component.showTagSuggestions).toBe(false);
        expect(component.filteredTags).toEqual([]);
      });

      it('shows suggestions when matches found', () => {
        const component = wordEditFormData();
        mockFormStore.formData.tags = [];
        component.tagInput = 'ne';

        component.filterTags();

        expect(component.showTagSuggestions).toBe(true);
        expect(component.filteredTags).toContain('news');
      });
    });

    describe('selectTagSuggestion', () => {
      it('calls addTag', () => {
        const component = wordEditFormData();
        mockFormStore.formData.tags = [];

        component.selectTagSuggestion('news');

        expect(mockFormStore.formData.tags).toContain('news');
      });
    });

    describe('hideTagSuggestions', () => {
      it('hides suggestions after delay', async () => {
        vi.useFakeTimers();
        const component = wordEditFormData();
        component.showTagSuggestions = true;

        component.hideTagSuggestions();

        expect(component.showTagSuggestions).toBe(true);

        vi.advanceTimersByTime(200);

        expect(component.showTagSuggestions).toBe(false);
        vi.useRealTimers();
      });
    });
  });

  // ===========================================================================
  // copyFromSimilar Tests
  // ===========================================================================

  describe('copyFromSimilar', () => {
    it('copies translation from similar term', () => {
      const component = wordEditFormData();
      const similarTerm = { translation: 'similar translation', id: 1, text: 'test' };

      component.copyFromSimilar(similarTerm);

      expect(mockFormStore.copyTranslationFromSimilar).toHaveBeenCalledWith('similar translation');
    });

    it('does nothing if no translation', () => {
      const component = wordEditFormData();
      const similarTerm = { translation: '', id: 1, text: 'test' };

      component.copyFromSimilar(similarTerm);

      expect(mockFormStore.copyTranslationFromSimilar).not.toHaveBeenCalled();
    });
  });

  // ===========================================================================
  // getStatusClass Tests
  // ===========================================================================

  describe('getStatusClass', () => {
    it('returns is-danger for status 1', () => {
      const component = wordEditFormData();
      expect(component.getStatusClass(1)).toBe('is-danger');
    });

    it('returns is-warning for status 2', () => {
      const component = wordEditFormData();
      expect(component.getStatusClass(2)).toBe('is-warning');
    });

    it('returns is-info for status 3', () => {
      const component = wordEditFormData();
      expect(component.getStatusClass(3)).toBe('is-info');
    });

    it('returns is-primary for status 4', () => {
      const component = wordEditFormData();
      expect(component.getStatusClass(4)).toBe('is-primary');
    });

    it('returns is-success for status 5', () => {
      const component = wordEditFormData();
      expect(component.getStatusClass(5)).toBe('is-success');
    });

    it('returns is-success is-light for status 99 (well known)', () => {
      // The shared store distinguishes well-known from learned; this file used
      // to give both the same class (#238).
      const component = wordEditFormData();
      expect(component.getStatusClass(99)).toBe('is-success is-light');
    });

    it('returns is-light for status 98 (ignored)', () => {
      const component = wordEditFormData();
      expect(component.getStatusClass(98)).toBe('is-light');
    });

    it('returns empty string for unknown status', () => {
      const component = wordEditFormData();
      expect(component.getStatusClass(100)).toBe('');
    });
  });

  // ===========================================================================
  // initWordEditFormAlpine Tests
  // ===========================================================================

  describe('initWordEditFormAlpine', () => {
    it('registers wordEditForm component with Alpine', () => {
      initWordEditFormAlpine();

      expect(Alpine.data).toHaveBeenCalledWith('wordEditForm', wordEditFormData);
    });
  });

  // ===========================================================================
  // Global Window Exposure Tests
  // ===========================================================================

  describe('global window exposure', () => {
    it('exposes wordEditFormData on window', () => {
      expect(typeof window.wordEditFormData).toBe('function');
    });
  });
});
