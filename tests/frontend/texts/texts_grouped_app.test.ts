/**
 * Tests for texts_grouped_app.ts - Texts list Alpine component (single language)
 */
import { dismissAlerts, settle } from '../helpers/confirm_answers';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

// Mock Alpine.js
vi.mock('alpinejs', () => ({
  default: {
    data: vi.fn()
  }
}));

// Mock lucide icons
vi.mock('../../../src/frontend/js/shared/icons/lucide_icons', () => ({
  initIcons: vi.fn()
}));

// Mock API client
vi.mock('../../../src/frontend/js/shared/api/client', () => ({
  apiGet: vi.fn(),
  getCsrfToken: vi.fn(() => 'test-csrf-token')
}));

// Mock TextsApi
vi.mock('../../../src/frontend/js/modules/text/api/texts_api', () => ({
  TextsApi: {
    getStatistics: vi.fn().mockResolvedValue({ data: {} }),
    bulkAction: vi.fn().mockResolvedValue({ data: { count: 1 } })
  }
}));

// Mock ui_utilities
vi.mock('../../../src/frontend/js/shared/utils/ui_utilities', () => ({
  confirmDelete: vi.fn(() => false)
}));

import Alpine from 'alpinejs';
import { textsGroupedData, initTextsGroupedAlpine } from '../../../src/frontend/js/modules/text/pages/texts_grouped_app';
import { apiGet } from '../../../src/frontend/js/shared/api/client';
import { TextsApi } from '../../../src/frontend/js/modules/text/api/texts_api';
import { confirmDelete } from '../../../src/frontend/js/shared/utils/ui_utilities';

