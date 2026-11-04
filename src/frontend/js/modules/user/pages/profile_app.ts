/**
 * Profile page — reads and writes through `/api/v1/profile`.
 *
 * Replaces the two same-origin POSTs to `/profile` and `/profile/password`
 * (issue #262). Both act on the signed-in user only; there is no ID in any
 * path, so nothing here can address another account.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.4.0
 */

import Alpine from 'alpinejs';
import { t } from '@shared/i18n/translator';
import { ProfileApi } from '../api/profile_api';

/** Alpine state for the profile page. */
export interface ProfileAppData {
  username: string;
  email: string;
  emailVerified: boolean;
  showsUnverifiedNotice(): boolean;
  currentPassword: string;
  newPassword: string;
  confirmPassword: string;
  isLoading: boolean;
  isSavingProfile: boolean;
  isSavingPassword: boolean;
  profileError: string;
  profileSuccess: string;
  passwordError: string;
  passwordSuccess: string;
  init(): Promise<void>;
  load(): Promise<void>;
  saveProfile(): Promise<void>;
  savePassword(): Promise<void>;
}

/**
 * Build the profile page component.
 *
 * @returns Alpine component data
 */
export function profileAppData(): ProfileAppData {
  return {
    username: '',
    email: '',
    emailVerified: false,
    currentPassword: '',
    newPassword: '',
    confirmPassword: '',
    isLoading: false,
    isSavingProfile: false,
    isSavingPassword: false,
    profileError: '',
    profileSuccess: '',
    passwordError: '',
    passwordSuccess: '',

    async init(): Promise<void> {
      await this.load();
    },

    async load(): Promise<void> {
      this.isLoading = true;
      const response = await ProfileApi.get();
      this.isLoading = false;

      if (response.error || !response.data || !response.data.profile) {
        this.profileError = (response.data && response.data.error)
          ? response.data.error
          : (response.error || t('user.profile.load_failed'));
        return;
      }

      this.username = response.data.profile.username;
      this.email = response.data.profile.email || '';
      this.emailVerified = response.data.profile.emailVerified;
    },

    showsUnverifiedNotice(): boolean {
      return this.email !== '' && !this.emailVerified;
    },

    async saveProfile(): Promise<void> {
      this.isSavingProfile = true;
      this.profileError = '';
      this.profileSuccess = '';

      const response = await ProfileApi.update(this.username, this.email);
      this.isSavingProfile = false;

      if (response.error || !response.data || !response.data.success) {
        const body = response.data;
        this.profileError = (body && body.error)
          ? body.error
          : (response.error || t('user.profile.save_failed'));
        return;
      }

      // A changed address restarts verification, so say which happened.
      this.profileSuccess = response.data.emailChanged
        ? t('user.flash.profile_updated_verify')
        : t('user.flash.profile_updated');
      await this.load();
    },

    async savePassword(): Promise<void> {
      this.isSavingPassword = true;
      this.passwordError = '';
      this.passwordSuccess = '';

      const response = await ProfileApi.changePassword(
        this.currentPassword,
        this.newPassword,
        this.confirmPassword
      );
      this.isSavingPassword = false;

      if (response.error || !response.data || !response.data.success) {
        const body = response.data;
        this.passwordError = (body && body.error)
          ? body.error
          : (response.error || t('user.profile.save_failed'));
        return;
      }

      // Never leave the old or new password sitting in the DOM.
      this.currentPassword = '';
      this.newPassword = '';
      this.confirmPassword = '';
      this.passwordSuccess = t('user.flash.password_changed');
    }
  };
}

Alpine.data('profileApp', profileAppData);
