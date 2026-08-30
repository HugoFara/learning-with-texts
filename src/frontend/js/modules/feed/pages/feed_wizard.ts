/**
 * Feed Wizard Page - holds the four steps together.
 *
 * The wizard used to be four PHP pages posting to `/feeds/wizard`, each
 * render rebuilding its state from `$_SESSION`: the parsed feed, the fetched
 * article HTML, and the picked selectors as `<li>` markup the next page
 * parsed back out. The steps are panels of one page now and the state lives
 * in the store (#262, #266).
 *
 * This component owns only what surrounds the steps — which one is showing,
 * and the banner for a feed that could not be reopened. Each step is a
 * component of its own, mounted by `x-if` so it starts when the user reaches
 * it and stops when they leave.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.5.1
 */

import Alpine from 'alpinejs';
import type { FeedWizardStoreState } from '../types/feed_wizard_types';
import { getFeedWizardStore } from '../stores/feed_wizard_store';
import { resumeFeed } from '../services/wizard_flow';
import { readWizardPageConfig, type FeedWizardPageConfig } from './feed_wizard_config';

/**
 * The wizard page component's state.
 */
export interface FeedWizardData {
  config: FeedWizardPageConfig;
  loading: boolean;
  error: string;

  readonly store: FeedWizardStoreState;
  readonly isStep1: boolean;
  readonly isStep2: boolean;
  readonly isStep3: boolean;
  readonly isStep4: boolean;
  readonly isPicking: boolean;

  init(): void;
  hasError(): boolean;
}

/**
 * Feed wizard page component factory.
 */
export function feedWizardData(): FeedWizardData {
  const config = readWizardPageConfig();

  return {
    config,
    loading: false,
    error: '',

    get store(): FeedWizardStoreState {
      return getFeedWizardStore();
    },

    get isStep1(): boolean {
      return this.store.currentStep === 1;
    },

    get isStep2(): boolean {
      return this.store.currentStep === 2;
    },

    get isStep3(): boolean {
      return this.store.currentStep === 3;
    },

    get isStep4(): boolean {
      return this.store.currentStep === 4;
    },

    /**
     * Whether the article is on screen, which is both picking steps.
     */
    get isPicking(): boolean {
      return this.store.currentStep === 2 || this.store.currentStep === 3;
    },

    init(): void {
      this.store.reset();

      if (this.config.editFeedId === null) {
        return;
      }

      this.loading = true;
      void resumeFeed(this.store, this.config.editFeedId).then((error) => {
        this.loading = false;
        this.error = error;
      });
    },

    hasError(): boolean {
      return this.error !== '';
    }
  };
}

Alpine.data('feedWizard', feedWizardData);
