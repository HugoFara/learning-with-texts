/**
 * Feed Manager App - Alpine.js application for feed management SPA.
 *
 * This module initializes the feed manager when the appropriate container
 * element exists on the page.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.0.0
 */

import { onDomReady } from '@shared/utils/dom_ready';
import Alpine from 'alpinejs';
import { initFeedManagerStore, getFeedManagerStore } from '../stores/feed_manager_store';
import type { FeedManagerStoreState, PendingText } from '../stores/feed_manager_store';
import type { Feed, Article, Language } from '@modules/feed/api/feeds_api';
import { getStatusBadgeClass, getStatusLabel, formatAutoUpdate } from '@modules/feed/api/feeds_api';

// ============================================================================
// Component Definitions
// ============================================================================

/**
 * Feed list component data.
 */
function feedListComponent() {
  return {
    get store(): FeedManagerStoreState {
      return getFeedManagerStore();
    },

    get feeds(): Feed[] {
      return this.store.feeds;
    },

    get pagination() {
      return this.store.feedsPagination;
    },

    get isLoading(): boolean {
      return this.store.isLoading;
    },

    get selectedCount(): number {
      return this.store.selectedFeedIds.length;
    },

    get allSelected(): boolean {
      return this.feeds.length > 0 && this.store.selectedFeedIds.length === this.feeds.length;
    },

    isSelected(feedId: number): boolean {
      return this.store.selectedFeedIds.includes(feedId);
    },

    toggleSelection(feedId: number): void {
      this.store.toggleFeedSelection(feedId);
    },

    toggleAll(): void {
      this.store.toggleAllFeeds();
    },

    viewArticles(feed: Feed): void {
      this.store.showArticles(feed);
    },

    editFeed(feed: Feed): void {
      this.store.showEditForm(feed);
    },

    async deleteFeed(feed: Feed): Promise<void> {
      if (confirm(`Delete feed "${feed.name}"?`)) {
        await this.store.deleteFeed(feed.id);
      }
    },

    async loadFeed(feed: Feed): Promise<void> {
      await this.store.loadFeedContent(feed);
    },

    async loadSelected(): Promise<void> {
      await this.store.loadSelectedFeeds();
    },

    async deleteSelected(): Promise<void> {
      if (this.selectedCount === 0) return;
      if (confirm(`Delete ${this.selectedCount} selected feed(s)?`)) {
        await this.store.deleteSelectedFeeds();
      }
    },

    goToPage(page: number): void {
      this.store.goToFeedsPage(page);
    }
  };
}

/**
 * Feed filter component data.
 */
function feedFilterComponent() {
  return {
    localQuery: '',

    get store(): FeedManagerStoreState {
      return getFeedManagerStore();
    },

    get sort() {
      return this.store.sort;
    },

    init(): void {
      this.localQuery = this.store.filterQuery;
    },

    setSort(sort: string): void {
      this.store.setSort(parseInt(sort, 10));
    },

    search(): void {
      this.store.setFilterQuery(this.localQuery);
    },

    clearSearch(): void {
      this.localQuery = '';
      this.store.setFilterQuery('');
    }
  };
}

/**
 * Article list component data.
 */
function articleListComponent() {
  return {
    get store(): FeedManagerStoreState {
      return getFeedManagerStore();
    },

    get feed(): Feed | null {
      return this.store.currentFeed;
    },

    get articles(): Article[] {
      return this.store.articles;
    },

    get pagination() {
      return this.store.articlesPagination;
    },

    get isLoading(): boolean {
      return this.store.isLoadingArticles;
    },

    get selectedCount(): number {
      return this.store.selectedArticleIds.length;
    },

    get allSelected(): boolean {
      return this.articles.length > 0 &&
        this.store.selectedArticleIds.length === this.articles.length;
    },

    isSelected(articleId: number): boolean {
      return this.store.selectedArticleIds.includes(articleId);
    },

    toggleSelection(articleId: number): void {
      this.store.toggleArticleSelection(articleId);
    },

    toggleAll(): void {
      this.store.toggleAllArticles();
    },

    getStatusClass(status: Article['status']): string {
      return getStatusBadgeClass(status);
    },

    getStatusText(status: Article['status']): string {
      return getStatusLabel(status);
    },

    backToList(): void {
      this.store.showList();
    },

    async importSelected(): Promise<void> {
      await this.store.importSelectedArticles();
    },

    async deleteSelected(): Promise<void> {
      if (this.selectedCount === 0) return;
      if (confirm(`Delete ${this.selectedCount} selected article(s)?`)) {
        await this.store.deleteSelectedArticles();
      }
    },

    async deleteAll(): Promise<void> {
      if (confirm(`Delete ALL articles from "${this.feed?.name}"?`)) {
        await this.store.deleteAllArticles();
      }
    },

    async resetErrors(): Promise<void> {
      await this.store.resetErrorArticles();
    },

    truncateText(text: string, maxLength: number): string {
      if (!text) return '';
      if (text.length <= maxLength) return text;
      return text.substring(0, maxLength) + '...';
    },

    goToPage(page: number): void {
      this.store.goToArticlesPage(page);
    }
  };
}

