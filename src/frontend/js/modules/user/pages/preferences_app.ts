/**
 * Preferences page — reads and writes through `/api/v1/profile/preferences`.
 *
 * Replaces the same-origin POST to `/profile/preferences` (issue #262).
 *
 * The page is a settings map rather than a set of distinct fields, so the
 * component holds one `settings` object and every input binds a key in it.
 * The option lists stay server-rendered: they are static configuration, and
 * the themes list in particular is knowledge only the server has.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.4.0
 */

import Alpine from 'alpinejs';
import { t } from '@shared/i18n/translator';
import { ProfileApi } from '../api/profile_api';

/** Alpine state for the preferences page. */
export interface PreferencesAppData {
  settings: Record<string, string>;
  loadedLocale: string | undefined;
  isLoading: boolean;
  isSaving: boolean;
  error: string;
  success: string;
  init(): Promise<void>;
  load(): Promise<void>;
  save(): Promise<void>;
  settingValue(key: string): string;
  setValue(key: string, value: string): void;
  onFieldInput(key: string, event: Event): void;
  isOn(key: string): boolean;
  toggle(key: string, event: Event): void;
}

/**
 * Build the preferences page component.
 *
 * @returns Alpine component data
 */
export function preferencesAppData(): PreferencesAppData {
  return {
    settings: {},
    loadedLocale: undefined,
    isLoading: false,
    isSaving: false,
    error: '',
    success: '',

    async init(): Promise<void> {
      await this.load();
    },

    async load(): Promise<void> {
      this.isLoading = true;
      this.error = '';

      const response = await ProfileApi.getPreferences();
      this.isLoading = false;

      if (response.error || !response.data) {
        this.error = response.error || t('user.profile.load_failed');
        return;
      }

      this.settings = response.data.settings;
      this.loadedLocale = this.settings.app_language;
    },

    async save(): Promise<void> {
      this.isSaving = true;
      this.error = '';
      this.success = '';

      const response = await ProfileApi.savePreferences(this.settings);
      this.isSaving = false;

      if (response.error || !response.data || !response.data.success) {
        const body = response.data;
        this.error = (body && body.error)
          ? body.error
          : (response.error || t('user.flash.preferences_failed'));
        return;
      }

      if (this.settings.app_language !== this.loadedLocale) {
        // The page is already rendered in the old language.
        window.location.reload();
        return;
      }

      this.success = t('user.flash.preferences_saved');
    },

    /**
     * Current value of a setting, or empty until the load resolves.
     *
     * A method rather than bracket access in the template: Alpine's CSP build
     * evaluates a restricted expression grammar, and routing every read through
     * one accessor keeps the markup to plain calls.
     *
     * Deliberately not named `valueOf` — that is an Object.prototype method,
     * and shadowing it means any coercion of the component (String(), +) calls
     * it with no argument and silently yields ''.
     */
    settingValue(key: string): string {
      return this.settings[key] ?? '';
    },

    setValue(key: string, value: string): void {
      this.settings[key] = value;
    },

    onFieldInput(key: string, event: Event): void {
      const target = event.target as HTMLInputElement | HTMLSelectElement;
      this.setValue(key, target.value);
    },

    isOn(key: string): boolean {
      const value = this.settings[key];
      return value === '1' || value === 'true';
    },

    toggle(key: string, event: Event): void {
      const target = event.target as HTMLInputElement;
      this.setValue(key, target.checked ? '1' : '0');
    }
  };
}

Alpine.data('preferencesApp', preferencesAppData);
