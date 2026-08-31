/**
 * Home page Alpine.js application.
 *
 * Provides reactive state management for the home page dashboard
 * including collapsible menus, system warnings, and language selection.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.0.0
 */

import Alpine from 'alpinejs';
import { initIcons } from '@shared/icons/lucide_icons';
import { t } from '@shared/i18n/translator';
import { statusName } from '@shared/stores/statuses';
import { setLangAsync } from '@modules/language/stores/language_settings';
import type { LanguageChangedEvent, TextStats } from '@modules/language/stores/language_settings';

interface LastTextInfo {
  id: number;
  title: string;
  language_id: number;
  language_name: string;
  annotated: boolean;
  stats?: TextStats;
}

interface HomeWarningsConfig {
  phpVersion: string;
  lwtVersion: string;
  lastText: LastTextInfo | null;
  basePath: string;
  textCount: number;
  currentLanguageId: number;
  checkForUpdates?: boolean;
}

interface Warning {
  type: 'danger' | 'warning' | 'info';
  message: string;
  visible: boolean;
}

interface PHPWarning extends Warning {
  phpVersion: string;
  minVersion: string;
}

interface UpdateWarning extends Warning {
  currentVersion: string;
  latestVersion: string;
  downloadUrl: string;
}

interface LanguageNotification {
  message: string;
  visible: boolean;
}

interface HomeData {
  // Current language ID (for active tab highlighting)
  currentLanguageId: number;

  // Last text info (dynamically updated when language changes)
  lastText: LastTextInfo | null;

  // Number of texts for current language (for onboarding state)
  textCount: number;

  // Base path for URLs (e.g., '/lwt' for subdirectory installation)
  basePath: string;

  // Language change notification
  languageNotification: LanguageNotification;

  // Warnings
  warnings: {
    phpOutdated: PHPWarning;
    cookiesDisabled: Warning;
    updateAvailable: UpdateWarning;
  };

  // Methods
  init(): void;
  initWarnings(): void;
  initLanguageChangeListener(): void;
  switchLanguage(languageId: number, languageName: string): Promise<void>;
  handleLanguageChange(event: LanguageChangedEvent): void;
  checkCookies(): void;
  checkPHPVersion(version: string): void;
  checkLWTUpdate(currentVersion: string): void;
  dismissUpdateWarning(): void;
  shouldUpdate(fromVersion: string, toVersion: string): boolean | null;
  getStatPercent(key: string): number;
  getStatsTitle(): string;
}

/**
 * Alpine.js data component for the home page.
 */
