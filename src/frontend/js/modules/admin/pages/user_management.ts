/**
 * Admin user list and user form — both render from `/api/v1/admin/users`.
 *
 * Replaces the server-rendered table and the six same-origin form POSTs it
 * carried (activate, deactivate, promote, demote, delete, search), plus the
 * create/edit form's own POST.
 *
 * Self-protection — an admin cannot demote, deactivate or delete themselves —
 * is enforced by the use cases server-side. The disabled buttons here are a
 * courtesy, not the boundary.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.4.0
 */

import { confirmAction, confirmDeletion } from '@shared/components/confirm_dialog';
import Alpine from 'alpinejs';
import { t } from '@shared/i18n/translator';
import {
  AdminUsersApi,
  type AdminUser,
  type UserPagination,
  type UserPayload
} from '../api/users_api';

/** Config the list scaffold ships. */
export interface UserListConfig {
  search: string;
  sort: string;
  dir: string;
}

/** Config the form scaffold ships. */
export interface UserFormConfig {
  isEdit: boolean;
  userId: number | null;
}

/** Alpine state for the user list. */
export interface UserManagementData {
  users: AdminUser[];
  pagination: UserPagination;
  statistics: Record<string, number>;
  currentAdminId: number;
  search: string;
  searchInput: string;
  sort: string;
  dir: string;
  isLoading: boolean;
  busyId: number;
  error: string;
  init(): Promise<void>;
  load(): Promise<void>;
  doSearch(): Promise<void>;
  clearSearch(): Promise<void>;
  sortBy(column: string): Promise<void>;
  goToPage(page: number): Promise<void>;
  toggleActive(user: AdminUser): Promise<void>;
  toggleRole(user: AdminUser): Promise<void>;
  confirmDelete(user: AdminUser): Promise<void>;
  editUrl(user: AdminUser): string;
  isSelf(user: AdminUser): boolean;
  isBusy(user: AdminUser): boolean;
  statusClass(user: AdminUser): string;
  statusLabel(user: AdminUser): string;
  roleClass(user: AdminUser): string;
  roleLabel(user: AdminUser): string;
  lastLoginLabel(user: AdminUser): string;
  hasPages(): boolean;
  isEmpty(): boolean;
  statOr(key: string): number;
}

/** Alpine state for the create/edit form. */
export interface UserFormData {
  isEdit: boolean;
  userId: number | null;
  currentAdminId: number;
  form: UserPayload;
  user: AdminUser | null;
  isLoading: boolean;
  isSaving: boolean;
  errors: string[];
  init(): Promise<void>;
  load(): Promise<void>;
  save(): Promise<void>;
  isSelf(): boolean;
  hasLinkedProviders(): boolean;
  lastLoginLabel(): string;
  passwordLabel(): string;
}

/** Read a JSON config blob by element ID. */
function readBlob(id: string): Record<string, unknown> {
  const el = document.getElementById(id);
  if (!el) {
    return {};
  }
  try {
    return JSON.parse(el.textContent || '{}');
  } catch {
    return {};
  }
}

/**
 * Build the user list component.
 *
 * @returns Alpine component data
 */