/**
 * Review screen for feeds carrying the `edit_text` option.
 *
 * Each row is edited in place on the store's `pendingTexts`, so no field
 * names are involved — the rows are posted as JSON, not as a form.
 */
function pendingTextListComponent() {
  return {
    get store(): FeedManagerStoreState {
      return getFeedManagerStore();
    },

    get texts(): PendingText[] {
      return this.store.pendingTexts;
    },

    get languages(): Language[] {
      return this.store.languages;
    },

    get isSubmitting(): boolean {
      return this.store.isSubmitting;
    },

    get selectedCount(): number {
      return this.store.selectedPendingCount();
    },

    toggle(index: number): void {
      this.store.togglePendingText(index);
    },

    /** Alpine's CSP build has no `parseInt`, so the cast happens here. */
    setLanguage(index: number, value: string): void {
      const pending = this.store.pendingTexts[index];
      if (pending) {
        pending.language_id = parseInt(value, 10) || 0;
      }
    },

    async save(): Promise<void> {
      await this.store.savePendingTexts();
    },

    cancel(): void {
      this.store.cancelPendingTexts();
    }
  };
}

/**
 * Article filter component data.
 */
function articleFilterComponent() {
  return {
    localQuery: '',

    get store(): FeedManagerStoreState {
      return getFeedManagerStore();
    },

    get sort() {
      return this.store.articlesSort;
    },

    init(): void {
      this.localQuery = this.store.articlesQuery;
    },

    setSort(sort: string): void {
      this.store.setArticlesSort(parseInt(sort, 10));
    },

    search(): void {
      this.store.setArticlesQuery(this.localQuery);
    },

    clearSearch(): void {
      this.localQuery = '';
      this.store.setArticlesQuery('');
    }
  };
}

/**
 * Feed form component data.
 */
function feedFormComponent() {
  return {
    get store(): FeedManagerStoreState {
      return getFeedManagerStore();
    },

    get isCreate(): boolean {
      return this.store.viewMode === 'create';
    },

    get feed() {
      return this.store.editingFeed;
    },

    get languageName(): string {
      const id = Number(this.store.editingFeed?.langId ?? 0);
      const match = this.store.languages.find(lang => lang.id === id);
      return match?.name ?? this.store.currentLanguageName;
    },

    get isSubmitting(): boolean {
      return this.store.isSubmitting;
    },

    get currentFeed(): Feed | null {
      return this.store.currentFeed;
    },

    cancel(): void {
      this.store.showList();
    },

    async submit(): Promise<void> {
      if (!this.feed) return;

      const data = {
        langId: this.feed.langId || 0,
        name: this.feed.name || '',
        sourceUri: this.feed.sourceUri || '',
        articleSectionTags: this.feed.articleSectionTags || '',
        filterTags: this.feed.filterTags || '',
        options: this.feed.options || ''
      };

      if (this.isCreate) {
        await this.store.createFeed(data);
      } else if (this.currentFeed) {
        await this.store.updateFeed(this.currentFeed.id, data);
      }
    }
  };
}

/**
 * Notification component data.
 */
function notificationComponent() {
  return {
    get store(): FeedManagerStoreState {
      return getFeedManagerStore();
    },

    get notifications() {
      return this.store.notifications;
    },

    dismiss(id: string): void {
      this.store.dismissNotification(id);
    },

    getClass(type: string): string {
      switch (type) {
        case 'success':
          return 'is-success';
        case 'error':
          return 'is-danger';
        case 'warning':
          return 'is-warning';
        default:
          return 'is-info';
      }
    }
  };
}

// ============================================================================
// Initialization
// ============================================================================

/**
 * Initialize the feed manager app.
 */
export function initFeedManagerApp(): void {
  // Register Alpine components
  Alpine.data('feedList', feedListComponent);
  Alpine.data('feedFilter', feedFilterComponent);
  Alpine.data('articleList', articleListComponent);
  Alpine.data('articleFilter', articleFilterComponent);
  Alpine.data('pendingTextList', pendingTextListComponent);
  Alpine.data('feedForm', feedFormComponent);
  Alpine.data('feedNotifications', notificationComponent);

  // Initialize the store
  initFeedManagerStore();
}

/**
 * Check if we should initialize the feed manager (container exists).
 */
export function shouldInitFeedManager(): boolean {
  return document.getElementById('feed-manager-app') !== null;
}

// Auto-initialize when the container exists
onDomReady(() => {
  if (shouldInitFeedManager()) {
    initFeedManagerApp();

    // Initialize the store after Alpine starts
    document.addEventListener('alpine:init', () => {
      const store = getFeedManagerStore();
      store.init();
    });
  }
});

// Export helpers for templates
export { getStatusBadgeClass, getStatusLabel, formatAutoUpdate };
