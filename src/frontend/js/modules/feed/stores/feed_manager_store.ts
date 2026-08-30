/**
 * Feed Manager Store - Alpine.js store for feed management SPA.
 *
 * Provides centralized state management for the feed list, articles,
 * and CRUD operations.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.0.0
 */

import Alpine from 'alpinejs';
import type {
  Feed,
  Article,
  Language,
  Pagination,
  FeedListParams,
  ArticleParams,
  FeedData,
  LoadFeedResponse,
  EditedText
} from '@modules/feed/api/feeds_api';
import * as feedsApi from '@modules/feed/api/feeds_api';

/**
 * The navbar's language, as the manager page carries it.
 */
interface ManagerConfig {
  currentLanguageId: number;
  currentLanguageName: string;
}

/**
 * Read the manager page's configuration.
 */
function readManagerConfig(): ManagerConfig {
  const configEl = document.getElementById('feed-manager-config');
  if (!configEl?.textContent) {
    return { currentLanguageId: 0, currentLanguageName: '' };
  }

  try {
    const parsed = JSON.parse(configEl.textContent) as Partial<ManagerConfig>;
    return {
      currentLanguageId: parsed.currentLanguageId ?? 0,
      currentLanguageName: parsed.currentLanguageName ?? ''
    };
  } catch {
    console.error('Failed to parse feed manager config');
    return { currentLanguageId: 0, currentLanguageName: '' };
  }
}

// ============================================================================
// Types
// ============================================================================

/**
 * View modes for the feed manager.
 */
export type ViewMode = 'list' | 'articles' | 'edit' | 'create' | 'edit-texts';

/**
 * A text being reviewed before import.
 *
 * `selected` is local to the review screen: deselected rows are simply not
 * sent, so the server never has to be told about them.
 */
export interface PendingText extends EditedText {
  selected: boolean;
}

/**
 * Notification message.
 */
export interface Notification {
  id: string;
  type: 'success' | 'error' | 'info' | 'warning';
  message: string;
  timeout?: number;
}

/**
 * Feed manager store state interface.
 */
export interface FeedManagerStoreState {
  // === View State ===
  viewMode: ViewMode;
  isLoading: boolean;
  isLoadingArticles: boolean;
  isSubmitting: boolean;

  // === Feed List ===
  feeds: Feed[];
  selectedFeedIds: number[];
  feedsPagination: Pagination;

  // === Articles ===
  currentFeed: Feed | null;
  articles: Article[];
  selectedArticleIds: number[];
  articlesPagination: Pagination;

  // === Filters ===
  filterLang: number | '';
  filterQuery: string;
  sort: number;
  articlesQuery: string;
  articlesSort: number;

  // === Languages ===
  languages: Language[];
  currentLanguageId: number;
  currentLanguageName: string;

  // === Form ===
  editingFeed: Partial<FeedData> | null;

  // === Edit-before-import ===
  pendingTexts: PendingText[];
  pendingFeedId: number;

  // === Notifications ===
  notifications: Notification[];

  // === Methods ===
  init(): Promise<void>;
  loadFeeds(params?: FeedListParams): Promise<void>;
  loadArticles(feedId: number, params?: Partial<ArticleParams>): Promise<void>;
  createFeed(data: FeedData): Promise<boolean>;
  updateFeed(feedId: number, data: Partial<FeedData>): Promise<boolean>;
  deleteFeed(feedId: number): Promise<boolean>;
  deleteSelectedFeeds(): Promise<boolean>;
  loadFeedContent(feed: Feed): Promise<LoadFeedResponse | null>;
  loadSelectedFeeds(): Promise<void>;
  importSelectedArticles(): Promise<boolean>;
  extractSelectedArticles(): Promise<boolean>;
  savePendingTexts(): Promise<boolean>;
  cancelPendingTexts(): void;
  togglePendingText(index: number): void;
  selectedPendingCount(): number;
  deleteSelectedArticles(): Promise<boolean>;
  deleteAllArticles(): Promise<boolean>;
  resetErrorArticles(): Promise<boolean>;

  // View navigation
  showList(): void;
  showArticles(feed: Feed): void;
  showEditForm(feed: Feed): void;
  showCreateForm(): void;

  // Selection
  toggleFeedSelection(feedId: number): void;
  toggleAllFeeds(): void;
  clearFeedSelection(): void;
  toggleArticleSelection(articleId: number): void;
  toggleAllArticles(): void;
  clearArticleSelection(): void;

  // Pagination
  goToFeedsPage(page: number): Promise<void>;
  goToArticlesPage(page: number): Promise<void>;

