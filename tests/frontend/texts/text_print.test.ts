/**
 * Tests for text_print_app.ts - Alpine.js Text Print Component
 */
import { answerConfirms, settle } from '../helpers/confirm_answers';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { textPrintAppData, type TextPrintAppData } from '../../../src/frontend/js/modules/text/pages/text_print_app';

// Mock the API client
vi.mock('../../../src/frontend/js/shared/api/client', () => ({
  apiGet: vi.fn(),
  apiPost: vi.fn().mockResolvedValue({ data: { success: true } })
}));

// Mock the texts API
vi.mock('../../../src/frontend/js/modules/text/api/texts_api', () => ({
  TextsApi: {
    getPrintItems: vi.fn().mockResolvedValue({
      data: {
        items: [
          { position: 1, text: 'Hello', isWord: true, isParagraph: false, wordId: 1, status: 2, translation: 'Bonjour', romanization: '', tags: '' },
          { position: 2, text: ' ', isWord: false, isParagraph: false, wordId: null, status: null, translation: '', romanization: '', tags: '' },
          { position: 3, text: 'world', isWord: true, isParagraph: false, wordId: 2, status: 3, translation: 'monde', romanization: '', tags: 'greeting' }
        ],
        config: {
          textId: 123,
          title: 'Test Text',
          sourceUri: '',
          audioUri: '',
          langId: 1,
          textSize: 150,
          rtlScript: false,
          hasAnnotation: false,
          savedAnn: 3,
          savedStatus: 14,
          savedPlacement: 0
        }
      }
    }),
    getAnnotation: vi.fn().mockResolvedValue({
      data: {
        items: [
          { order: 0, text: 'Hello', wordId: 1, translation: 'Bonjour', isWord: true }
        ],
        config: {
          textId: 123,
          title: 'Test Text',
          sourceUri: '',
          audioUri: '',
          langId: 1,
          textSize: 150,
          rtlScript: false,
          hasAnnotation: true,
          ttsClass: 'tts_en'
        }
      }
    })
  }
}));

// Mock lucide with all required icons
vi.mock('lucide', async () => {
  const { lucideMock } = await import('../helpers/lucide_mock');
  return lucideMock;
});

