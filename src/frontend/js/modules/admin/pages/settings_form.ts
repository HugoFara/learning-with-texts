/**
 * Settings Form Module - Alpine.js component for settings form interactions
 *
 * @author  HugoFara <git@hugofara.net>
 * @license Unlicense <http://unlicense.org/>
 * @since   3.0.0
 * @since   3.1.0 Migrated to Alpine.js component
 */

import { confirmAction, confirmBeforeSubmit } from '@shared/components/confirm_dialog';
import { t } from '@shared/i18n/translator';
import { onDomReady } from '@shared/utils/dom_ready';
import Alpine from 'alpinejs';
import { lwtFormCheck } from '@shared/forms/unloadformcheck';

/**
 * Alpine.js component for settings form management.
 * Handles form change tracking, navigation, and submission.
 */
export function settingsFormApp() {
  return {
    /** Whether the form has unsaved changes */
    isDirty: false,

    /** Loading state for submit buttons */
    isSubmitting: false,

    /**
     * Initialize the component.
     */
    init() {
      // Set up form change tracking
      lwtFormCheck.askBeforeExit();
    },

    /**
     * Navigate to a URL, resetting dirty state first.
     */
    navigate(url: string) {
      lwtFormCheck.resetDirty();
      location.href = url;
    },

    /**
     * Go back in browser history.
     */
    historyBack() {
      history.back();
    },

    /**
     * Handle form submission with confirmation.
     */
    confirmSubmit(event: Event, message: string = t('common.dialog.are_you_sure')) {
      const form = event.target as HTMLFormElement;
      if (!confirmBeforeSubmit(event, form, () => confirmAction(message))) {
        return false;
      }
      this.isSubmitting = true;
      return true;
    }
  };
}

/**
 * Alpine.js component for the theme selector with live preview.
 * Reads initial theme from the `data-current-theme` attribute on its root element.
 */
export function themeSelector() {
  return {
    currentTheme: '',
    description: '',
    mode: '',
    highlighting: '',
    wordBreaking: '',

    init() {
      const el = (this as unknown as { $el: HTMLElement }).$el;
      this.currentTheme = el.dataset.currentTheme || '';
      this.updateInfo();
    },

    updateInfo() {
      const select = document.getElementById('set-theme-dir') as HTMLSelectElement | null;
      if (!select) return;
      const option = select.options[select.selectedIndex];
      this.description = option?.dataset.description || '';
      this.mode = option?.dataset.mode || 'light';
      this.highlighting = option?.dataset.highlighting || '';
      this.wordBreaking = option?.dataset.wordBreaking || '';
    },

    previewTheme() {
      const select = document.getElementById('set-theme-dir') as HTMLSelectElement | null;
      if (!select) return;
      const themePath = select.value;
      const styleId = 'theme-preview-styles';
      let styleEl = document.getElementById(styleId) as HTMLLinkElement | null;
      if (!styleEl) {
        styleEl = document.createElement('link');
        styleEl.id = styleId;
        styleEl.rel = 'stylesheet';
        document.head.appendChild(styleEl);
      }
      styleEl.href = '/' + themePath + 'styles.css?preview=' + Date.now();
    },

    onThemeChange() {
      this.updateInfo();
      this.previewTheme();
    },
  };
}

// Register Alpine components
if (typeof Alpine !== 'undefined') {
  Alpine.data('settingsFormApp', settingsFormApp);
  Alpine.data('themeSelector', themeSelector);
}

// ============================================================================
// Legacy API - For backward compatibility with non-Alpine pages
// ============================================================================

/**
 * Initialize settings form event handlers.
 * Sets up form change tracking and navigation buttons.
 */
export function initSettingsForm(): void {
  const form = document.querySelector<HTMLFormElement>('[data-lwt-settings-form]');
  if (!form) {
    return;
  }

  // Set up form change tracking
  lwtFormCheck.askBeforeExit();

  // Handle settings navigation buttons (reset dirty before navigating)
  document.addEventListener('click', (e) => {
    const target = e.target as HTMLElement;
    const button = target.closest<HTMLElement>('[data-action="settings-navigate"]');
    if (button) {
      const url = button.dataset.url;
      if (url) {
        lwtFormCheck.resetDirty();
        location.href = url;
      }
    }
  });
}

/**
 * Initialize confirm submit forms.
 * Shows a confirmation dialog before form submission.
 * Also shows loading state on the submit button after confirmation.
 */
export function initConfirmSubmitForms(): void {
  document.addEventListener('submit', (e) => {
    const form = (e.target as HTMLElement).closest<HTMLFormElement>('form[data-action="confirm-submit"]');
    if (form) {
      const message = form.dataset.confirmMessage || t('common.dialog.are_you_sure');
      if (!confirmBeforeSubmit(e, form, () => confirmAction(message))) {
        return false;
      }
      // Show loading state on submit button after confirmation
      const submitButton = form.querySelector<HTMLInputElement | HTMLButtonElement>(
        'input[type="submit"], button[type="submit"]'
      );
      if (submitButton) {
        submitButton.classList.add('is-loading');
        submitButton.disabled = true;
      }
    }
    return true;
  });
}

/**
 * Initialize navigation buttons with data-action="navigate".
 * This is a general handler for simple navigation buttons.
 */
export function initNavigateButtons(): void {
  document.addEventListener('click', (e) => {
    const target = e.target as HTMLElement;
    const button = target.closest<HTMLElement>('[data-action="navigate"]');
    if (button) {
      const url = button.dataset.url;
      if (url) {
        location.href = url;
      }
    }
  });
}

/**
 * Initialize history back buttons with data-action="history-back".
 * This is a general handler for back buttons.
 */
export function initHistoryBackButtons(): void {
  document.addEventListener('click', (e) => {
    const target = e.target as HTMLElement;
    const button = target.closest<HTMLElement>('[data-action="history-back"]');
    if (button) {
      e.preventDefault();
      history.back();
    }
  });
}

// Auto-initialize when DOM is ready
onDomReady(() => {
  initSettingsForm();
  initConfirmSubmitForms();
  initNavigateButtons();
  initHistoryBackButtons();
});