export function homeData(): HomeData {
  return {
    currentLanguageId: 0,

    lastText: null,

    textCount: 0,

    basePath: '',

    languageNotification: {
      message: '',
      visible: false
    },

    warnings: {
      phpOutdated: {
        type: 'danger',
        message: '',
        visible: false,
        phpVersion: '',
        minVersion: ''
      },
      cookiesDisabled: {
        type: 'warning',
        message: '',
        visible: false
      },
      updateAvailable: {
        type: 'info',
        message: '',
        visible: false,
        currentVersion: '',
        latestVersion: '',
        downloadUrl: ''
      }
    },

    init() {
      // Initialize warnings and last text from config
      this.initWarnings();

      // Listen for language changes
      this.initLanguageChangeListener();
    },

    initWarnings() {
      const configElement = document.getElementById('home-warnings-config');
      if (!configElement) {
        return;
      }

      try {
        const config: HomeWarningsConfig = JSON.parse(configElement.textContent || '{}');

        // Load initial last text info
        this.lastText = config.lastText;

        // Load text count for onboarding state
        this.textCount = config.textCount || 0;

        // Load base path for URL generation
        this.basePath = config.basePath || '';

        // Load current language ID for tab highlighting
        this.currentLanguageId = config.currentLanguageId || 0;

        // Check all warnings
        this.checkCookies();
        this.checkPHPVersion(config.phpVersion);
        if (config.checkForUpdates) {
          this.checkLWTUpdate(config.lwtVersion);
        }
      } catch (e) {
        console.error('Failed to parse home warnings config:', e);
      }
    },

    initLanguageChangeListener() {
      // Listen for the custom language change event
      document.addEventListener('lwt:languageChanged', ((event: LanguageChangedEvent) => {
        this.handleLanguageChange(event);
      }) as EventListener);
    },

    async switchLanguage(languageId: number, languageName: string) {
      if (languageId === this.currentLanguageId) {
        return;
      }
      try {
        const response = await setLangAsync(String(languageId));
        this.currentLanguageId = languageId;

        // Dispatch the same event other components listen for
        document.dispatchEvent(new CustomEvent('lwt:languageChanged', {
          detail: {
            languageId: String(languageId),
            languageName,
            response
          }
        }));
      } catch (error) {
        console.error('Failed to change language:', error);
      }
    },

    handleLanguageChange(event: LanguageChangedEvent) {
      const { languageName, response } = event.detail;

      // Update the last text info
      if (response.last_text) {
        this.lastText = response.last_text;
      } else {
        this.lastText = null;
      }

      // Update text count for onboarding state
      this.textCount = response.text_count ?? 0;

      // Show notification
      this.languageNotification.message = `Language changed to "${languageName}"`;
      this.languageNotification.visible = true;

      // Refresh Lucide icons for the newly rendered template
      setTimeout(() => {
        initIcons();
      }, 0);

      // Auto-hide notification after 3 seconds
      setTimeout(() => {
        this.languageNotification.visible = false;
      }, 3000);
    },

    checkCookies() {
      // Test if cookies are enabled
      try {
        document.cookie = 'lwt_cookie_test=1; SameSite=Strict';
        const enabled = document.cookie.indexOf('lwt_cookie_test') !== -1;
        // Clean up test cookie
        document.cookie = 'lwt_cookie_test=; expires=Thu, 01 Jan 1970 00:00:00 GMT; SameSite=Strict';

        if (!enabled) {
          this.warnings.cookiesDisabled.message = t('home.warning_cookies_disabled');
          this.warnings.cookiesDisabled.visible = true;
        }
      } catch {
        // If we can't even try, assume cookies are disabled
        this.warnings.cookiesDisabled.message =
          'Cookies are not enabled! Please enable them for LWT to work properly.';
        this.warnings.cookiesDisabled.visible = true;
      }
    },

    checkPHPVersion(phpVersion: string) {
      const phpMinVersion = '8.0.0';
      if (this.shouldUpdate(phpVersion, phpMinVersion)) {
        this.warnings.phpOutdated.phpVersion = phpVersion;
        this.warnings.phpOutdated.minVersion = phpMinVersion;
        this.warnings.phpOutdated.message = t('home.warning_php_outdated', {
          phpVersion,
          minVersion: phpMinVersion,
        });
        this.warnings.phpOutdated.visible = true;
      }
    },

    checkLWTUpdate(lwtVersion: string) {
      fetch('https://api.github.com/repos/hugofara/lwt/releases/latest')
        .then(response => response.json())
        .then((data: { tag_name: string }) => {
          const latestVersion = data.tag_name;
          if (!this.shouldUpdate(lwtVersion, latestVersion)) {
            return;
          }
          // Respect a previously dismissed version: only re-show the banner
          // if a newer release has appeared since the user closed it.
          try {
            const dismissed = localStorage.getItem('lwt_update_dismissed_version');
            if (dismissed && !this.shouldUpdate(dismissed, latestVersion)) {
              return;
            }
          } catch {
            // localStorage unavailable — fall through and show the banner.
          }
          this.warnings.updateAvailable.currentVersion = lwtVersion;
          this.warnings.updateAvailable.latestVersion = latestVersion;
          this.warnings.updateAvailable.downloadUrl = `https://github.com/HugoFara/lwt/releases/tag/${latestVersion}`;
          this.warnings.updateAvailable.message = t('home.warning_update_available', {
            latestVersion,
            currentVersion: lwtVersion,
          });
          this.warnings.updateAvailable.visible = true;
        })
        .catch(() => {
          // Silently fail if GitHub API is unreachable
        });
    },

    dismissUpdateWarning() {
      this.warnings.updateAvailable.visible = false;
      try {
        const latest = this.warnings.updateAvailable.latestVersion;
        if (latest) {
          localStorage.setItem('lwt_update_dismissed_version', latest);
        }
      } catch {
        // localStorage unavailable — dismissal is session-only.
      }
    },

    shouldUpdate(fromVersion: string, toVersion: string): boolean | null {
      const regex = /^(\d+)\.(\d+)\.(\d+)(?:-[\w.-]+)?/;
      const match1 = fromVersion.match(regex);
      const match2 = toVersion.match(regex);

      if (!match1 || !match2) {
        return null;
      }

      for (let i = 1; i < 4; i++) {
        const level1 = parseInt(match1[i], 10);
        const level2 = parseInt(match2[i], 10);
        if (level1 < level2) {
          return true;
        } else if (level1 > level2) {
          return false;
        }
      }

      return null;
    },

    /**
     * Get the percentage for a stats key (CSP-safe, no optional chaining).
     */
    getStatPercent(key: string): number {
      if (!this.lastText || !this.lastText.stats) {
        return 0;
      }
      const stats = this.lastText.stats;
      const total = stats.total || 1;
      let value = 0;
      switch (key) {
        case 'unknown': value = stats.unknown || 0; break;
        case 's1': value = stats.s1 || 0; break;
        case 's2': value = stats.s2 || 0; break;
        case 's3': value = stats.s3 || 0; break;
        case 's4': value = stats.s4 || 0; break;
        case 's5': value = stats.s5 || 0; break;
        case 's98': value = stats.s98 || 0; break;
        case 's99': value = stats.s99 || 0; break;
      }
      return (value / total) * 100;
    },

    /**
     * Get the title string for stats tooltip (CSP-safe, no optional chaining).
     */
    getStatsTitle(): string {
      if (!this.lastText || !this.lastText.stats) {
        return '';
      }
      const stats = this.lastText.stats;
      const unknown = stats.unknown || 0;
      const s1 = stats.s1 || 0;
      const s2 = stats.s2 || 0;
      const s3 = stats.s3 || 0;
      const s4 = stats.s4 || 0;
      const s5 = stats.s5 || 0;
      const s98 = stats.s98 || 0;
      const s99 = stats.s99 || 0;
      const learning = s1 + s2 + s3 + s4;
      // Localized through the shared store: these five labels used to be
      // English string literals, so the tooltip stayed English whatever the
      // user's locale.
      return [
        statusName(0) + ': ' + unknown,
        statusName(1) + ': ' + learning,
        statusName(5) + ': ' + s5,
        statusName(99) + ': ' + s99,
        statusName(98) + ': ' + s98
      ].join(', ');
    }
  };
}

/**
 * Initialize the home page Alpine.js components.
 * This must be called before Alpine.start().
 */
export function initHomeAlpine(): void {
  // Register the home data component
  Alpine.data('homeApp', homeData);
}

// Expose for global access if needed
declare global {
  interface Window {
    Alpine: typeof Alpine;
    homeData: typeof homeData;
    initHomeAlpine: typeof initHomeAlpine;
  }
}

window.homeData = homeData;
window.initHomeAlpine = initHomeAlpine;

// Register Alpine data component immediately (before Alpine.start() in main.ts)
initHomeAlpine();
