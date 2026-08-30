/**
 * Tag list — renders from `GET /tags/{type}/list`.
 *
 * Replaces the server-rendered table (and its mobile card twin) plus the
 * same-origin form POST that carried the bulk actions. Both tag types share
 * this component; `type` comes from the scaffold's config blob.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.4.0
 */

import Alpine from 'alpinejs';
import { t } from '@shared/i18n/translator';
import { readPageConfig } from '@shared/utils/page_config';
import {
  TagsApi,
  type TagRecord,
  type TagPagination,
  type TagSortOption,
  type TagType
} from '../api/tags_api';

/** Config the scaffold ships. */
export interface TagListConfig {
  type: TagType;
  isTextTag: boolean;
  query: string;
  sort: number;
  page: number;
}

/** Alpine component state. */
export interface TagListAppData {
  type: TagType;
  isTextTag: boolean;
  tags: TagRecord[];
  pagination: TagPagination;
  sortOptions: TagSortOption[];
  baseUrl: string;
  query: string;
  searchInput: string;
  sort: number;
  selectedIds: number[];
  isLoading: boolean;
  isBusy: boolean;
  error: string;
  init(): Promise<void>;
  load(): Promise<void>;
  search(): Promise<void>;
  clearSearch(): Promise<void>;
  setSort(value: string): Promise<void>;
  goToPage(page: number): Promise<void>;
  toggle(id: number): void;
  markAll(): void;
  markNone(): void;
  isSelected(id: number): boolean;
  allSelected(): boolean;
  deleteOne(tag: TagRecord): Promise<void>;
  deleteSelected(): Promise<void>;
  deleteAllMatching(): Promise<void>;
  editUrl(tag: TagRecord): string;
  hasArchived(tag: TagRecord): boolean;
  archivedCount(tag: TagRecord): number;
  hasUsage(tag: TagRecord): boolean;
  hasPages(): boolean;
  isEmpty(): boolean;
}

/** Read the scaffold's config blob. */
function readConfig(): TagListConfig {
  const cfg = readPageConfig<TagListConfig>('tag-list-config', {
    type: 'term',
    isTextTag: false,
    query: '',
    sort: 1,
    page: 1
  });
  // Anything but an explicit 'text' means the term-tag list.
  return { ...cfg, type: cfg.type === 'text' ? 'text' : 'term' };
}

/**
 * Build the tag list component.
 *
 * @returns Alpine component data
 */
