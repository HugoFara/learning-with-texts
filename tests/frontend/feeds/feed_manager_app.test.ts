/**
 * Tests for feed_manager_app.ts - Feed manager SPA components
 */
import { describe, it, expect, beforeEach, afterEach, vi, type Mock } from 'vitest';

// Mock Alpine.js before importing the module
vi.mock('alpinejs', () => ({
  default: {
    data: vi.fn()
  }
}));

// Create a shared mock store instance
const mockStore = {
  feeds: [],
  feedsPagination: { page: 1, totalPages: 1 },
  isLoading: false,
  selectedFeedIds: [],
  currentFeed: null,
  articles: [],
  articlesPagination: { page: 1, totalPages: 1 },
  isLoadingArticles: false,
  selectedArticleIds: [],
  languages: [] as Array<{ id: number; name: string }>,
  currentLanguageId: 0,
  currentLanguageName: '',
  filterLang: '' as number | '',
  filterQuery: '',
  sort: 0,
  articlesSort: 0,
  articlesQuery: '',
  viewMode: 'list',
  editingFeed: null as { langId?: number; name?: string } | null,
  isSubmitting: false,
  notifications: [],
  toggleFeedSelection: vi.fn(),
  toggleAllFeeds: vi.fn(),
  showArticles: vi.fn(),
  showEditForm: vi.fn(),
  deleteFeed: vi.fn(),
  loadFeedContent: vi.fn(),
  loadSelectedFeeds: vi.fn(),
  deleteSelectedFeeds: vi.fn(),
  goToFeedsPage: vi.fn(),
  toggleArticleSelection: vi.fn(),
  toggleAllArticles: vi.fn(),
  showList: vi.fn(),
  importSelectedArticles: vi.fn(),
  deleteSelectedArticles: vi.fn(),
  deleteAllArticles: vi.fn(),
  resetErrorArticles: vi.fn(),
  goToArticlesPage: vi.fn(),
  setSort: vi.fn(),
  setFilterQuery: vi.fn(),
  setArticlesSort: vi.fn(),
  setArticlesQuery: vi.fn(),
  createFeed: vi.fn(),
  updateFeed: vi.fn(),
  dismissNotification: vi.fn(),
  init: vi.fn()
};

// Mock the feed_manager_store module
vi.mock('../../../src/frontend/js/modules/feed/stores/feed_manager_store', () => ({
  initFeedManagerStore: vi.fn(),
  getFeedManagerStore: vi.fn(() => mockStore)
}));

// Mock feeds_api
vi.mock('../../../src/frontend/js/modules/feed/api/feeds_api', () => ({
  getStatusBadgeClass: vi.fn((status) => `badge-${status}`),
  getStatusLabel: vi.fn((status) => `Status: ${status}`),
  formatAutoUpdate: vi.fn()
}));

import Alpine from 'alpinejs';
import { initFeedManagerApp, shouldInitFeedManager } from '../../../src/frontend/js/modules/feed/pages/feed_manager_app';
import { getStatusBadgeClass, getStatusLabel } from '../../../src/frontend/js/modules/feed/api/feeds_api';
import { initFeedManagerStore } from '../../../src/frontend/js/modules/feed/stores/feed_manager_store';

