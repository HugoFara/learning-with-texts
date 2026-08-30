/**
 * Tests for feeds/stores/feed_manager_store.ts - Feed manager Alpine.js store
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

// Mock Alpine.js before importing the store
vi.mock('alpinejs', () => {
  const stores: Record<string, unknown> = {};
  return {
    default: {
      store: vi.fn((name: string, data?: unknown) => {
        if (data !== undefined) {
          stores[name] = data;
        }
        return stores[name];
      })
    }
  };
});

// Mock feeds_api
const mockGetFeeds = vi.fn();
const mockGetFeed = vi.fn();
const mockCreateFeed = vi.fn();
const mockUpdateFeed = vi.fn();
const mockDeleteFeed = vi.fn();
const mockLoadFeed = vi.fn();
const mockGetArticles = vi.fn();
const mockDeleteArticles = vi.fn();
const mockImportArticles = vi.fn();
const mockExtractArticles = vi.fn();
const mockCreateTextsFromEdited = vi.fn();
const mockResetErrorArticles = vi.fn();

vi.mock('../../../../src/frontend/js/modules/feed/api/feeds_api', () => ({
  getFeeds: (...args: unknown[]) => mockGetFeeds(...args),
  getFeed: (...args: unknown[]) => mockGetFeed(...args),
  createFeed: (...args: unknown[]) => mockCreateFeed(...args),
  updateFeed: (...args: unknown[]) => mockUpdateFeed(...args),
  deleteFeed: (...args: unknown[]) => mockDeleteFeed(...args),
  loadFeed: (...args: unknown[]) => mockLoadFeed(...args),
  getArticles: (...args: unknown[]) => mockGetArticles(...args),
  deleteArticles: (...args: unknown[]) => mockDeleteArticles(...args),
  importArticles: (...args: unknown[]) => mockImportArticles(...args),
  extractArticles: (...args: unknown[]) => mockExtractArticles(...args),
  createTextsFromEdited: (...args: unknown[]) => mockCreateTextsFromEdited(...args),
  resetErrorArticles: (...args: unknown[]) => mockResetErrorArticles(...args)
}));

import Alpine from 'alpinejs';
import { initFeedManagerStore, getFeedManagerStore, type FeedManagerStoreState } from '../../../../src/frontend/js/modules/feed/stores/feed_manager_store';
import type { Feed, Article } from '../../../../src/frontend/js/modules/feed/api/feeds_api';

describe('feeds/stores/feed_manager_store.ts', () => {
  let store: FeedManagerStoreState;

  const mockFeed: Feed = {
    id: 1,
    name: 'Test Feed',
    sourceUri: 'https://example.com/feed.xml',
    langId: 1,
    langName: 'English',
    articleSectionTags: '//item',
    filterTags: '',
    options: {},
    optionsString: '',
    updateTimestamp: 1234567890,
    lastUpdate: '2025-01-01',
    articleCount: 10
  };

  const mockArticle: Article = {
    id: 1,
    title: 'Test Article',
    link: 'https://example.com/article',
    description: 'Test description',
    date: '2025-01-01',
    audio: '',
    hasText: false,
    status: 'new',
    textId: null,
    archivedTextId: null
  };

  beforeEach(() => {
    vi.clearAllMocks();
    vi.useFakeTimers();
    initFeedManagerStore();
    store = getFeedManagerStore();
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
  });

  // ===========================================================================
  // Store Initialization Tests
  // ===========================================================================

  describe('Store initialization', () => {
    it('registers store with Alpine', () => {
      expect(Alpine.store).toHaveBeenCalledWith('feedManager', expect.any(Object));
    });

    it('initializes with default values', () => {
      expect(store.viewMode).toBe('list');
      expect(store.isLoading).toBe(false);
      expect(store.isLoadingArticles).toBe(false);
      expect(store.isSubmitting).toBe(false);
      expect(store.feeds).toEqual([]);
      expect(store.selectedFeedIds).toEqual([]);
      expect(store.articles).toEqual([]);
      expect(store.selectedArticleIds).toEqual([]);
      expect(store.currentFeed).toBeNull();
      expect(store.editingFeed).toBeNull();
      expect(store.notifications).toEqual([]);
    });

    it('initializes pagination with defaults', () => {
      expect(store.feedsPagination).toEqual({
        page: 1,
        per_page: 50,
        total: 0,
        total_pages: 0
      });
      expect(store.articlesPagination).toEqual({
        page: 1,
        per_page: 50,
        total: 0,
        total_pages: 0
      });
    });

    it('initializes filters with defaults', () => {
      expect(store.filterLang).toBe('');
      expect(store.filterQuery).toBe('');
      expect(store.sort).toBe(2);
      expect(store.articlesQuery).toBe('');
      expect(store.articlesSort).toBe(1);
    });
  });

  // ===========================================================================
  // init Tests
  // ===========================================================================

  describe('init', () => {
    it('calls loadFeeds on initialization', async () => {
      mockGetFeeds.mockResolvedValue({
        data: {
          feeds: [mockFeed],
          pagination: { page: 1, per_page: 50, total: 1, total_pages: 1 },
          languages: [{ id: 1, name: 'English' }]
        }
      });

      await store.init();

      expect(mockGetFeeds).toHaveBeenCalled();
    });

    it('narrows the list to the language the navbar is on', async () => {
      document.body.innerHTML =
        '<script type="application/json" id="feed-manager-config">' +
        '{"currentLanguageId":4,"currentLanguageName":"French"}</script>';
      mockGetFeeds.mockResolvedValue({
        data: {
          feeds: [],
          pagination: { page: 1, per_page: 50, total: 0, total_pages: 0 },
          languages: []
        }
      });

      await store.init();

      expect(store.filterLang).toBe(4);
      expect(store.currentLanguageName).toBe('French');
      expect(mockGetFeeds).toHaveBeenCalledWith(expect.objectContaining({ lang: 4 }));
    });

    it('falls back to every language when no language is current', async () => {
      document.body.innerHTML = '';
      mockGetFeeds.mockResolvedValue({
        data: {
          feeds: [],
          pagination: { page: 1, per_page: 50, total: 0, total_pages: 0 },
          languages: []
        }
      });

      await store.init();

      expect(store.filterLang).toBe('');
    });
  });

  describe('showCreateForm', () => {
    it('starts a new feed in the language the navbar is on', async () => {
      document.body.innerHTML =
        '<script type="application/json" id="feed-manager-config">' +
        '{"currentLanguageId":4,"currentLanguageName":"French"}</script>';
      mockGetFeeds.mockResolvedValue({
        data: {
          feeds: [],
          pagination: { page: 1, per_page: 50, total: 0, total_pages: 0 },
          languages: [{ id: 9, name: 'German' }]
        }
      });
      await store.init();

      store.showCreateForm();

      expect(store.editingFeed?.langId).toBe(4);
    });
  });

  // ===========================================================================
  // loadFeeds Tests
  // ===========================================================================

  describe('loadFeeds', () => {
    it('sets isLoading while fetching', async () => {
      mockGetFeeds.mockResolvedValue({
        data: {
          feeds: [],
          pagination: { page: 1, per_page: 50, total: 0, total_pages: 0 },
          languages: []
        }
      });

      const promise = store.loadFeeds();
      expect(store.isLoading).toBe(true);

      await promise;
      expect(store.isLoading).toBe(false);
    });

    it('updates feeds from response', async () => {
      mockGetFeeds.mockResolvedValue({
        data: {
          feeds: [mockFeed],
          pagination: { page: 1, per_page: 50, total: 1, total_pages: 1 },
          languages: [{ id: 1, name: 'English' }]
        }
      });

      await store.loadFeeds();

      expect(store.feeds).toHaveLength(1);
      expect(store.feeds[0].name).toBe('Test Feed');
    });

    it('updates pagination from response', async () => {
      mockGetFeeds.mockResolvedValue({
        data: {
          feeds: [mockFeed],
          pagination: { page: 2, per_page: 25, total: 100, total_pages: 4 },
          languages: []
        }
      });

      await store.loadFeeds();

      expect(store.feedsPagination).toEqual({
        page: 2,
        per_page: 25,
        total: 100,
        total_pages: 4
      });
    });

    it('updates languages from response', async () => {
      mockGetFeeds.mockResolvedValue({
        data: {
          feeds: [],
          pagination: { page: 1, per_page: 50, total: 0, total_pages: 0 },
          languages: [{ id: 1, name: 'English' }, { id: 2, name: 'French' }]
        }
      });

      await store.loadFeeds();

      expect(store.languages).toHaveLength(2);
    });

    it('passes filter parameters', async () => {
      mockGetFeeds.mockResolvedValue({
        data: {
          feeds: [],
          pagination: { page: 1, per_page: 50, total: 0, total_pages: 0 },
          languages: []
        }
      });

      store.filterLang = 1;
      store.filterQuery = 'test';
      store.sort = 3;

      await store.loadFeeds();

      expect(mockGetFeeds).toHaveBeenCalledWith(expect.objectContaining({
        lang: 1,
        query: 'test',
        sort: 3
      }));
    });

    it('notifies on error', async () => {
      mockGetFeeds.mockResolvedValue({ error: 'Network error' });

      await store.loadFeeds();

      expect(store.notifications).toHaveLength(1);
      expect(store.notifications[0].type).toBe('error');
    });
  });

  // ===========================================================================
  // loadArticles Tests
  // ===========================================================================

  describe('loadArticles', () => {
    it('sets isLoadingArticles while fetching', async () => {
      mockGetArticles.mockResolvedValue({
        data: {
          articles: [],
          pagination: { page: 1, per_page: 50, total: 0, total_pages: 0 },
          feed: { id: 1, name: 'Test', langId: 1 }
        }
      });

      const promise = store.loadArticles(1);
      expect(store.isLoadingArticles).toBe(true);

      await promise;
      expect(store.isLoadingArticles).toBe(false);
    });

    it('updates articles from response', async () => {
      mockGetArticles.mockResolvedValue({
        data: {
          articles: [mockArticle],
          pagination: { page: 1, per_page: 50, total: 1, total_pages: 1 },
          feed: { id: 1, name: 'Test', langId: 1 }
        }
      });

      await store.loadArticles(1);

      expect(store.articles).toHaveLength(1);
      expect(store.articles[0].title).toBe('Test Article');
    });

    it('notifies on error', async () => {
      mockGetArticles.mockResolvedValue({ error: 'Failed to load articles' });

      await store.loadArticles(1);

      expect(store.notifications).toHaveLength(1);
      expect(store.notifications[0].type).toBe('error');
    });
  });

  // ===========================================================================
  // createFeed Tests
  // ===========================================================================

  describe('createFeed', () => {
    it('sets isSubmitting while creating', async () => {
      mockCreateFeed.mockResolvedValue({ data: { success: true, feed: mockFeed } });
      mockGetFeeds.mockResolvedValue({
        data: {
          feeds: [],
          pagination: { page: 1, per_page: 50, total: 0, total_pages: 0 },
          languages: []
        }
      });

      const promise = store.createFeed({ langId: 1, name: 'New', sourceUri: 'https://test.com' });
      expect(store.isSubmitting).toBe(true);

      await promise;
      expect(store.isSubmitting).toBe(false);
    });

    it('notifies on success', async () => {
      mockCreateFeed.mockResolvedValue({ data: { success: true, feed: mockFeed } });
      mockGetFeeds.mockResolvedValue({
        data: {
          feeds: [],
          pagination: { page: 1, per_page: 50, total: 0, total_pages: 0 },
          languages: []
        }
      });

      await store.createFeed({ langId: 1, name: 'New', sourceUri: 'https://test.com' });

      expect(store.notifications).toHaveLength(1);
      expect(store.notifications[0].type).toBe('success');
    });

    it('returns true on success', async () => {
      mockCreateFeed.mockResolvedValue({ data: { success: true, feed: mockFeed } });
      mockGetFeeds.mockResolvedValue({
        data: {
          feeds: [],
          pagination: { page: 1, per_page: 50, total: 0, total_pages: 0 },
          languages: []
        }
      });

      const result = await store.createFeed({ langId: 1, name: 'New', sourceUri: 'https://test.com' });

      expect(result).toBe(true);
    });

    it('returns false on error', async () => {
      mockCreateFeed.mockResolvedValue({ error: 'Failed to create' });

      const result = await store.createFeed({ langId: 1, name: 'New', sourceUri: 'https://test.com' });

      expect(result).toBe(false);
    });

    it('shows list view after creation', async () => {
      mockCreateFeed.mockResolvedValue({ data: { success: true, feed: mockFeed } });
      mockGetFeeds.mockResolvedValue({
        data: {
          feeds: [],
          pagination: { page: 1, per_page: 50, total: 0, total_pages: 0 },
          languages: []
        }
      });

      store.viewMode = 'create';
      await store.createFeed({ langId: 1, name: 'New', sourceUri: 'https://test.com' });

      expect(store.viewMode).toBe('list');
    });
  });

  // ===========================================================================
  // updateFeed Tests
  // ===========================================================================

  describe('updateFeed', () => {
    it('calls API with feed ID and data', async () => {
      mockUpdateFeed.mockResolvedValue({ data: { success: true, feed: mockFeed } });
      mockGetFeeds.mockResolvedValue({
        data: {
          feeds: [],
          pagination: { page: 1, per_page: 50, total: 0, total_pages: 0 },
          languages: []
        }
      });

      await store.updateFeed(1, { name: 'Updated Name' });

      expect(mockUpdateFeed).toHaveBeenCalledWith(1, { name: 'Updated Name' });
    });

    it('returns true on success', async () => {
      mockUpdateFeed.mockResolvedValue({ data: { success: true, feed: mockFeed } });
      mockGetFeeds.mockResolvedValue({
        data: {
          feeds: [],
          pagination: { page: 1, per_page: 50, total: 0, total_pages: 0 },
          languages: []
        }
      });

      const result = await store.updateFeed(1, { name: 'Updated' });

      expect(result).toBe(true);
    });

    it('returns false on error', async () => {
      mockUpdateFeed.mockResolvedValue({ error: 'Failed to update' });

      const result = await store.updateFeed(1, { name: 'Updated' });

      expect(result).toBe(false);
    });
  });

  // ===========================================================================
  // deleteFeed Tests
  // ===========================================================================

  describe('deleteFeed', () => {
    it('calls API with feed ID', async () => {
      mockDeleteFeed.mockResolvedValue({ data: { success: true, deleted: 1 } });
      mockGetFeeds.mockResolvedValue({
        data: {
          feeds: [],
          pagination: { page: 1, per_page: 50, total: 0, total_pages: 0 },
          languages: []
        }
      });

      await store.deleteFeed(5);

      expect(mockDeleteFeed).toHaveBeenCalledWith(5);
    });

    it('reloads feeds after deletion', async () => {
      mockDeleteFeed.mockResolvedValue({ data: { success: true, deleted: 1 } });
      mockGetFeeds.mockResolvedValue({
        data: {
          feeds: [],
          pagination: { page: 1, per_page: 50, total: 0, total_pages: 0 },
          languages: []
        }
      });

      await store.deleteFeed(5);

      expect(mockGetFeeds).toHaveBeenCalled();
    });

    it('returns false on error', async () => {
      mockDeleteFeed.mockResolvedValue({ error: 'Failed to delete' });

      const result = await store.deleteFeed(5);

      expect(result).toBe(false);
    });
  });

  // ===========================================================================
  // deleteSelectedFeeds Tests
  // ===========================================================================

  describe('deleteSelectedFeeds', () => {
    it('returns false when no feeds selected', async () => {
      store.selectedFeedIds = [];

      const result = await store.deleteSelectedFeeds();

      expect(result).toBe(false);
      expect(store.notifications[0].type).toBe('warning');
    });

    it('deletes each selected feed', async () => {
      store.selectedFeedIds = [1, 2, 3];
      mockDeleteFeed.mockResolvedValue({ data: { success: true, deleted: 1 } });
      mockGetFeeds.mockResolvedValue({
        data: {
          feeds: [],
          pagination: { page: 1, per_page: 50, total: 0, total_pages: 0 },
          languages: []
        }
      });

      await store.deleteSelectedFeeds();

      expect(mockDeleteFeed).toHaveBeenCalledTimes(3);
    });

    it('clears selection after deletion', async () => {
      store.selectedFeedIds = [1, 2];
      mockDeleteFeed.mockResolvedValue({ data: { success: true, deleted: 1 } });
      mockGetFeeds.mockResolvedValue({
        data: {
          feeds: [],
          pagination: { page: 1, per_page: 50, total: 0, total_pages: 0 },
          languages: []
        }
      });

      await store.deleteSelectedFeeds();

      expect(store.selectedFeedIds).toEqual([]);
    });
  });

  // ===========================================================================
  // loadFeedContent Tests
  // ===========================================================================

  describe('loadFeedContent', () => {
    it('calls API with feed data', async () => {
      mockLoadFeed.mockResolvedValue({
        data: { success: true, message: 'Loaded', imported: 5, duplicates: 0 }
      });
      mockGetFeeds.mockResolvedValue({
        data: {
          feeds: [],
          pagination: { page: 1, per_page: 50, total: 0, total_pages: 0 },
          languages: []
        }
      });

      await store.loadFeedContent(mockFeed);

      expect(mockLoadFeed).toHaveBeenCalledWith(
        mockFeed.id,
        mockFeed.name,
        mockFeed.sourceUri,
        mockFeed.optionsString
      );
    });

    it('notifies on success', async () => {
      mockLoadFeed.mockResolvedValue({
        data: { success: true, message: 'Loaded 10 articles', imported: 10, duplicates: 0 }
      });
      mockGetFeeds.mockResolvedValue({
        data: {
          feeds: [],
          pagination: { page: 1, per_page: 50, total: 0, total_pages: 0 },
          languages: []
        }
      });

      await store.loadFeedContent(mockFeed);

      expect(store.notifications[0].type).toBe('success');
    });

    it('notifies on error in response', async () => {
      mockLoadFeed.mockResolvedValue({
        data: { success: false, error: 'Invalid feed URL' }
      });

      await store.loadFeedContent(mockFeed);

      expect(store.notifications[0].type).toBe('error');
    });

    it('returns null on API error', async () => {
      mockLoadFeed.mockResolvedValue({ error: 'Network error' });

      const result = await store.loadFeedContent(mockFeed);

      expect(result).toBeNull();
    });
  });

  // ===========================================================================
  // importSelectedArticles Tests
  // ===========================================================================

  describe('importSelectedArticles', () => {
    it('returns false when no articles selected', async () => {
      store.selectedArticleIds = [];

      const result = await store.importSelectedArticles();

      expect(result).toBe(false);
    });

    it('calls API with selected article IDs', async () => {
      store.selectedArticleIds = [1, 2, 3];
      store.currentFeed = mockFeed;
      mockImportArticles.mockResolvedValue({
        data: { success: true, imported: 3, errors: [] }
      });
      mockGetArticles.mockResolvedValue({
        data: {
          articles: [],
          pagination: { page: 1, per_page: 50, total: 0, total_pages: 0 },
          feed: { id: 1, name: 'Test', langId: 1 }
        }
      });

      await store.importSelectedArticles();

      expect(mockImportArticles).toHaveBeenCalledWith([1, 2, 3]);
    });

    it('clears selection after import', async () => {
      store.selectedArticleIds = [1, 2];
      store.currentFeed = mockFeed;
      mockImportArticles.mockResolvedValue({
        data: { success: true, imported: 2, errors: [] }
      });
      mockGetArticles.mockResolvedValue({
        data: {
          articles: [],
          pagination: { page: 1, per_page: 50, total: 0, total_pages: 0 },
          feed: { id: 1, name: 'Test', langId: 1 }
        }
      });

      await store.importSelectedArticles();

      expect(store.selectedArticleIds).toEqual([]);
    });
  });

  // ===========================================================================
  // Edit-before-import Tests
  //
  // Feeds carrying the edit_text option must never write straight through:
  // importSelectedArticles has to divert into the review screen instead.
  // ===========================================================================

  describe('edit-before-import', () => {
    const editFeed: Feed = { ...mockFeed, options: { edit_text: '1' } };

    const extracted = {
      success: true,
      texts: [
        {
          TxTitle: 'Article 1',
          TxText: 'Body 1',
          TxAudioURI: '',
          TxSourceURI: 'https://example.com/1'
        }
      ],
      language_id: 3,
      errors: []
    };

    it('diverts to extract instead of importing when the feed wants editing', async () => {
      store.currentFeed = editFeed;
      store.selectedArticleIds = [1];
      mockExtractArticles.mockResolvedValue({ data: extracted });

      await store.importSelectedArticles();

      expect(mockImportArticles).not.toHaveBeenCalled();
      expect(mockExtractArticles).toHaveBeenCalledWith([1]);
      expect(store.viewMode).toBe('edit-texts');
      expect(store.pendingTexts).toHaveLength(1);
      expect(store.pendingTexts[0].title).toBe('Article 1');
      expect(store.pendingTexts[0].language_id).toBe(3);
      expect(store.pendingTexts[0].selected).toBe(true);
    });

    it('imports directly when the feed does not want editing', async () => {
      store.currentFeed = mockFeed;
      store.selectedArticleIds = [1];
      mockImportArticles.mockResolvedValue({
        data: { success: true, imported: 1, errors: [] }
      });
      mockGetArticles.mockResolvedValue({
        data: {
          articles: [],
          pagination: { page: 1, per_page: 50, total: 0, total_pages: 0 },
          feed: { id: 1, name: 'Test', langId: 1 }
        }
      });

      await store.importSelectedArticles();

      expect(mockExtractArticles).not.toHaveBeenCalled();
      expect(mockImportArticles).toHaveBeenCalledWith([1]);
    });

    it('stays put when nothing could be extracted', async () => {
      store.currentFeed = editFeed;
      store.selectedArticleIds = [1];
      mockExtractArticles.mockResolvedValue({
        data: { success: true, texts: [], language_id: 3, errors: ['boom'] }
      });

      const result = await store.importSelectedArticles();

      expect(result).toBe(false);
      expect(store.viewMode).not.toBe('edit-texts');
    });

    it('sends only the selected rows, without the selected flag', async () => {
      store.currentFeed = editFeed;
      store.pendingFeedId = 7;
      store.pendingTexts = [
        {
          title: 'Keep', text: 'Body', source_uri: 's', audio_uri: 'a',
          language_id: 3, selected: true
        },
        {
          title: 'Drop', text: 'Body', source_uri: '', audio_uri: '',
          language_id: 3, selected: false
        }
      ];
      mockCreateTextsFromEdited.mockResolvedValue({
        data: { success: true, created: 1, archived: 0, errors: [] }
      });
      mockGetArticles.mockResolvedValue({
        data: {
          articles: [],
          pagination: { page: 1, per_page: 50, total: 0, total_pages: 0 },
          feed: { id: 1, name: 'Test', langId: 1 }
        }
      });

      await store.savePendingTexts();

      expect(mockCreateTextsFromEdited).toHaveBeenCalledWith(7, [
        {
          title: 'Keep',
          text: 'Body',
          source_uri: 's',
          audio_uri: 'a',
          language_id: 3
        }
      ]);
      expect(store.viewMode).toBe('articles');
      expect(store.pendingTexts).toEqual([]);
    });

    it('refuses to save a selected row with a blank title or body', async () => {
      store.pendingFeedId = 7;
      store.pendingTexts = [
        {
          title: '   ', text: 'Body', source_uri: '', audio_uri: '',
          language_id: 3, selected: true
        }
      ];

      const result = await store.savePendingTexts();

      expect(result).toBe(false);
      expect(mockCreateTextsFromEdited).not.toHaveBeenCalled();
    });

    it('refuses to save when every row is deselected', async () => {
      store.pendingFeedId = 7;
      store.pendingTexts = [
        {
          title: 'T', text: 'Body', source_uri: '', audio_uri: '',
          language_id: 3, selected: false
        }
      ];

      const result = await store.savePendingTexts();

      expect(result).toBe(false);
      expect(mockCreateTextsFromEdited).not.toHaveBeenCalled();
    });

    it('drops the pending rows on cancel', () => {
      store.pendingTexts = [
        {
          title: 'T', text: 'Body', source_uri: '', audio_uri: '',
          language_id: 3, selected: true
        }
      ];
      store.viewMode = 'edit-texts';

      store.cancelPendingTexts();

      expect(store.pendingTexts).toEqual([]);
      expect(store.viewMode).toBe('articles');
    });

    it('toggles a row and counts the selected ones', () => {
      store.pendingTexts = [
        {
          title: 'A', text: 'B', source_uri: '', audio_uri: '',
          language_id: 1, selected: true
        },
        {
          title: 'C', text: 'D', source_uri: '', audio_uri: '',
          language_id: 1, selected: true
        }
      ];

      expect(store.selectedPendingCount()).toBe(2);
      store.togglePendingText(0);
      expect(store.selectedPendingCount()).toBe(1);
    });
  });

  // ===========================================================================
  // deleteSelectedArticles Tests
  // ===========================================================================

  describe('deleteSelectedArticles', () => {
    it('returns false when no articles selected', async () => {
      store.selectedArticleIds = [];
      store.currentFeed = mockFeed;

      const result = await store.deleteSelectedArticles();

      expect(result).toBe(false);
    });

    it('returns false when no current feed', async () => {
      store.selectedArticleIds = [1, 2];
      store.currentFeed = null;

      const result = await store.deleteSelectedArticles();

      expect(result).toBe(false);
    });

    it('calls API and reloads articles on success', async () => {
      store.selectedArticleIds = [1, 2];
      store.currentFeed = mockFeed;
      mockDeleteArticles.mockResolvedValue({ data: { success: true, deleted: 2 } });
      mockGetArticles.mockResolvedValue({
        data: {
          articles: [],
          pagination: { page: 1, per_page: 50, total: 0, total_pages: 0 },
          feed: { id: 1, name: 'Test', langId: 1 }
        }
      });

      await store.deleteSelectedArticles();

      expect(mockDeleteArticles).toHaveBeenCalled();
      expect(mockGetArticles).toHaveBeenCalled();
    });
  });

  // ===========================================================================
  // deleteAllArticles Tests
  // ===========================================================================

  describe('deleteAllArticles', () => {
    it('returns false when no current feed', async () => {
      store.currentFeed = null;

      const result = await store.deleteAllArticles();

      expect(result).toBe(false);
    });

    it('deletes all articles for current feed', async () => {
      store.currentFeed = mockFeed;
      mockDeleteArticles.mockResolvedValue({ data: { success: true, deleted: 10 } });
      mockGetArticles.mockResolvedValue({
        data: {
          articles: [],
          pagination: { page: 1, per_page: 50, total: 0, total_pages: 0 },
          feed: { id: 1, name: 'Test', langId: 1 }
        }
      });

      await store.deleteAllArticles();

      expect(mockDeleteArticles).toHaveBeenCalledWith(mockFeed.id);
    });
  });

  // ===========================================================================
  // resetErrorArticles Tests
  // ===========================================================================

  describe('resetErrorArticles', () => {
    it('returns false when no current feed', async () => {
      store.currentFeed = null;

      const result = await store.resetErrorArticles();

      expect(result).toBe(false);
    });

    it('resets error articles for current feed', async () => {
      store.currentFeed = mockFeed;
      mockResetErrorArticles.mockResolvedValue({ data: { success: true, reset: 3 } });
      mockGetArticles.mockResolvedValue({
        data: {
          articles: [],
          pagination: { page: 1, per_page: 50, total: 0, total_pages: 0 },
          feed: { id: 1, name: 'Test', langId: 1 }
        }
      });

      await store.resetErrorArticles();

      expect(mockResetErrorArticles).toHaveBeenCalledWith(mockFeed.id);
    });
  });

  // ===========================================================================
  // View Navigation Tests
  // ===========================================================================

  describe('showList', () => {
    it('sets viewMode to list', () => {
      store.viewMode = 'articles';

      store.showList();

      expect(store.viewMode).toBe('list');
    });

    it('clears current feed and articles', () => {
      store.currentFeed = mockFeed;
      store.articles = [mockArticle];
      store.selectedArticleIds = [1];
      store.editingFeed = { name: 'Test' };

      store.showList();

      expect(store.currentFeed).toBeNull();
      expect(store.articles).toEqual([]);
      expect(store.selectedArticleIds).toEqual([]);
      expect(store.editingFeed).toBeNull();
    });
  });

  describe('showArticles', () => {
    beforeEach(() => {
      mockGetArticles.mockResolvedValue({
        data: {
          articles: [],
          pagination: { page: 1, per_page: 50, total: 0, total_pages: 0 },
          feed: { id: 1, name: 'Test', langId: 1 }
        }
      });
    });

    it('sets viewMode to articles', () => {
      store.showArticles(mockFeed);

      expect(store.viewMode).toBe('articles');
    });

    it('sets current feed', () => {
      store.showArticles(mockFeed);

      expect(store.currentFeed).toBe(mockFeed);
    });

    it('resets article filters', () => {
      store.articlesQuery = 'old query';
      store.articlesSort = 5;

      store.showArticles(mockFeed);

      expect(store.articlesQuery).toBe('');
      expect(store.articlesSort).toBe(1);
    });

    it('loads articles for feed', () => {
      store.showArticles(mockFeed);

      expect(mockGetArticles).toHaveBeenCalled();
    });
  });

  describe('showEditForm', () => {
    it('sets viewMode to edit', () => {
      store.showEditForm(mockFeed);

      expect(store.viewMode).toBe('edit');
    });

    it('populates editingFeed from feed', () => {
      store.showEditForm(mockFeed);

      expect(store.editingFeed).toEqual({
        langId: mockFeed.langId,
        name: mockFeed.name,
        sourceUri: mockFeed.sourceUri,
        articleSectionTags: mockFeed.articleSectionTags,
        filterTags: mockFeed.filterTags,
        options: mockFeed.optionsString
      });
    });

    it('sets currentFeed', () => {
      store.showEditForm(mockFeed);

      expect(store.currentFeed).toBe(mockFeed);
    });
  });

  describe('showCreateForm', () => {
    it('sets viewMode to create', () => {
      store.showCreateForm();

      expect(store.viewMode).toBe('create');
    });

    it('initializes empty editingFeed', () => {
      store.languages = [{ id: 5, name: 'German' }];

      store.showCreateForm();

      expect(store.editingFeed).toEqual({
        langId: 5,
        name: '',
        sourceUri: '',
        articleSectionTags: '',
        filterTags: '',
        options: ''
      });
    });

    it('clears currentFeed', () => {
      store.currentFeed = mockFeed;

      store.showCreateForm();

      expect(store.currentFeed).toBeNull();
    });
  });

  // ===========================================================================
  // Selection Tests
  // ===========================================================================

  describe('toggleFeedSelection', () => {
    it('adds feed ID if not selected', () => {
      store.selectedFeedIds = [1, 2];

      store.toggleFeedSelection(3);

      expect(store.selectedFeedIds).toContain(3);
    });

    it('removes feed ID if already selected', () => {
      store.selectedFeedIds = [1, 2, 3];

      store.toggleFeedSelection(2);

      expect(store.selectedFeedIds).not.toContain(2);
    });
  });

  describe('toggleAllFeeds', () => {
    it('selects all feeds when none selected', () => {
      store.feeds = [mockFeed, { ...mockFeed, id: 2 }];
      store.selectedFeedIds = [];

      store.toggleAllFeeds();

      expect(store.selectedFeedIds).toEqual([1, 2]);
    });

    it('deselects all when all selected', () => {
      store.feeds = [mockFeed, { ...mockFeed, id: 2 }];
      store.selectedFeedIds = [1, 2];

      store.toggleAllFeeds();

      expect(store.selectedFeedIds).toEqual([]);
    });
  });

  describe('clearFeedSelection', () => {
    it('clears selected feed IDs', () => {
      store.selectedFeedIds = [1, 2, 3];

      store.clearFeedSelection();

      expect(store.selectedFeedIds).toEqual([]);
    });
  });

  describe('toggleArticleSelection', () => {
    it('adds article ID if not selected', () => {
      store.selectedArticleIds = [1];

      store.toggleArticleSelection(2);

      expect(store.selectedArticleIds).toContain(2);
    });

    it('removes article ID if already selected', () => {
      store.selectedArticleIds = [1, 2];

      store.toggleArticleSelection(1);

      expect(store.selectedArticleIds).not.toContain(1);
    });
  });

  describe('toggleAllArticles', () => {
    it('selects all articles when none selected', () => {
      store.articles = [mockArticle, { ...mockArticle, id: 2 }];
      store.selectedArticleIds = [];

      store.toggleAllArticles();

      expect(store.selectedArticleIds).toEqual([1, 2]);
    });

    it('deselects all when all selected', () => {
      store.articles = [mockArticle, { ...mockArticle, id: 2 }];
      store.selectedArticleIds = [1, 2];

      store.toggleAllArticles();

      expect(store.selectedArticleIds).toEqual([]);
    });
  });

  // ===========================================================================
  // Pagination Tests
  // ===========================================================================

  describe('goToFeedsPage', () => {
    it('updates pagination page and reloads', async () => {
      mockGetFeeds.mockResolvedValue({
        data: {
          feeds: [],
          pagination: { page: 3, per_page: 50, total: 150, total_pages: 3 },
          languages: []
        }
      });

      await store.goToFeedsPage(3);

      expect(store.feedsPagination.page).toBe(3);
      expect(mockGetFeeds).toHaveBeenCalledWith(expect.objectContaining({ page: 3 }));
    });
  });

  describe('goToArticlesPage', () => {
    it('does nothing when no current feed', async () => {
      store.currentFeed = null;

      await store.goToArticlesPage(2);

      expect(mockGetArticles).not.toHaveBeenCalled();
    });

    it('updates pagination and reloads articles', async () => {
      store.currentFeed = mockFeed;
      mockGetArticles.mockResolvedValue({
        data: {
          articles: [],
          pagination: { page: 2, per_page: 50, total: 100, total_pages: 2 },
          feed: { id: 1, name: 'Test', langId: 1 }
        }
      });

      await store.goToArticlesPage(2);

      expect(store.articlesPagination.page).toBe(2);
      expect(mockGetArticles).toHaveBeenCalledWith(expect.objectContaining({ page: 2 }));
    });
  });

  // ===========================================================================
  // Filter Tests
  // ===========================================================================

  describe('setFilterQuery', () => {
    it('sets filter query and reloads', async () => {
      mockGetFeeds.mockResolvedValue({
        data: {
          feeds: [],
          pagination: { page: 1, per_page: 50, total: 0, total_pages: 0 },
          languages: []
        }
      });

      await store.setFilterQuery('search term');

      expect(store.filterQuery).toBe('search term');
      expect(mockGetFeeds).toHaveBeenCalled();
    });
  });

  describe('setSort', () => {
    it('sets sort and reloads', async () => {
      mockGetFeeds.mockResolvedValue({
        data: {
          feeds: [],
          pagination: { page: 1, per_page: 50, total: 0, total_pages: 0 },
          languages: []
        }
      });

      await store.setSort(4);

      expect(store.sort).toBe(4);
      expect(mockGetFeeds).toHaveBeenCalled();
    });
  });

  describe('setArticlesQuery', () => {
    it('does nothing when no current feed', async () => {
      store.currentFeed = null;

      await store.setArticlesQuery('test');

      expect(mockGetArticles).not.toHaveBeenCalled();
    });

    it('sets query and reloads articles', async () => {
      store.currentFeed = mockFeed;
      mockGetArticles.mockResolvedValue({
        data: {
          articles: [],
          pagination: { page: 1, per_page: 50, total: 0, total_pages: 0 },
          feed: { id: 1, name: 'Test', langId: 1 }
        }
      });

      await store.setArticlesQuery('search');

      expect(store.articlesQuery).toBe('search');
      expect(mockGetArticles).toHaveBeenCalled();
    });
  });

  describe('setArticlesSort', () => {
    it('does nothing when no current feed', async () => {
      store.currentFeed = null;

      await store.setArticlesSort(3);

      expect(mockGetArticles).not.toHaveBeenCalled();
    });

    it('sets sort and reloads articles', async () => {
      store.currentFeed = mockFeed;
      mockGetArticles.mockResolvedValue({
        data: {
          articles: [],
          pagination: { page: 1, per_page: 50, total: 0, total_pages: 0 },
          feed: { id: 1, name: 'Test', langId: 1 }
        }
      });

      await store.setArticlesSort(3);

      expect(store.articlesSort).toBe(3);
      expect(mockGetArticles).toHaveBeenCalled();
    });
  });

  // ===========================================================================
  // Notification Tests
  // ===========================================================================

  describe('notify', () => {
    it('adds notification with unique ID', () => {
      store.notify('success', 'Test message');

      expect(store.notifications).toHaveLength(1);
      expect(store.notifications[0].id).toMatch(/^notif_/);
      expect(store.notifications[0].type).toBe('success');
      expect(store.notifications[0].message).toBe('Test message');
    });

    it('auto-dismisses after timeout', () => {
      store.notify('info', 'Will dismiss');

      expect(store.notifications).toHaveLength(1);

      vi.advanceTimersByTime(5000);

      expect(store.notifications).toHaveLength(0);
    });

    it('uses longer timeout for errors', () => {
      store.notify('error', 'Error message');

      vi.advanceTimersByTime(5000);
      expect(store.notifications).toHaveLength(1);

      vi.advanceTimersByTime(3000);
      expect(store.notifications).toHaveLength(0);
    });
  });

  describe('dismissNotification', () => {
    it('removes notification by ID', () => {
      store.notify('success', 'Message 1');
      store.notify('info', 'Message 2');
      const id = store.notifications[0].id;

      store.dismissNotification(id);

      expect(store.notifications).toHaveLength(1);
      expect(store.notifications[0].message).toBe('Message 2');
    });

    it('does nothing for unknown ID', () => {
      store.notify('success', 'Message');

      store.dismissNotification('unknown_id');

      expect(store.notifications).toHaveLength(1);
    });
  });
});