export function userManagementData(): UserManagementData {
  return {
    users: [],
    pagination: { page: 1, per_page: 20, total: 0, total_pages: 0 },
    statistics: {},
    currentAdminId: 0,
    search: '',
    searchInput: '',
    sort: 'username',
    dir: 'ASC',
    isLoading: false,
    busyId: 0,
    error: '',

    async init(): Promise<void> {
      const config = readBlob('user-list-config');
      this.search = String(config.search || '');
      this.searchInput = this.search;
      this.sort = String(config.sort || 'username');
      this.dir = String(config.dir || 'ASC');
      await this.load();
    },

    async load(): Promise<void> {
      this.isLoading = true;
      this.error = '';

      const response = await AdminUsersApi.list({
        page: this.pagination.page,
        per_page: this.pagination.per_page,
        sort: this.sort,
        dir: this.dir,
        search: this.search
      });
      this.isLoading = false;

      if (response.error || !response.data) {
        this.error = response.error || t('admin.users.errors.load_failed');
        this.users = [];
        return;
      }

      this.users = response.data.users;
      this.pagination = response.data.pagination;
      this.statistics = response.data.statistics;
      this.currentAdminId = response.data.currentAdminId;
    },

    async doSearch(): Promise<void> {
      this.search = this.searchInput;
      this.pagination.page = 1;
      await this.load();
    },

    async clearSearch(): Promise<void> {
      this.searchInput = '';
      await this.doSearch();
    },

    async sortBy(column: string): Promise<void> {
      if (this.sort === column) {
        this.dir = this.dir === 'ASC' ? 'DESC' : 'ASC';
      } else {
        this.sort = column;
        this.dir = 'ASC';
      }
      await this.load();
    },

    async goToPage(page: number): Promise<void> {
      this.pagination.page = page;
      await this.load();
    },

    async toggleActive(user: AdminUser): Promise<void> {
      this.busyId = user.id;
      const response = await AdminUsersApi.setStatus(user.id, !user.isActive);
      this.busyId = 0;

      if (response.error || !response.data || !response.data.success) {
        this.error = this.messageFrom(response);
        return;
      }
      await this.load();
    },

    async toggleRole(user: AdminUser): Promise<void> {
      const next = user.isAdmin ? 'user' : 'admin';
      if (!await confirmAction(t('admin.users.confirm_role', { username: user.username, role: next }))) {
        return;
      }
      this.busyId = user.id;
      const response = await AdminUsersApi.setRole(user.id, next);
      this.busyId = 0;

      if (response.error || !response.data || !response.data.success) {
        this.error = this.messageFrom(response);
        return;
      }
      await this.load();
    },

    async confirmDelete(user: AdminUser): Promise<void> {
      if (!await confirmDeletion(t('admin.users.confirm_delete', { username: user.username }))) {
        return;
      }
      this.busyId = user.id;
      const response = await AdminUsersApi.remove(user.id);
      this.busyId = 0;

      if (response.error || !response.data || !response.data.success) {
        this.error = this.messageFrom(response);
        return;
      }
      await this.load();
    },

    editUrl(user: AdminUser): string {
      return `/admin/users/${user.id}/edit`;
    },

    isSelf(user: AdminUser): boolean {
      return user.id === this.currentAdminId;
    },

    isBusy(user: AdminUser): boolean {
      return this.busyId === user.id;
    },

    statusClass(user: AdminUser): string {
      return user.isActive ? 'tag is-success is-light' : 'tag is-warning is-light';
    },

    statusLabel(user: AdminUser): string {
      return user.isActive
        ? t('admin.users_status_active')
        : t('admin.users_status_inactive');
    },

    roleClass(user: AdminUser): string {
      return user.isAdmin ? 'tag is-link is-light' : 'tag is-light';
    },

    roleLabel(user: AdminUser): string {
      return user.isAdmin ? t('admin.user_form_role_admin') : t('admin.user_form_role_user');
    },

    lastLoginLabel(user: AdminUser): string {
      return user.lastLogin === null ? t('admin.users_never') : user.lastLogin;
    },

    hasPages(): boolean {
      return this.pagination.total_pages > 1;
    },

    isEmpty(): boolean {
      return !this.isLoading && this.users.length === 0;
    },

    statOr(key: string): number {
      return this.statistics[key] || 0;
    },

    /** Pull the most specific message out of a mutation response. */
    messageFrom(response: { error?: string; data?: { error?: string } | null }): string {
      if (response.data && response.data.error) {
        return response.data.error;
      }
      return response.error || t('admin.users.errors.action_failed');
    }
  } as UserManagementData & {
    messageFrom(response: { error?: string; data?: { error?: string } | null }): string;
  };
}

/**
 * Build the create/edit form component.
 *
 * @returns Alpine component data
 */
export function userFormData(): UserFormData {
  return {
    isEdit: false,
    userId: null,
    currentAdminId: 0,
    form: { username: '', email: '', password: '', role: 'user', is_active: true },
    user: null,
    isLoading: false,
    isSaving: false,
    errors: [],

    async init(): Promise<void> {
      const config = readBlob('user-form-config');
      this.isEdit = Boolean(config.isEdit);
      this.userId = config.userId === null || config.userId === undefined
        ? null
        : Number(config.userId);

      if (this.isEdit && this.userId) {
        await this.load();
      }
    },

    async load(): Promise<void> {
      if (!this.userId) {
        return;
      }
      this.isLoading = true;
      this.errors = [];

      const response = await AdminUsersApi.get(this.userId);
      this.isLoading = false;

      if (response.error || !response.data || !response.data.user) {
        this.errors = [response.error || t('admin.users.flash.not_found')];
        return;
      }

      this.user = response.data.user;
      this.currentAdminId = response.data.currentAdminId || 0;
      this.form = {
        username: this.user.username,
        email: this.user.email || '',
        // Never prefilled: the API does not return it, and an empty password
        // on update means "leave it alone".
        password: '',
        role: this.user.role,
        is_active: this.user.isActive
      };
    },

    async save(): Promise<void> {
      this.isSaving = true;
      this.errors = [];

      const payload: UserPayload = { ...this.form };
      // An admin editing themselves cannot change their own role or status;
      // the server refuses it either way, so send what it already has.
      if (this.isSelf()) {
        payload.role = 'admin';
        payload.is_active = true;
      }

      const response = this.isEdit && this.userId
        ? await AdminUsersApi.update(this.userId, payload)
        : await AdminUsersApi.create(payload);

      this.isSaving = false;

      if (response.error || !response.data || !response.data.success) {
        const body = response.data;
        if (body && body.errors && body.errors.length > 0) {
          this.errors = body.errors;
        } else if (body && body.error) {
          this.errors = [body.error];
        } else {
          this.errors = [response.error || t('admin.users.errors.action_failed')];
        }
        return;
      }

      window.location.href = '/admin/users';
    },

    isSelf(): boolean {
      return this.isEdit && this.user !== null && this.user.id === this.currentAdminId;
    },

    hasLinkedProviders(): boolean {
      return this.user !== null && this.user.linkedProviders.length > 0;
    },

    lastLoginLabel(): string {
      if (this.user === null || this.user.lastLogin === null) {
        return t('admin.users_never');
      }
      return this.user.lastLogin;
    },

    passwordLabel(): string {
      if (this.user !== null && this.user.hasPassword) {
        return t('admin.user_form_yes');
      }
      return t('admin.user_form_no_oauth');
    }
  };
}

Alpine.data('userManagement', userManagementData);
Alpine.data('userForm', userFormData);