  // Filters
  setFilterQuery(query: string): Promise<void>;
  setSort(sort: number): Promise<void>;
  setArticlesQuery(query: string): Promise<void>;
  setArticlesSort(sort: number): Promise<void>;

  // Notifications
  notify(type: Notification['type'], message: string): void;
  dismissNotification(id: string): void;
}

// ============================================================================
// Store Implementation
// ============================================================================

/**
 * Generate unique ID for notifications.
 */
function generateNotificationId(): string {
  return `notif_${Date.now()}_${Math.random().toString(36).substring(2, 9)}`;
}

/**
 * Create the feed manager store data object.
 */
function createFeedManagerStore(): FeedManagerStoreState {
  return {
    // === View State ===
    viewMode: 'list',
    isLoading: false,
    isLoadingArticles: false,
    isSubmitting: false,

    // === Feed List ===
    feeds: [],
    selectedFeedIds: [],
    feedsPagination: { page: 1, per_page: 50, total: 0, total_pages: 0 },

    // === Articles ===
    currentFeed: null,
    articles: [],
    selectedArticleIds: [],
    articlesPagination: { page: 1, per_page: 50, total: 0, total_pages: 0 },

    // === Filters ===
    filterLang: '',
    filterQuery: '',
    sort: 2, // Default: update desc
    articlesQuery: '',
    articlesSort: 1, // Default: date desc

    // === Languages ===
    languages: [],
    currentLanguageId: 0,
    currentLanguageName: '',

    // === Form ===
    editingFeed: null,

    // === Edit-before-import ===
    pendingTexts: [],
    pendingFeedId: 0,

    // === Notifications ===
    notifications: [],

    // =========================================================================
    // Initialization
    // =========================================================================

    async init(): Promise<void> {
      // Feeds belong to the language the navbar is on. Showing every
      // language's feeds side by side, with a column and a filter of their
      // own repeating what the navbar already says, made the list something
      // to narrow down before it was useful.
      const config = readManagerConfig();
      this.currentLanguageId = config.currentLanguageId;
      this.currentLanguageName = config.currentLanguageName;
      this.filterLang = config.currentLanguageId || '';

      await this.loadFeeds();
    },

    // =========================================================================
    // Data Loading
    // =========================================================================

    async loadFeeds(params: FeedListParams = {}): Promise<void> {
      this.isLoading = true;

      const response = await feedsApi.getFeeds({
        lang: this.filterLang,
        query: this.filterQuery,
        page: params.page || this.feedsPagination.page,
        per_page: params.per_page || this.feedsPagination.per_page,
        sort: this.sort,
        ...params
      });

      if (response.data) {
        this.feeds = response.data.feeds;
        this.feedsPagination = response.data.pagination;
        this.languages = response.data.languages;
      } else if (response.error) {
        this.notify('error', `Failed to load feeds: ${response.error}`);
      }

      this.isLoading = false;

      // Re-initialize icons after Alpine renders the new content
      requestAnimationFrame(() => {
        document.dispatchEvent(new CustomEvent('lwt:contentLoaded'));
      });
    },

    async loadArticles(feedId: number, params: Partial<ArticleParams> = {}): Promise<void> {
      this.isLoadingArticles = true;

      const response = await feedsApi.getArticles({
        feed_id: feedId,
        query: this.articlesQuery,
        page: params.page || this.articlesPagination.page,
        per_page: params.per_page || this.articlesPagination.per_page,
        sort: this.articlesSort,
        ...params
      });

      if (response.data) {
        this.articles = response.data.articles;
        this.articlesPagination = response.data.pagination;
      } else if (response.error) {
        this.notify('error', `Failed to load articles: ${response.error}`);
      }

      this.isLoadingArticles = false;

      // Re-initialize icons after Alpine renders the new content
      requestAnimationFrame(() => {
        document.dispatchEvent(new CustomEvent('lwt:contentLoaded'));
      });
    },

    // =========================================================================
    // CRUD Operations
    // =========================================================================

    async createFeed(data: FeedData): Promise<boolean> {
      this.isSubmitting = true;

      const response = await feedsApi.createFeed(data);

      if (response.data?.success) {
        this.notify('success', 'Feed created successfully');
        await this.loadFeeds({ page: 1 });
        this.showList();
        this.isSubmitting = false;
        return true;
      } else {
        this.notify('error', response.data?.error || response.error || 'Failed to create feed');
        this.isSubmitting = false;
        return false;
      }
    },

    async updateFeed(feedId: number, data: Partial<FeedData>): Promise<boolean> {
      this.isSubmitting = true;

      const response = await feedsApi.updateFeed(feedId, data);

      if (response.data?.success) {
        this.notify('success', 'Feed updated successfully');
        await this.loadFeeds();
        this.showList();
        this.isSubmitting = false;
        return true;
      } else {
        this.notify('error', response.data?.error || response.error || 'Failed to update feed');
        this.isSubmitting = false;
        return false;
      }
    },

    async deleteFeed(feedId: number): Promise<boolean> {
      const response = await feedsApi.deleteFeed(feedId);

      if (response.data?.success) {
        this.notify('success', 'Feed deleted');
        await this.loadFeeds();
        return true;
      } else {
        this.notify('error', response.error || 'Failed to delete feed');
        return false;
      }
    },

    async deleteSelectedFeeds(): Promise<boolean> {
      if (this.selectedFeedIds.length === 0) {
        this.notify('warning', 'No feeds selected');
        return false;
      }

      // Delete one by one since bulk delete needs body
      let deleted = 0;
      for (const feedId of this.selectedFeedIds) {
        const response = await feedsApi.deleteFeed(feedId);
        if (response.data?.success) {
          deleted++;
        }
      }

      this.clearFeedSelection();
      await this.loadFeeds();
      this.notify('success', `Deleted ${deleted} feed(s)`);
      return true;
    },

    async loadFeedContent(feed: Feed): Promise<LoadFeedResponse | null> {
      const response = await feedsApi.loadFeed(
        feed.id,
        feed.name,
        feed.sourceUri,
        feed.optionsString
      );

      if (response.data) {
        if (response.data.success) {
          this.notify('success', response.data.message || 'Feed loaded');
          // Refresh feed list to update article counts
          await this.loadFeeds();
        } else if (response.data.error) {
          this.notify('error', response.data.error);
        }
        return response.data;
      } else {
        this.notify('error', response.error || 'Failed to load feed');
        return null;
      }
    },

    async loadSelectedFeeds(): Promise<void> {
      if (this.selectedFeedIds.length === 0) {
        this.notify('warning', 'No feeds selected');
        return;
      }

      this.isLoading = true;
      const selectedFeeds = this.feeds.filter(f => this.selectedFeedIds.includes(f.id));

      for (const feed of selectedFeeds) {
        await this.loadFeedContent(feed);
      }

      this.clearFeedSelection();
      this.isLoading = false;
    },

    // =========================================================================
    // Article Operations
    // =========================================================================

    async importSelectedArticles(): Promise<boolean> {
      if (this.selectedArticleIds.length === 0) {
        this.notify('warning', 'No articles selected');
        return false;
      }

      // A feed configured with edit_text wants its texts reviewed before they
      // are written, so importing straight away would ignore the setting.
      if (this.currentFeed?.options?.edit_text) {
        return await this.extractSelectedArticles();
      }

      this.isSubmitting = true;

      const response = await feedsApi.importArticles(this.selectedArticleIds);

      if (response.data?.success) {
        const msg = response.data.imported > 0
          ? `Imported ${response.data.imported} article(s)`
          : 'No articles imported';

        if (response.data.errors.length > 0) {
          this.notify('warning', `${msg}. Errors: ${response.data.errors.join(', ')}`);
        } else {
          this.notify('success', msg);
        }

        this.clearArticleSelection();
        if (this.currentFeed) {
          await this.loadArticles(this.currentFeed.id);
        }
        this.isSubmitting = false;
        return true;
      } else {
        this.notify('error', response.error || 'Failed to import articles');
        this.isSubmitting = false;
        return false;
      }
    },

    /**
     * Pull the selected articles into the review screen without writing them.
     */
    async extractSelectedArticles(): Promise<boolean> {
      if (!this.currentFeed || this.selectedArticleIds.length === 0) {
        this.notify('warning', 'No articles selected');
        return false;
      }

      this.isSubmitting = true;
      const response = await feedsApi.extractArticles(this.selectedArticleIds);
      this.isSubmitting = false;

      if (!response.data?.success) {
        this.notify('error', response.error || 'Failed to extract articles');
        return false;
      }

      const { texts, language_id: languageId, errors } = response.data;
      if (errors.length > 0) {
        this.notify('warning', `Some articles could not be read: ${errors.join(', ')}`);
      }

      if (texts.length === 0) {
        this.notify('warning', 'Nothing could be extracted from the selected articles');
        return false;
      }

      this.pendingFeedId = this.currentFeed.id;
      this.pendingTexts = texts.map(text => ({
        title: text.TxTitle,
        text: text.TxText,
        source_uri: text.TxSourceURI,
        audio_uri: text.TxAudioURI,
        // The feed's language is the default; the row picker can override it.
        language_id: languageId || this.currentFeed?.langId || 0,
        selected: true
      }));
      this.viewMode = 'edit-texts';
      return true;
    },

    /**
     * Write the reviewed texts. Deselected and blank rows are never sent.
     */
    async savePendingTexts(): Promise<boolean> {
      // `selected` is review-screen state; the endpoint takes text rows only.
      const rows: EditedText[] = this.pendingTexts
        .filter(pending => pending.selected)
        .map(pending => ({
          title: pending.title,
          text: pending.text,
          source_uri: pending.source_uri,
          audio_uri: pending.audio_uri,
          language_id: pending.language_id
        }));

      if (rows.length === 0) {
        this.notify('warning', 'No texts selected');
        return false;
      }

      const blank = rows.find(row => row.title.trim() === '' || row.text.trim() === '');
      if (blank) {
        this.notify('error', 'Every selected text needs a title and a body');
        return false;
      }

      this.isSubmitting = true;
      const response = await feedsApi.createTextsFromEdited(this.pendingFeedId, rows);
      this.isSubmitting = false;

      if (!response.data?.success) {
        this.notify('error', response.data?.error || response.error || 'Failed to save texts');
        return false;
      }

      const { created, archived, errors } = response.data;
      const summary = archived > 0
        ? `Created ${created} text(s), archived ${archived}`
        : `Created ${created} text(s)`;

      if (errors.length > 0) {
        this.notify('warning', `${summary}. ${errors.join(', ')}`);
      } else {
        this.notify('success', summary);
      }

      this.pendingTexts = [];
      this.pendingFeedId = 0;
      this.clearArticleSelection();
      this.viewMode = 'articles';
      if (this.currentFeed) {
        await this.loadArticles(this.currentFeed.id);
      }
      return true;
    },

    cancelPendingTexts(): void {
      this.pendingTexts = [];
      this.pendingFeedId = 0;
      this.viewMode = 'articles';
    },

    togglePendingText(index: number): void {
      const pending = this.pendingTexts[index];
      if (pending) {
        pending.selected = !pending.selected;
      }
    },

    selectedPendingCount(): number {
      return this.pendingTexts.filter(pending => pending.selected).length;
    },

    async deleteSelectedArticles(): Promise<boolean> {
      if (!this.currentFeed || this.selectedArticleIds.length === 0) {
        this.notify('warning', 'No articles selected');
        return false;
      }

      const response = await feedsApi.deleteArticles(
        this.currentFeed.id,
        this.selectedArticleIds
      );

      if (response.data?.success) {
        this.notify('success', `Deleted ${response.data.deleted} article(s)`);
        this.clearArticleSelection();
        await this.loadArticles(this.currentFeed.id);
        return true;
      } else {
        this.notify('error', response.error || 'Failed to delete articles');
        return false;
      }
    },

    async deleteAllArticles(): Promise<boolean> {
      if (!this.currentFeed) {
        return false;
      }

      const response = await feedsApi.deleteArticles(this.currentFeed.id);

      if (response.data?.success) {
        this.notify('success', `Deleted all articles from ${this.currentFeed.name}`);
        await this.loadArticles(this.currentFeed.id);
        return true;
      } else {
        this.notify('error', response.error || 'Failed to delete articles');
        return false;
      }
    },

    async resetErrorArticles(): Promise<boolean> {
      if (!this.currentFeed) {
        return false;
      }

      const response = await feedsApi.resetErrorArticles(this.currentFeed.id);

      if (response.data?.success) {
        this.notify('success', `Reset ${response.data.reset} error article(s)`);
        await this.loadArticles(this.currentFeed.id);
        return true;
      } else {
        this.notify('error', response.error || 'Failed to reset error articles');
        return false;
      }
    },

    // =========================================================================
    // View Navigation
    // =========================================================================

    showList(): void {
      this.viewMode = 'list';
      this.currentFeed = null;
      this.articles = [];
      this.selectedArticleIds = [];
      this.editingFeed = null;

      // Re-initialize icons after Alpine renders the view
      requestAnimationFrame(() => {
        document.dispatchEvent(new CustomEvent('lwt:contentLoaded'));
      });
    },

    showArticles(feed: Feed): void {
      this.viewMode = 'articles';
      this.currentFeed = feed;
      this.selectedArticleIds = [];
      this.articlesQuery = '';
      this.articlesSort = 1;
      this.articlesPagination = { page: 1, per_page: 50, total: 0, total_pages: 0 };
      this.loadArticles(feed.id);
    },

    showEditForm(feed: Feed): void {
      this.viewMode = 'edit';
      this.editingFeed = {
        langId: feed.langId,
        name: feed.name,
        sourceUri: feed.sourceUri,
        articleSectionTags: feed.articleSectionTags,
        filterTags: feed.filterTags,
        options: feed.optionsString
      };
      this.currentFeed = feed;
    },

    showCreateForm(): void {
      this.viewMode = 'create';
      this.editingFeed = {
        langId: this.currentLanguageId || this.languages[0]?.id || 0,
        name: '',
        sourceUri: '',
        articleSectionTags: '',
        filterTags: '',
        options: ''
      };
      this.currentFeed = null;
    },

    // =========================================================================
    // Selection
    // =========================================================================

    toggleFeedSelection(feedId: number): void {
      const index = this.selectedFeedIds.indexOf(feedId);
      if (index === -1) {
        this.selectedFeedIds = [...this.selectedFeedIds, feedId];
      } else {
        this.selectedFeedIds = this.selectedFeedIds.filter(id => id !== feedId);
      }
    },

    toggleAllFeeds(): void {
      if (this.selectedFeedIds.length === this.feeds.length) {
        this.selectedFeedIds = [];
      } else {
        this.selectedFeedIds = this.feeds.map(f => f.id);
      }
    },

    clearFeedSelection(): void {
      this.selectedFeedIds = [];
    },

    toggleArticleSelection(articleId: number): void {
      const index = this.selectedArticleIds.indexOf(articleId);
      if (index === -1) {
        this.selectedArticleIds = [...this.selectedArticleIds, articleId];
      } else {
        this.selectedArticleIds = this.selectedArticleIds.filter(id => id !== articleId);
      }
    },

    toggleAllArticles(): void {
      if (this.selectedArticleIds.length === this.articles.length) {
        this.selectedArticleIds = [];
      } else {
        this.selectedArticleIds = this.articles.map(a => a.id);
      }
    },

    clearArticleSelection(): void {
      this.selectedArticleIds = [];
    },

    // =========================================================================
    // Pagination
    // =========================================================================

    async goToFeedsPage(page: number): Promise<void> {
      this.feedsPagination.page = page;
      await this.loadFeeds({ page });
    },

    async goToArticlesPage(page: number): Promise<void> {
      if (!this.currentFeed) return;
      this.articlesPagination.page = page;
      await this.loadArticles(this.currentFeed.id, { page });
    },

    // =========================================================================
    // Filters
    // =========================================================================

    async setFilterQuery(query: string): Promise<void> {
      this.filterQuery = query;
      this.feedsPagination.page = 1;
      await this.loadFeeds();
    },

    async setSort(sort: number): Promise<void> {
      this.sort = sort;
      this.feedsPagination.page = 1;
      await this.loadFeeds();
    },

    async setArticlesQuery(query: string): Promise<void> {
      if (!this.currentFeed) return;
      this.articlesQuery = query;
      this.articlesPagination.page = 1;
      await this.loadArticles(this.currentFeed.id);
    },

    async setArticlesSort(sort: number): Promise<void> {
      if (!this.currentFeed) return;
      this.articlesSort = sort;
      this.articlesPagination.page = 1;
      await this.loadArticles(this.currentFeed.id);
    },

    // =========================================================================
    // Notifications
    // =========================================================================

    notify(type: Notification['type'], message: string): void {
      const id = generateNotificationId();
      const notification: Notification = { id, type, message };

      this.notifications = [...this.notifications, notification];

      // Auto-dismiss after timeout
      const timeout = type === 'error' ? 8000 : 5000;
      setTimeout(() => {
        this.dismissNotification(id);
      }, timeout);
    },

    dismissNotification(id: string): void {
      this.notifications = this.notifications.filter(n => n.id !== id);
    }
  };
}

// ============================================================================
// Store Registration
// ============================================================================

/**
 * Initialize the feed manager store as an Alpine.js store.
 */
export function initFeedManagerStore(): void {
  Alpine.store('feedManager', createFeedManagerStore());
}

/**
 * Get the feed manager store instance.
 */
export function getFeedManagerStore(): FeedManagerStoreState {
  return Alpine.store('feedManager') as FeedManagerStoreState;
}

// Expose for global access
declare global {
  interface Window {
    getFeedManagerStore: typeof getFeedManagerStore;
  }
}

window.getFeedManagerStore = getFeedManagerStore;