export function tagListAppData(): TagListAppData {
  return {
    type: 'term',
    isTextTag: false,
    tags: [],
    pagination: { page: 1, per_page: 0, total: 0, total_pages: 0 },
    sortOptions: [],
    baseUrl: '/tags',
    query: '',
    searchInput: '',
    sort: 1,
    selectedIds: [],
    isLoading: false,
    isBusy: false,
    error: '',

    async init(): Promise<void> {
      const config = readConfig();
      this.type = config.type;
      this.isTextTag = config.isTextTag;
      this.query = config.query;
      this.searchInput = config.query;
      this.sort = config.sort;
      this.pagination = { ...this.pagination, page: config.page };
      await this.load();
    },

    async load(): Promise<void> {
      this.isLoading = true;
      this.error = '';

      const response = await TagsApi.list(this.type, {
        query: this.query,
        sort: this.sort,
        page: this.pagination.page
      });
      this.isLoading = false;

      if (response.error || !response.data) {
        this.error = response.error || t('tags.errors.load_failed');
        this.tags = [];
        return;
      }

      this.tags = response.data.tags;
      this.pagination = response.data.pagination;
      this.sortOptions = response.data.sortOptions;
      this.baseUrl = response.data.baseUrl;
      // A row that scrolled off the current page can no longer be acted on.
      this.selectedIds = this.selectedIds.filter(
        id => this.tags.some(tag => tag.id === id)
      );
    },

    async search(): Promise<void> {
      // buildWhereClause() maps `*` to SQL `%` and does nothing else, so a
      // bare term would only ever match a tag named exactly that. Wrap it so
      // the box behaves like the substring search it looks like. A user who
      // types their own `*` keeps control of the pattern.
      const typed = this.searchInput.trim();
      this.query = typed === '' || typed.includes('*') ? typed : `*${typed}*`;
      this.pagination.page = 1;
      await this.load();
    },

    async clearSearch(): Promise<void> {
      this.searchInput = '';
      await this.search();
    },

    async setSort(value: string): Promise<void> {
      this.sort = parseInt(value, 10) || 1;
      await this.load();
    },

    async goToPage(page: number): Promise<void> {
      this.pagination.page = page;
      await this.load();
    },

    toggle(id: number): void {
      const at = this.selectedIds.indexOf(id);
      if (at === -1) {
        this.selectedIds.push(id);
      } else {
        this.selectedIds.splice(at, 1);
      }
    },

    markAll(): void {
      this.selectedIds = this.tags.map(tag => tag.id);
    },

    markNone(): void {
      this.selectedIds = [];
    },

    isSelected(id: number): boolean {
      return this.selectedIds.indexOf(id) !== -1;
    },

    allSelected(): boolean {
      return this.tags.length > 0 && this.selectedIds.length === this.tags.length;
    },

    async deleteOne(tag: TagRecord): Promise<void> {
      if (!confirm(t('tags.confirm_delete', { text: tag.text }))) {
        return;
      }
      this.isBusy = true;
      const response = await TagsApi.remove(this.type, tag.id);
      this.isBusy = false;

      if (response.error || !response.data || !response.data.success) {
        this.error = this.errorFrom(response);
        return;
      }
      await this.load();
    },

    async deleteSelected(): Promise<void> {
      if (this.selectedIds.length === 0) {
        return;
      }
      if (!confirm(t('tags.confirm_delete_selected', { count: this.selectedIds.length }))) {
        return;
      }
      this.isBusy = true;
      const response = await TagsApi.removeMany(this.type, this.selectedIds);
      this.isBusy = false;

      if (response.error || !response.data || !response.data.success) {
        this.error = this.errorFrom(response);
        return;
      }
      this.selectedIds = [];
      await this.load();
    },

    async deleteAllMatching(): Promise<void> {
      if (!confirm(t('tags.confirm_delete_all', { count: this.pagination.total }))) {
        return;
      }
      this.isBusy = true;
      // The filter goes to the server, not a page of IDs: "all" means every
      // match, and the page only holds one page's worth.
      const response = await TagsApi.removeAll(this.type, this.query);
      this.isBusy = false;

      if (response.error || !response.data || !response.data.success) {
        this.error = this.errorFrom(response);
        return;
      }
      this.selectedIds = [];
      this.pagination.page = 1;
      await this.load();
    },

    editUrl(tag: TagRecord): string {
      return `${this.baseUrl}/${tag.id}/edit`;
    },

    hasArchived(tag: TagRecord): boolean {
      return (tag.archivedUsageCount || 0) > 0;
    },

    archivedCount(tag: TagRecord): number {
      return tag.archivedUsageCount || 0;
    },

    hasUsage(tag: TagRecord): boolean {
      return tag.usageCount > 0;
    },

    hasPages(): boolean {
      return this.pagination.total_pages > 1;
    },

    isEmpty(): boolean {
      return !this.isLoading && this.tags.length === 0;
    },

    /** Pull the most specific message out of a mutation response. */
    errorFrom(response: { error?: string; data?: { error?: string } | null }): string {
      if (response.data && response.data.error) {
        return response.data.error;
      }
      return response.error || t('tags.errors.action_failed');
    }
  } as TagListAppData & {
    errorFrom(response: { error?: string; data?: { error?: string } | null }): string;
  };
}

Alpine.data('tagListApp', tagListAppData);