describe('feed_manager_app.ts', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
    vi.clearAllMocks();

    // Reset mock store function calls
    mockStore.toggleFeedSelection.mockClear();
    mockStore.toggleAllFeeds.mockClear();
    mockStore.showArticles.mockClear();
    mockStore.showEditForm.mockClear();
    mockStore.goToFeedsPage.mockClear();
    mockStore.toggleArticleSelection.mockClear();
    mockStore.toggleAllArticles.mockClear();
    mockStore.showList.mockClear();
    mockStore.goToArticlesPage.mockClear();
    mockStore.setSort.mockClear();
    mockStore.setFilterQuery.mockClear();
    mockStore.setArticlesSort.mockClear();
    mockStore.setArticlesQuery.mockClear();
    mockStore.dismissNotification.mockClear();
  });

  afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
  });

  // ===========================================================================
  // initFeedManagerApp Tests
  // ===========================================================================

  describe('initFeedManagerApp', () => {
    it('registers all Alpine components', () => {
      initFeedManagerApp();

      expect(Alpine.data).toHaveBeenCalledWith('feedList', expect.any(Function));
      expect(Alpine.data).toHaveBeenCalledWith('feedFilter', expect.any(Function));
      expect(Alpine.data).toHaveBeenCalledWith('articleList', expect.any(Function));
      expect(Alpine.data).toHaveBeenCalledWith('articleFilter', expect.any(Function));
      expect(Alpine.data).toHaveBeenCalledWith('feedForm', expect.any(Function));
      expect(Alpine.data).toHaveBeenCalledWith('feedNotifications', expect.any(Function));
    });

    it('initializes the store', () => {
      initFeedManagerApp();

      expect(initFeedManagerStore).toHaveBeenCalled();
    });
  });

  // ===========================================================================
  // shouldInitFeedManager Tests
  // ===========================================================================

  describe('shouldInitFeedManager', () => {
    it('returns true when feed-manager-app container exists', () => {
      document.body.innerHTML = '<div id="feed-manager-app"></div>';

      expect(shouldInitFeedManager()).toBe(true);
    });

    it('returns false when container does not exist', () => {
      document.body.innerHTML = '';

      expect(shouldInitFeedManager()).toBe(false);
    });

    it('returns false for different container id', () => {
      document.body.innerHTML = '<div id="other-app"></div>';

      expect(shouldInitFeedManager()).toBe(false);
    });
  });

  // ===========================================================================
  // feedListComponent Tests
  // ===========================================================================

  describe('feedListComponent', () => {
    let feedListComponent: ReturnType<typeof extractComponent>;

    function extractComponent(): () => unknown {
      initFeedManagerApp();
      const calls = (Alpine.data as Mock).mock.calls;
      const feedListCall = calls.find((c: unknown[]) => c[0] === 'feedList');
      return feedListCall ? feedListCall[1] : () => ({});
    }

    beforeEach(() => {
      feedListComponent = extractComponent();
    });

    it('returns store from getFeedManagerStore', () => {
      const component = feedListComponent() as { store: unknown };

      expect(component.store).toBeDefined();
    });

    it('gets feeds from store', () => {
      const component = feedListComponent() as { feeds: unknown[] };

      expect(Array.isArray(component.feeds)).toBe(true);
    });

    it('gets pagination from store', () => {
      const component = feedListComponent() as { pagination: unknown };

      expect(component.pagination).toBeDefined();
    });

    it('gets isLoading from store', () => {
      const component = feedListComponent() as { isLoading: boolean };

      expect(typeof component.isLoading).toBe('boolean');
    });

    it('computes selectedCount from selectedFeedIds', () => {
      const component = feedListComponent() as { selectedCount: number };

      expect(typeof component.selectedCount).toBe('number');
    });

    it('isSelected checks if feed is in selectedFeedIds', () => {
      const component = feedListComponent() as { isSelected: (id: number) => boolean };

      expect(typeof component.isSelected(1)).toBe('boolean');
    });

    it('toggleSelection calls store toggleFeedSelection', () => {
      const component = feedListComponent() as { toggleSelection: (id: number) => void };

      component.toggleSelection(5);

      expect(mockStore.toggleFeedSelection).toHaveBeenCalledWith(5);
    });

    it('toggleAll calls store toggleAllFeeds', () => {
      const component = feedListComponent() as { toggleAll: () => void };

      component.toggleAll();

      expect(mockStore.toggleAllFeeds).toHaveBeenCalled();
    });

    it('viewArticles calls store showArticles', () => {
      const component = feedListComponent() as { viewArticles: (feed: unknown) => void };
      const feed = { id: 1, name: 'Test Feed' };

      component.viewArticles(feed);

      expect(mockStore.showArticles).toHaveBeenCalledWith(feed);
    });

    it('editFeed calls store showEditForm', () => {
      const component = feedListComponent() as { editFeed: (feed: unknown) => void };
      const feed = { id: 1, name: 'Test Feed' };

      component.editFeed(feed);

      expect(mockStore.showEditForm).toHaveBeenCalledWith(feed);
    });

    it('goToPage calls store goToFeedsPage', () => {
      const component = feedListComponent() as { goToPage: (page: number) => void };

      component.goToPage(3);

      expect(mockStore.goToFeedsPage).toHaveBeenCalledWith(3);
    });
  });

  // ===========================================================================
  // feedFilterComponent Tests
  // ===========================================================================

  describe('feedFilterComponent', () => {
    let feedFilterComponent: ReturnType<typeof extractComponent>;

    function extractComponent(): () => unknown {
      initFeedManagerApp();
      const calls = (Alpine.data as Mock).mock.calls;
      const filterCall = calls.find((c: unknown[]) => c[0] === 'feedFilter');
      return filterCall ? filterCall[1] : () => ({});
    }

    beforeEach(() => {
      feedFilterComponent = extractComponent();
    });

    it('has localQuery state', () => {
      const component = feedFilterComponent() as { localQuery: string };

      expect(component.localQuery).toBe('');
    });

    // The filter bar carries no language control: the navbar is the one
    // that picks a language, and the store filters on it.

    it('setSort calls store setSort with parsed value', () => {
      const component = feedFilterComponent() as { setSort: (sort: string) => void };

      component.setSort('2');

      expect(mockStore.setSort).toHaveBeenCalledWith(2);
    });

    it('search calls store setFilterQuery', () => {
      const component = feedFilterComponent() as {
        localQuery: string;
        search: () => void;
      };
      component.localQuery = 'test search';

      component.search();

      expect(mockStore.setFilterQuery).toHaveBeenCalledWith('test search');
    });

    it('clearSearch resets localQuery and calls store', () => {
      const component = feedFilterComponent() as {
        localQuery: string;
        clearSearch: () => void;
      };
      component.localQuery = 'test';

      component.clearSearch();

      expect(component.localQuery).toBe('');
      expect(mockStore.setFilterQuery).toHaveBeenCalledWith('');
    });
  });

  // ===========================================================================
  // articleListComponent Tests
  // ===========================================================================

  describe('articleListComponent', () => {
    let articleListComponent: ReturnType<typeof extractComponent>;

    function extractComponent(): () => unknown {
      initFeedManagerApp();
      const calls = (Alpine.data as Mock).mock.calls;
      const articleCall = calls.find((c: unknown[]) => c[0] === 'articleList');
      return articleCall ? articleCall[1] : () => ({});
    }

    beforeEach(() => {
      articleListComponent = extractComponent();
    });

    it('gets articles from store', () => {
      const component = articleListComponent() as { articles: unknown[] };

      expect(Array.isArray(component.articles)).toBe(true);
    });

    it('isSelected checks if article is selected', () => {
      const component = articleListComponent() as { isSelected: (id: number) => boolean };

      expect(typeof component.isSelected(1)).toBe('boolean');
    });

    it('toggleSelection calls store toggleArticleSelection', () => {
      const component = articleListComponent() as { toggleSelection: (id: number) => void };

      component.toggleSelection(3);

      expect(mockStore.toggleArticleSelection).toHaveBeenCalledWith(3);
    });

    it('toggleAll calls store toggleAllArticles', () => {
      const component = articleListComponent() as { toggleAll: () => void };

      component.toggleAll();

      expect(mockStore.toggleAllArticles).toHaveBeenCalled();
    });

    it('getStatusClass uses getStatusBadgeClass', () => {
      const component = articleListComponent() as { getStatusClass: (s: string) => string };

      component.getStatusClass('pending');

      expect(getStatusBadgeClass).toHaveBeenCalledWith('pending');
    });

    it('getStatusText uses getStatusLabel', () => {
      const component = articleListComponent() as { getStatusText: (s: string) => string };

      component.getStatusText('imported');

      expect(getStatusLabel).toHaveBeenCalledWith('imported');
    });

    it('backToList calls store showList', () => {
      const component = articleListComponent() as { backToList: () => void };

      component.backToList();

      expect(mockStore.showList).toHaveBeenCalled();
    });

    it('goToPage calls store goToArticlesPage', () => {
      const component = articleListComponent() as { goToPage: (page: number) => void };

      component.goToPage(5);

      expect(mockStore.goToArticlesPage).toHaveBeenCalledWith(5);
    });
  });

  // ===========================================================================
  // articleFilterComponent Tests
  // ===========================================================================

  describe('articleFilterComponent', () => {
    let articleFilterComponent: ReturnType<typeof extractComponent>;

    function extractComponent(): () => unknown {
      initFeedManagerApp();
      const calls = (Alpine.data as Mock).mock.calls;
      const filterCall = calls.find((c: unknown[]) => c[0] === 'articleFilter');
      return filterCall ? filterCall[1] : () => ({});
    }

    beforeEach(() => {
      articleFilterComponent = extractComponent();
    });

    it('has localQuery state', () => {
      const component = articleFilterComponent() as { localQuery: string };

      expect(component.localQuery).toBe('');
    });

    it('setSort calls store setArticlesSort', () => {
      const component = articleFilterComponent() as { setSort: (s: string) => void };

      component.setSort('3');

      expect(mockStore.setArticlesSort).toHaveBeenCalledWith(3);
    });

    it('search calls store setArticlesQuery', () => {
      const component = articleFilterComponent() as {
        localQuery: string;
        search: () => void;
      };
      component.localQuery = 'article search';

      component.search();

      expect(mockStore.setArticlesQuery).toHaveBeenCalledWith('article search');
    });

    it('clearSearch resets and calls store', () => {
      const component = articleFilterComponent() as {
        localQuery: string;
        clearSearch: () => void;
      };
      component.localQuery = 'test';

      component.clearSearch();

      expect(component.localQuery).toBe('');
      expect(mockStore.setArticlesQuery).toHaveBeenCalledWith('');
    });
  });

  // ===========================================================================
  // feedFormComponent Tests
  // ===========================================================================

  describe('feedFormComponent', () => {
    let feedFormComponent: ReturnType<typeof extractComponent>;

    function extractComponent(): () => unknown {
      initFeedManagerApp();
      const calls = (Alpine.data as Mock).mock.calls;
      const formCall = calls.find((c: unknown[]) => c[0] === 'feedForm');
      return formCall ? formCall[1] : () => ({});
    }

    beforeEach(() => {
      feedFormComponent = extractComponent();
    });

    it('cancel calls store showList', () => {
      const component = feedFormComponent() as { cancel: () => void };

      component.cancel();

      expect(mockStore.showList).toHaveBeenCalled();
    });

    it('names the language of the feed being edited', () => {
      mockStore.languages = [{ id: 3, name: 'French' }];
      mockStore.editingFeed = { langId: 3, name: 'Le Monde' };
      const component = feedFormComponent() as { languageName: string };

      expect(component.languageName).toBe('French');
    });

    it('falls back to the navbar language for a feed with none yet', () => {
      mockStore.languages = [];
      mockStore.editingFeed = { langId: 0, name: '' };
      mockStore.currentLanguageName = 'German';
      const component = feedFormComponent() as { languageName: string };

      expect(component.languageName).toBe('German');
    });
  });

  // ===========================================================================
  // notificationComponent Tests
  // ===========================================================================

  describe('notificationComponent', () => {
    let notificationComponent: ReturnType<typeof extractComponent>;

    function extractComponent(): () => unknown {
      initFeedManagerApp();
      const calls = (Alpine.data as Mock).mock.calls;
      const notifCall = calls.find((c: unknown[]) => c[0] === 'feedNotifications');
      return notifCall ? notifCall[1] : () => ({});
    }

    beforeEach(() => {
      notificationComponent = extractComponent();
    });

    it('gets notifications from store', () => {
      const component = notificationComponent() as { notifications: unknown[] };

      expect(Array.isArray(component.notifications)).toBe(true);
    });

    it('dismiss calls store dismissNotification', () => {
      const component = notificationComponent() as { dismiss: (id: string) => void };

      component.dismiss('notif-123');

      expect(mockStore.dismissNotification).toHaveBeenCalledWith('notif-123');
    });

    it('getClass returns correct class for success', () => {
      const component = notificationComponent() as { getClass: (type: string) => string };

      expect(component.getClass('success')).toBe('is-success');
    });

    it('getClass returns correct class for error', () => {
      const component = notificationComponent() as { getClass: (type: string) => string };

      expect(component.getClass('error')).toBe('is-danger');
    });

    it('getClass returns correct class for warning', () => {
      const component = notificationComponent() as { getClass: (type: string) => string };

      expect(component.getClass('warning')).toBe('is-warning');
    });

    it('getClass returns is-info for unknown types', () => {
      const component = notificationComponent() as { getClass: (type: string) => string };

      expect(component.getClass('info')).toBe('is-info');
      expect(component.getClass('unknown')).toBe('is-info');
    });
  });
});