describe('text_print_app.ts', () => {
  let component: TextPrintAppData;

  beforeEach(() => {
    document.body.innerHTML = '';
    vi.clearAllMocks();

    // Set up default config element
    document.body.innerHTML = `
      <script type="application/json" id="print-config">
        {"textId": 123, "mode": "plain", "savedAnn": 3, "savedStatus": 14, "savedPlacement": 0}
      </script>
    `;

    component = textPrintAppData();
  });

  afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
  });

  // ===========================================================================
  // Initialization Tests
  // ===========================================================================

  describe('initialization', () => {
    it('initializes with correct default values', () => {
      expect(component.loading).toBe(true);
      expect(component.mode).toBe('plain');
      expect(component.textId).toBe(123);
      expect(component.statusFilter).toBe(14);
      expect(component.annotationFlags).toBe(3);
      expect(component.placementMode).toBe(0);
    });

    it('reads config from script element', () => {
      document.body.innerHTML = `
        <script type="application/json" id="print-config">
          {"textId": 456, "mode": "annotated", "savedAnn": 7, "savedStatus": 31, "savedPlacement": 2}
        </script>
      `;

      const comp = textPrintAppData();

      expect(comp.textId).toBe(456);
      expect(comp.mode).toBe('annotated');
      expect(comp.statusFilter).toBe(31);
      expect(comp.annotationFlags).toBe(7);
      expect(comp.placementMode).toBe(2);
    });

    it('handles missing config element gracefully', () => {
      document.body.innerHTML = '';

      expect(() => textPrintAppData()).not.toThrow();
    });

    it('loads print items on init for plain mode', async () => {
      const { TextsApi } = await import('../../../src/frontend/js/modules/text/api/texts_api');

      await component.init();

      expect(TextsApi.getPrintItems).toHaveBeenCalledWith(123);
      expect(component.loading).toBe(false);
      expect(component.items.length).toBeGreaterThan(0);
    });

    it('loads annotation on init for annotated mode', async () => {
      document.body.innerHTML = `
        <script type="application/json" id="print-config">
          {"textId": 123, "mode": "annotated"}
        </script>
      `;

      const comp = textPrintAppData();
      const { TextsApi } = await import('../../../src/frontend/js/modules/text/api/texts_api');

      await comp.init();

      expect(TextsApi.getAnnotation).toHaveBeenCalledWith(123);
      expect(comp.loading).toBe(false);
    });
  });

  // ===========================================================================
  // Computed Properties Tests
  // ===========================================================================

  describe('computed properties', () => {
    it('showRom returns true when bit 2 is set', () => {
      component.annotationFlags = 2; // Only romanization
      expect(component.showRom).toBe(true);

      component.annotationFlags = 3; // Both
      expect(component.showRom).toBe(true);

      component.annotationFlags = 1; // Only translation
      expect(component.showRom).toBe(false);
    });

    it('showTrans returns true when bit 1 is set', () => {
      component.annotationFlags = 1; // Only translation
      expect(component.showTrans).toBe(true);

      component.annotationFlags = 3; // Both
      expect(component.showTrans).toBe(true);

      component.annotationFlags = 2; // Only romanization
      expect(component.showTrans).toBe(false);
    });

    it('showTags returns true when bit 4 is set', () => {
      component.annotationFlags = 4; // Only tags
      expect(component.showTags).toBe(true);

      component.annotationFlags = 7; // All
      expect(component.showTags).toBe(true);

      component.annotationFlags = 3; // No tags
      expect(component.showTags).toBe(false);
    });
  });

  // ===========================================================================
  // Status Range Tests
  // ===========================================================================

  describe('checkStatusInRange', () => {
    it('returns false for null status', () => {
      expect(component.checkStatusInRange(null)).toBe(false);
    });

    it('checks status 1-5 against bitmask', () => {
      component.statusFilter = 14; // 0b1110 = statuses 2, 3, 4

      expect(component.checkStatusInRange(1)).toBe(false);
      expect(component.checkStatusInRange(2)).toBe(true);
      expect(component.checkStatusInRange(3)).toBe(true);
      expect(component.checkStatusInRange(4)).toBe(true);
      expect(component.checkStatusInRange(5)).toBe(false);
    });

    it('checks status 98 (ignored) against bit 5', () => {
      component.statusFilter = 32; // Bit 5 = status 98
      expect(component.checkStatusInRange(98)).toBe(true);

      component.statusFilter = 14; // No bit 5
      expect(component.checkStatusInRange(98)).toBe(false);
    });

    it('checks status 99 (well-known) against bit 6', () => {
      component.statusFilter = 64; // Bit 6 = status 99
      expect(component.checkStatusInRange(99)).toBe(true);

      component.statusFilter = 14; // No bit 6
      expect(component.checkStatusInRange(99)).toBe(false);
    });
  });

  // ===========================================================================
  // Format Item Tests
  // ===========================================================================

  describe('formatItem', () => {
    beforeEach(async () => {
      await component.init();
    });

    it('returns paragraph break for isParagraph items', () => {
      const item = {
        position: 1,
        text: '¶',
        isWord: false,
        isParagraph: true,
        wordId: null,
        status: null,
        translation: '',
        romanization: '',
        tags: ''
      };

      const result = component.formatItem(item);
      expect(result).toContain('</p><p');
    });

    it('returns escaped text for non-word items', () => {
      const item = {
        position: 1,
        text: ', ',
        isWord: false,
        isParagraph: false,
        wordId: null,
        status: null,
        translation: '',
        romanization: '',
        tags: ''
      };

      const result = component.formatItem(item);
      expect(result).toBe(', ');
    });

    it('returns plain text for words without matching status', () => {
      component.statusFilter = 1; // Only status 1

      const item = {
        position: 1,
        text: 'Hello',
        isWord: true,
        isParagraph: false,
        wordId: 1,
        status: 2, // Not in filter
        translation: 'Bonjour',
        romanization: '',
        tags: ''
      };

      const result = component.formatItem(item);
      expect(result).toBe('Hello');
    });

    it('returns annotated text for words with matching status', () => {
      component.statusFilter = 2; // Status 2
      component.annotationFlags = 1; // Show translation
      component.placementMode = 0; // Behind

      const item = {
        position: 1,
        text: 'Hello',
        isWord: true,
        isParagraph: false,
        wordId: 1,
        status: 2,
        translation: 'Bonjour',
        romanization: '',
        tags: ''
      };

      const result = component.formatItem(item);
      expect(result).toContain('annterm');
      expect(result).toContain('Hello');
      expect(result).toContain('anntrans');
      expect(result).toContain('Bonjour');
    });

    it('formats with ruby placement when placementMode is 2', () => {
      component.statusFilter = 2;
      component.annotationFlags = 1;
      component.placementMode = 2; // Ruby

      const item = {
        position: 1,
        text: 'Hello',
        isWord: true,
        isParagraph: false,
        wordId: 1,
        status: 2,
        translation: 'Bonjour',
        romanization: '',
        tags: ''
      };

      const result = component.formatItem(item);
      expect(result).toContain('<ruby>');
      expect(result).toContain('</ruby>');
    });
  });

  // ===========================================================================
  // Filter Handler Tests
  // ===========================================================================

  describe('filter handlers', () => {
    it('handleStatusChange updates statusFilter', () => {
      const event = { target: { value: '31' } } as unknown as Event;

      component.handleStatusChange(event);

      expect(component.statusFilter).toBe(31);
    });

    it('handleAnnotationChange updates annotationFlags', () => {
      const event = { target: { value: '7' } } as unknown as Event;

      component.handleAnnotationChange(event);

      expect(component.annotationFlags).toBe(7);
    });

    it('handlePlacementChange updates placementMode', () => {
      const event = { target: { value: '2' } } as unknown as Event;

      component.handlePlacementChange(event);

      expect(component.placementMode).toBe(2);
    });
  });

  // ===========================================================================
  // Action Tests
  // ===========================================================================

  describe('actions', () => {
    it('handlePrint calls window.print', () => {
      const printSpy = vi.spyOn(window, 'print').mockImplementation(() => {});

      component.handlePrint();

      expect(printSpy).toHaveBeenCalled();
    });

    it('navigateTo sets window.location.href', () => {
      // Can't actually test navigation, but check it doesn't throw
      expect(() => component.navigateTo('/texts')).not.toThrow();
    });

    it('confirmNavigateTo shows confirm dialog', async () => {
      const confirmSpy = answerConfirms(false);

      component.confirmNavigateTo('/delete', 'Are you sure?');

      await settle();
      expect(confirmSpy).toHaveBeenCalledWith('Are you sure?');
    });

    it('openWindow calls window.open', () => {
      const openSpy = vi.spyOn(window, 'open').mockImplementation(() => null);

      component.openWindow('/help');

      expect(openSpy).toHaveBeenCalledWith('/help');
    });
  });

  // ===========================================================================
  // Format Annotation Item Tests
  // ===========================================================================

  describe('formatAnnotationItem', () => {
    beforeEach(async () => {
      document.body.innerHTML = `
        <script type="application/json" id="print-config">
          {"textId": 123, "mode": "annotated"}
        </script>
      `;
      component = textPrintAppData();
      await component.init();
    });

    it('returns paragraph break for paragraph markers', () => {
      const item = {
        order: -1,
        text: '¶',
        wordId: null,
        translation: '',
        isWord: false
      };

      const result = component.formatAnnotationItem(item);
      expect(result).toContain('</p><p');
    });

    it('returns escaped text for non-word items', () => {
      const item = {
        order: -1,
        text: ', ',
        wordId: null,
        translation: '',
        isWord: false
      };

      const result = component.formatAnnotationItem(item);
      expect(result).toContain(', ');
    });

    it('returns ruby formatted text for word items', () => {
      const item = {
        order: 0,
        text: 'Hello',
        wordId: 1,
        translation: 'Bonjour',
        isWord: true
      };

      const result = component.formatAnnotationItem(item);
      expect(result).toContain('<ruby>');
      expect(result).toContain('Hello');
      expect(result).toContain('Bonjour');
    });
  });

  // ===========================================================================
  // Window Export Tests
  // ===========================================================================

  describe('window exports', () => {
    it('exports textPrintAppData to window', async () => {
      await import('../../../src/frontend/js/modules/text/pages/text_print_app');

      expect(typeof window.textPrintAppData).toBe('function');
    });
  });
});
