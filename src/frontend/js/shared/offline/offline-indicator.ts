/**
 * Offline Indicator Component for LWT PWA.
 *
 * Shows connectivity status and offline storage information.
 *
 * @author  HugoFara <git@hugofara.net>
 * @license Unlicense <http://unlicense.org/>
 * @since   3.0.0
 */

import { t } from '@shared/i18n/translator';
import { confirmDeletion } from '@shared/components/confirm_dialog';
import Alpine from 'alpinejs';
import {
  isOfflineStorageAvailable,
  getOfflineSummary,
  type OfflineSummary,
} from './text-service';
import { clearOfflineData } from './db';

// =============================================================================
// TYPES
// =============================================================================

/**
 * Offline indicator component data.
 */
export interface OfflineIndicatorData {
  isOnline: boolean;
  storageSupported: boolean;
  summary: OfflineSummary | null;
  showDetails: boolean;
  isClearing: boolean;

  // Methods
  init(): void;
  destroy(): void;
  updateOnlineStatus(): void;
  loadSummary(): Promise<void>;
  clearAllOfflineData(): Promise<void>;
  formatSize(bytes: number): string;
}

// =============================================================================
// COMPONENT
// =============================================================================

/**
 * Create offline indicator Alpine.js component.
 *
 * Usage in HTML:
 * ```html
 * <div x-data="offlineIndicator">
 *   <span x-show="!isOnline" class="tag is-warning">Offline</span>
 *   <span x-show="isOnline && summary?.totalTexts > 0"
 *         x-text="summary?.totalTexts + ' texts available offline'"></span>
 * </div>
 * ```
 */
export function offlineIndicator(): OfflineIndicatorData {
  let onlineHandler: () => void;
  let offlineHandler: () => void;
  let downloadHandler: () => void;
  let removeHandler: () => void;

  return {
    isOnline: navigator.onLine,
    storageSupported: isOfflineStorageAvailable(),
    summary: null,
    showDetails: false,
    isClearing: false,

    init() {
      // Set up event listeners
      onlineHandler = () => this.updateOnlineStatus();
      offlineHandler = () => this.updateOnlineStatus();
      downloadHandler = () => this.loadSummary();
      removeHandler = () => this.loadSummary();

      window.addEventListener('online', onlineHandler);
      window.addEventListener('offline', offlineHandler);
      window.addEventListener('offline:text-downloaded', downloadHandler);
      window.addEventListener('offline:text-removed', removeHandler);

      // Load initial summary
      if (this.storageSupported) {
        this.loadSummary();
      }
    },

    destroy() {
      window.removeEventListener('online', onlineHandler);
      window.removeEventListener('offline', offlineHandler);
      window.removeEventListener('offline:text-downloaded', downloadHandler);
      window.removeEventListener('offline:text-removed', removeHandler);
    },

    updateOnlineStatus() {
      this.isOnline = navigator.onLine;

      // Dispatch custom event for other components
      window.dispatchEvent(new CustomEvent('offline:status-changed', {
        detail: { isOnline: this.isOnline },
      }));
    },

    async loadSummary() {
      if (!this.storageSupported) return;
      this.summary = await getOfflineSummary();
    },

    async clearAllOfflineData() {
      if (this.isClearing) return;

      if (!await confirmDeletion(t('common.dialog.clear_offline'))) {
        return;
      }

      this.isClearing = true;
      try {
        await clearOfflineData();
        await this.loadSummary();
      } catch (error) {
        console.error('Failed to clear offline data:', error);
      } finally {
        this.isClearing = false;
      }
    },

    formatSize(bytes: number): string {
      if (bytes === 0) return '0 B';
      const k = 1024;
      const sizes = ['B', 'KB', 'MB', 'GB'];
      const i = Math.floor(Math.log(bytes) / Math.log(k));
      return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    },
  };
}

// =============================================================================
// ALPINE REGISTRATION
// =============================================================================

/**
 * Register the offline indicator component with Alpine.js.
 */
export function registerOfflineIndicator(): void {
  Alpine.data('offlineIndicator', offlineIndicator);
}

// Auto-register when imported
if (typeof Alpine !== 'undefined') {
  document.addEventListener('alpine:init', () => {
    registerOfflineIndicator();
  });

  if (Alpine.version) {
    registerOfflineIndicator();
  }
}

// Expose globally
declare global {
  interface Window {
    offlineIndicator: typeof offlineIndicator;
  }
}

window.offlineIndicator = offlineIndicator;
