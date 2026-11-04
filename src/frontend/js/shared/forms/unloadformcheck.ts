/**
 * Check for unsaved changes when unloading window.
 *
 * @license unlicense
 * @author  andreask7 <andreasks7@users.noreply.github.com>
 * @since   1.6.16-fork
 * @since   2.3.1-fork You should not only include this script to check before unload
 *          but also call ask_before_exiting once.
 * @since   2.10.0-fork This file was refactored in a single object, use it instead
 */

import { setupTagChangeTracking } from '@shared/components/tagify_tags';
import { confirmDiscardChanges } from '@shared/components/confirm_dialog';
import { t } from '@shared/i18n/translator';

let linksGuarded = false;

/**
 * Whether following a link would replace the current page.
 *
 * @param link The clicked link
 * @returns False for new tabs, downloads, in-page anchors and non-web links
 */
function replacesThePage(link: HTMLAnchorElement): boolean {
  if ((link.target && link.target !== '_self') || link.hasAttribute('download')) {
    return false;
  }
  const url = new URL(link.href);
  if (url.protocol !== 'http:' && url.protocol !== 'https:') {
    return false;
  }
  const samePage = url.pathname === window.location.pathname
    && url.search === window.location.search;
  return !(samePage && link.href.includes('#'));
}

/**
 * Ask in the page before a link discards a modified form.
 *
 * Runs when the click reaches the document, so a link whose own handler
 * cancelled it (a tab, a dialog opener) never asks. A browser cannot show this
 * dialog while it unloads, so closing or reloading the tab, and scripts that
 * navigate by themselves, still get the native prompt from `beforeunload`.
 *
 * @param event The click
 */
function askBeforeFollowingLink(event: MouseEvent): void {
  if (
    !lwtFormCheck.dirty || event.defaultPrevented || event.button !== 0
    || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey
    || !(event.target instanceof Element)
  ) {
    return;
  }
  const link = event.target.closest<HTMLAnchorElement>('a[href]');
  if (!link || !replacesThePage(link)) {
    return;
  }

  event.preventDefault();
  void confirmDiscardChanges(t('common.dialog.leave_message')).then((leave) => {
    if (leave) {
      lwtFormCheck.resetDirty();
      window.location.assign(link.href);
    }
  });
}

/**
 * Keeps track of a modified form.
 */
export const lwtFormCheck = {

  dirty: false,

  /**
   * Check the DIRTY status and ask before leaving.
   *
   * @returns Confirmation string
   */
  isDirtyMessage: function (): string | undefined {
    if (lwtFormCheck.dirty) {
      return '** You have unsaved changes! **';
    }
    return undefined;
  },

  /**
   * Set the DIRTY variable to 1.
   */
  makeDirty: function (): void {
    lwtFormCheck.dirty = true;
  },

  /**
   * Set the DIRTY variable to 0.
   */
  resetDirty: function (): void {
    lwtFormCheck.dirty = false;
  },

  /**
   * Called when a tag is changed (added or removed).
   *
   * @param duringInit - Whether this change happened during initialization
   */
  tagChanged: function (duringInit: boolean): void {
    if (!duringInit) {
      lwtFormCheck.dirty = true;
    }
  },

  /**
   * Call this function if you want to ask the user
   * before exiting the form.
   */
  askBeforeExit: function (): void {
    // Set up tag change tracking with Tagify
    setupTagChangeTracking(lwtFormCheck.tagChanged);

    // Add change listener to form elements (excluding quickmenu)
    document.querySelectorAll<HTMLElement>('input, textarea, select')
      .forEach(el => {
        if (el.id !== 'quickmenu') {
          el.addEventListener('change', lwtFormCheck.makeDirty);
        }
      });

    // Reset dirty on submit/reset clicks
    document.querySelectorAll<HTMLElement>('[type="reset"], [type="submit"]')
      .forEach(el => {
        el.addEventListener('click', lwtFormCheck.resetDirty);
      });

    // Ask in the page when a link would leave it
    if (!linksGuarded) {
      linksGuarded = true;
      document.addEventListener('click', askBeforeFollowingLink);
    }

    // Warn before unload
    window.addEventListener('beforeunload', (e) => {
      const message = lwtFormCheck.isDirtyMessage();
      if (message) {
        e.preventDefault();
        return message;
      }
    });
  }
};