describe('texts_grouped_app.ts', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
    vi.clearAllMocks();
    localStorage.clear();
    vi.useFakeTimers();

    // Default mock responses
    (apiGet as ReturnType<typeof vi.fn>).mockResolvedValue({
      data: { texts: [], pagination: { current_page: 1, per_page: 10, total: 0, total_pages: 0 } }
    });
  });

  afterEach(() => {
    vi.restoreAllMocks();
    vi.useRealTimers();
    document.body.innerHTML = '';
    localStorage.clear();
  });

  // ===========================================================================
  // textsGroupedData Factory Tests
  // ===========================================================================

  describe('textsGroupedData', () => {
    it('creates component with default values', () => {
      const component = textsGroupedData();

      expect(component.loading).toBe(true);
      expect(component.loadingMore).toBe(false);
      expect(component.texts).toEqual([]);
      expect(component.sort).toBe(1);
    });

    it('reads activeLanguageId from config', () => {
      document.body.innerHTML = `
        <script id="texts-grouped-config" type="application/json">
          {"activeLanguageId": 5}
        </script>
      `;

      const component = textsGroupedData();

      expect(component.activeLanguageId).toBe(5);
    });

    it('handles missing config gracefully', () => {
      const component = textsGroupedData();

      expect(component.activeLanguageId).toBe(0);
    });
  });

  // ===========================================================================
  // init Tests
  // ===========================================================================

  describe('init', () => {
    it('loads texts when activeLanguageId is set', async () => {
      document.body.innerHTML = `
        <script id="texts-grouped-config" type="application/json">
          {"activeLanguageId": 1}
        </script>
      `;

      (apiGet as ReturnType<typeof vi.fn>).mockResolvedValue({
        data: {
          texts: [{ id: 1, title: 'Test' }],
          pagination: { current_page: 1, per_page: 10, total: 1, total_pages: 1 }
        }
      });

      const component = textsGroupedData();
      await component.init();
      await vi.runAllTimersAsync();

      expect(apiGet).toHaveBeenCalledWith('/texts/by-language/1', expect.any(Object));
      expect(component.loading).toBe(false);
    });

    it('does not load texts when no language selected', async () => {
      const component = textsGroupedData();
      await component.init();

      expect(apiGet).not.toHaveBeenCalled();
      expect(component.loading).toBe(false);
    });
  });

  // ===========================================================================
  // loadTexts Tests
  // ===========================================================================

  describe('loadTexts', () => {
    it('loads texts for the active language', async () => {
      (apiGet as ReturnType<typeof vi.fn>).mockResolvedValue({
        data: {
          texts: [
            { id: 1, title: 'Text 1', has_audio: false, source_uri: '', has_source: false, annotated: false, taglist: '' },
            { id: 2, title: 'Text 2', has_audio: true, source_uri: '', has_source: false, annotated: true, taglist: 'tag1' }
          ],
          pagination: { current_page: 1, per_page: 10, total: 2, total_pages: 1 }
        }
      });

      const component = textsGroupedData();
      component.activeLanguageId = 1;

      await component.loadTexts();
      await vi.runAllTimersAsync();

      expect(component.texts).toHaveLength(2);
      expect(component.texts[0].title).toBe('Text 1');
    });

    it('appends texts when loading additional pages', async () => {
      const component = textsGroupedData();
      component.activeLanguageId = 1;
      component.texts = [{ id: 1, title: 'Text 1' }] as never[];
      component.pagination = { current_page: 1, per_page: 10, total: 15, total_pages: 2 };

      (apiGet as ReturnType<typeof vi.fn>).mockResolvedValue({
        data: {
          texts: [{ id: 2, title: 'Text 2' }],
          pagination: { current_page: 2, per_page: 10, total: 15, total_pages: 2 }
        }
      });

      await component.loadTexts(2);
      await vi.runAllTimersAsync();

      expect(component.texts).toHaveLength(2);
    });

    it('sets loadingMore state during additional page load', async () => {
      let resolvePromise: (value: unknown) => void;
      (apiGet as ReturnType<typeof vi.fn>).mockImplementation(() =>
        new Promise(resolve => { resolvePromise = resolve; })
      );

      const component = textsGroupedData();
      component.activeLanguageId = 1;

      const loadPromise = component.loadTexts(2);

      expect(component.loadingMore).toBe(true);

      resolvePromise!({
        data: {
          texts: [],
          pagination: { current_page: 2, per_page: 10, total: 0, total_pages: 0 }
        }
      });

      await loadPromise;
      await vi.runAllTimersAsync();

      expect(component.loadingMore).toBe(false);
    });
  });

  // ===========================================================================
  // hasMore Tests
  // ===========================================================================

  describe('hasMore', () => {
    it('returns true when more pages available', () => {
      const component = textsGroupedData();
      component.pagination = { current_page: 1, per_page: 10, total: 25, total_pages: 3 };

      expect(component.hasMore).toBe(true);
    });

    it('returns false when on last page', () => {
      const component = textsGroupedData();
      component.pagination = { current_page: 3, per_page: 10, total: 25, total_pages: 3 };

      expect(component.hasMore).toBe(false);
    });
  });

  // ===========================================================================
  // loadMore Tests
  // ===========================================================================

  describe('loadMore', () => {
    it('loads next page of texts', async () => {
      (apiGet as ReturnType<typeof vi.fn>).mockResolvedValue({
        data: {
          texts: [{ id: 2, title: 'Text 2' }],
          pagination: { current_page: 2, per_page: 10, total: 15, total_pages: 2 }
        }
      });

      const component = textsGroupedData();
      component.activeLanguageId = 1;
      component.texts = [{ id: 1, title: 'Text 1' }] as never[];
      component.pagination = { current_page: 1, per_page: 10, total: 15, total_pages: 2 };

      await component.loadMore();

      expect(apiGet).toHaveBeenCalledWith('/texts/by-language/1', expect.objectContaining({ page: 2 }));
    });

    it('does nothing when already loading', async () => {
      const component = textsGroupedData();
      component.activeLanguageId = 1;
      component.loadingMore = true;
      component.pagination = { current_page: 1, per_page: 10, total: 20, total_pages: 2 };

      await component.loadMore();

      expect(apiGet).not.toHaveBeenCalled();
    });
  });

  // ===========================================================================
  // summaryText Tests
  // ===========================================================================

  describe('summaryText', () => {
    it('shows singular text count', () => {
      const component = textsGroupedData();
      component.pagination = { current_page: 1, per_page: 10, total: 1, total_pages: 1 };

      expect(component.summaryText).toBe('1 text');
    });

    it('shows plural text count', () => {
      const component = textsGroupedData();
      component.pagination = { current_page: 1, per_page: 10, total: 5, total_pages: 1 };

      expect(component.summaryText).toBe('5 texts');
    });
  });

  // ===========================================================================
  // Selection Methods Tests
  // ===========================================================================

  describe('selection methods', () => {
    let component: ReturnType<typeof textsGroupedData>;

    beforeEach(() => {
      component = textsGroupedData();
      component.texts = [{ id: 10 }, { id: 20 }, { id: 30 }] as never[];
    });

    describe('markAllTexts', () => {
      it('marks all texts when checked', () => {
        component.markAllTexts(true);

        expect(component.isTextMarked(10)).toBe(true);
        expect(component.isTextMarked(20)).toBe(true);
        expect(component.isTextMarked(30)).toBe(true);
      });

      it('clears marks when unchecked', () => {
        component.markAllTexts(true);
        component.markAllTexts(false);

        expect(component.isTextMarked(10)).toBe(false);
        expect(component.isTextMarked(20)).toBe(false);
      });
    });

    describe('toggleTextMark', () => {
      it('marks text via event', () => {
        const event = {
          target: { checked: true, dataset: { textId: '10' } }
        } as unknown as Event;

        component.toggleTextMark(event);

        expect(component.isTextMarked(10)).toBe(true);
      });

      it('unmarks text via event', () => {
        component.markedTexts.add(10);

        const event = {
          target: { checked: false, dataset: { textId: '10' } }
        } as unknown as Event;

        component.toggleTextMark(event);

        expect(component.isTextMarked(10)).toBe(false);
      });
    });

    describe('isTextMarked', () => {
      it('returns false for unmarked text', () => {
        expect(component.isTextMarked(10)).toBe(false);
      });

      it('returns true for marked text', () => {
        component.markedTexts.add(10);

        expect(component.isTextMarked(10)).toBe(true);
      });
    });
  });

  // ===========================================================================
  // parseTags Tests
  // ===========================================================================

  describe('parseTags', () => {
    it('parses comma-separated tags', () => {
      const component = textsGroupedData();

      expect(component.parseTags('tag1, tag2, tag3')).toEqual(['tag1', 'tag2', 'tag3']);
    });

    it('trims whitespace', () => {
      const component = textsGroupedData();

      expect(component.parseTags('  tag1  ,  tag2  ')).toEqual(['tag1', 'tag2']);
    });

    it('returns empty array for empty string', () => {
      const component = textsGroupedData();

      expect(component.parseTags('')).toEqual([]);
    });

    it('returns empty array for whitespace only', () => {
      const component = textsGroupedData();

      expect(component.parseTags('   ')).toEqual([]);
    });

    it('filters out empty tags', () => {
      const component = textsGroupedData();

      expect(component.parseTags('tag1,,tag2')).toEqual(['tag1', 'tag2']);
    });
  });

  // ===========================================================================
  // handleSortChange Tests
  // ===========================================================================

  describe('handleSortChange', () => {
    it('updates sort value and reloads', () => {
      const component = textsGroupedData();
      component.activeLanguageId = 1;
      component.texts = [{ id: 1, title: 'Original' }] as never[];
      const event = { target: { value: '3' } } as unknown as Event;

      component.handleSortChange(event);

      expect(component.sort).toBe(3);
      expect(component.texts).toEqual([]);
      expect(apiGet).toHaveBeenCalledWith('/texts/by-language/1', expect.objectContaining({ sort: 3 }));
    });

    it('defaults to 1 for invalid value', () => {
      const component = textsGroupedData();
      const event = { target: { value: 'invalid' } } as unknown as Event;

      component.handleSortChange(event);

      expect(component.sort).toBe(1);
    });
  });

  // ===========================================================================
  // Safe Stats Accessors Tests
  // ===========================================================================

  describe('safe stats accessors', () => {
    let component: ReturnType<typeof textsGroupedData>;

    beforeEach(() => {
      component = textsGroupedData();
      component.stats = new Map([[10, {
        total: 100,
        saved: 50,
        unknown: 25,
        unknownPercent: 25,
        statusCounts: {}
      }]]);
    });

    it('getStatTotal returns total', () => {
      expect(component.getStatTotal(10)).toBe('100');
    });

    it('getStatSaved returns saved', () => {
      expect(component.getStatSaved(10)).toBe('50');
    });

    it('getStatUnknown returns unknown', () => {
      expect(component.getStatUnknown(10)).toBe('25');
    });

    it('getStatUnknownPercent returns percent', () => {
      expect(component.getStatUnknownPercent(10)).toBe('25%');
    });

    it('returns dash for missing stats', () => {
      expect(component.getStatTotal(999)).toBe('-');
      expect(component.getStatSaved(999)).toBe('-');
      expect(component.getStatUnknown(999)).toBe('-');
      expect(component.getStatUnknownPercent(999)).toBe('-');
    });
  });

  // ===========================================================================
  // getStatsForText Tests
  // ===========================================================================

  describe('getStatsForText', () => {
    it('returns stats for text', () => {
      const component = textsGroupedData();
      const stats = { total: 100, saved: 50, unknown: 25, unknownPercent: 25, statusCounts: {} };
      component.stats = new Map([[10, stats]]);

      expect(component.getStatsForText(10)).toEqual(stats);
    });

    it('returns undefined for unknown text', () => {
      const component = textsGroupedData();

      expect(component.getStatsForText(999)).toBeUndefined();
    });
  });

  // ===========================================================================
  // getStatusSegments Tests
  // ===========================================================================

  describe('getStatusSegments', () => {
    it('returns segments for text with stats', () => {
      const component = textsGroupedData();
      component.stats = new Map([[10, {
        total: 100,
        saved: 70,
        unknown: 30,
        unknownPercent: 30,
        statusCounts: { '1': 20, '2': 15, '5': 35 }
      }]]);

      const segments = component.getStatusSegments(10);

      expect(segments.length).toBeGreaterThan(0);
      expect(segments.find(s => s.status === 0)?.count).toBe(30); // unknown
      expect(segments.find(s => s.status === 1)?.count).toBe(20);
    });

    it('returns empty array when no stats', () => {
      const component = textsGroupedData();

      const segments = component.getStatusSegments(10);

      expect(segments).toEqual([]);
    });

    it('returns empty array when total is zero', () => {
      const component = textsGroupedData();
      component.stats = new Map([[10, {
        total: 0,
        saved: 0,
        unknown: 0,
        unknownPercent: 0,
        statusCounts: {}
      }]]);

      const segments = component.getStatusSegments(10);

      expect(segments).toEqual([]);
    });

    it('includes correct status labels', () => {
      const component = textsGroupedData();
      component.stats = new Map([[10, {
        total: 100,
        saved: 0,
        unknown: 50,
        unknownPercent: 50,
        statusCounts: { '99': 30, '98': 20 }
      }]]);

      const segments = component.getStatusSegments(10);

      const wellKnown = segments.find(s => s.status === 99);
      const ignored = segments.find(s => s.status === 98);

      expect(wellKnown?.label).toContain('Well Known');
      expect(ignored?.label).toContain('Ignored');
    });
  });

  // ===========================================================================
  // handleMultiAction Tests
  // ===========================================================================

  describe('handleMultiAction', () => {
    it('does nothing when no action selected', () => {
      const component = textsGroupedData();
      component.markedTexts = new Set([10]);

      const event = { target: { value: '' } } as unknown as Event;

      component.handleMultiAction(event);

      // Should not create form or submit
    });

    it('resets select when no items marked', () => {
      const component = textsGroupedData();

      const selectEl = { value: 'del' };
      const event = { target: selectEl } as unknown as Event;

      component.handleMultiAction(event);

      expect(selectEl.value).toBe('');
    });

    it('shows confirmation for delete action', () => {
      const component = textsGroupedData();
      component.markedTexts = new Set([10]);

      const selectEl = { value: 'del' };
      const event = { target: selectEl } as unknown as Event;

      component.handleMultiAction(event);

      expect(confirmDelete).toHaveBeenCalled();
    });

    it('resets select when delete cancelled', async () => {
      (confirmDelete as ReturnType<typeof vi.fn>).mockResolvedValue(false);

      const component = textsGroupedData();
      component.markedTexts = new Set([10]);

      const selectEl = { value: 'del' };
      const event = { target: selectEl } as unknown as Event;

      await component.handleMultiAction(event);

      expect(selectEl.value).toBe('');
    });
  });

  // ===========================================================================
  // Destructive bulk actions via the JSON API
  // ===========================================================================

  describe('bulk archive/delete via API', () => {
    const originalLocation = window.location;
    let reloadMock: ReturnType<typeof vi.fn>;

    beforeEach(() => {
      reloadMock = vi.fn();
      Object.defineProperty(window, 'location', {
        configurable: true,
        value: { ...originalLocation, reload: reloadMock }
      });
    });

    afterEach(() => {
      Object.defineProperty(window, 'location', {
        configurable: true,
        value: originalLocation
      });
    });

    it('routes archive to the JSON API (not a form POST)', () => {
      const component = textsGroupedData();
      component.markedTexts = new Set([10, 20]);

      const selectEl = { value: 'arch' };
      component.handleMultiAction({ target: selectEl } as unknown as Event);

      // bulkAction is invoked synchronously (before the first await); the
      // selection control is reset immediately.
      expect(selectEl.value).toBe('');
      expect(TextsApi.bulkAction).toHaveBeenCalledWith('archive', [10, 20]);
    });

    it('routes confirmed delete to the JSON API', async () => {
      (confirmDelete as ReturnType<typeof vi.fn>).mockResolvedValue(true);

      const component = textsGroupedData();
      component.markedTexts = new Set([7]);

      await component.handleMultiAction({ target: { value: 'del' } } as unknown as Event);

      expect(TextsApi.bulkAction).toHaveBeenCalledWith('delete', [7]);
    });

    it('does not call the API when delete is cancelled', async () => {
      (confirmDelete as ReturnType<typeof vi.fn>).mockResolvedValue(false);

      const component = textsGroupedData();
      component.markedTexts = new Set([7]);

      await component.handleMultiAction({ target: { value: 'del' } } as unknown as Event);

      expect(TextsApi.bulkAction).not.toHaveBeenCalled();
    });

    it('reloads the list after a successful bulk action', async () => {
      const component = textsGroupedData();
      await component.submitBulkApiAction('archive', [10]);
      expect(reloadMock).toHaveBeenCalled();
    });

    it('alerts and does not reload when the API returns an error', async () => {
      (TextsApi.bulkAction as ReturnType<typeof vi.fn>).mockResolvedValue({
        error: 'boom'
      });
      const alertSpy = dismissAlerts();

      const component = textsGroupedData();
      await component.submitBulkApiAction('delete', [10]);

      await settle();
      expect(alertSpy).toHaveBeenCalledWith('Action failed. Please try again.');
      expect(reloadMock).not.toHaveBeenCalled();
    });
  });

  // ===========================================================================
  // initTextsGroupedAlpine Tests
  // ===========================================================================

  describe('initTextsGroupedAlpine', () => {
    it('registers textsGroupedApp component with Alpine', () => {
      initTextsGroupedAlpine();

      expect(Alpine.data).toHaveBeenCalledWith('textsGroupedApp', textsGroupedData);
    });
  });

  // ===========================================================================
  // Global Window Exposure Tests
  // ===========================================================================

  describe('global window exposure', () => {
    it('exposes textsGroupedData on window', () => {
      expect(typeof window.textsGroupedData).toBe('function');
    });
  });
});
