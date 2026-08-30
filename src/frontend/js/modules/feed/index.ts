/**
 * Feed Module Entry Point.
 *
 * Registers all Alpine.js components and stores for the feed feature.
 * Import this file to initialize the full feed functionality.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.0.0
 */

// Types (for re-export)
export * from './types/feed_wizard_types';

// API (side-effect import — has overlapping type names with feed_wizard_types)
import './api/feeds_api';

// Utilities
export * from './utils/xpath_utils';

// Services
export { HighlightService, getHighlightService, initHighlightService } from './services/highlight_service';
export { showSelectedArticle, clearArticle, ARTICLE_CONTAINER_ID } from './services/article_preview';
export { openFeed, resumeFeed, toStoreOptions } from './services/wizard_flow';

// Stores
export { initFeedWizardStore, getFeedWizardStore } from './stores/feed_wizard_store';
export { initFeedManagerStore, getFeedManagerStore } from './stores/feed_manager_store';

// Shared utilities needed by feed pages
import '@shared/components/sorttable';
import '@shared/forms/bulk_actions';

// Components (side effects — register Alpine.data)
import './components/feed_form_component';
import './components/feed_loader_component';
export { feedWizardStep1Data, initFeedWizardStep1Alpine } from './components/feed_wizard_step1';
export { feedWizardStep2Data, initFeedWizardStep2Alpine } from './components/feed_wizard_step2';
export { feedWizardStep3Data, initFeedWizardStep3Alpine } from './components/feed_wizard_step3';
export { feedWizardStep4Data, initFeedWizardStep4Alpine } from './components/feed_wizard_step4';

// Pages (side effects)
import './pages/feed_manager_app';
export { feedWizardData } from './pages/feed_wizard';
export { readWizardPageConfig } from './pages/feed_wizard_config';
