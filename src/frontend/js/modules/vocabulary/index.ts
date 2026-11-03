/**
 * Vocabulary Module - Word/term management.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.0.0
 */

// API
export * from './api/words_api';
export * from './api/terms_api';

// Stores
export * from './stores/word_store';
export * from './stores/word_form_store';
// multi_word_form_store has overlapping types (SaveResult, ValidationErrors)
// Export only unique exports, consumers can import directly if needed
export {
  initMultiWordFormStore,
  getMultiWordFormStore,
  type MultiWordFormData,
  type MultiWordFormStoreState
} from './stores/multi_word_form_store';
export * from './stores/word_list_filter';
export * from './stores/word_list_table';

// Components
export * from './components/word_popover';
export * from './components/word_modal';
export * from './components/word_edit_form';
export * from './components/multi_word_modal';
export * from './components/word_popup';
export * from './components/result_panel';

// Services
export * from './services/word_status';
export * from './services/dictionary';
export * from './services/translation_api';
export * from './services/term_operations';
export * from './services/word_dom_updates';

// Shared utilities needed by vocabulary pages
import '@shared/forms/bulk_actions';

// Side-effect imports (pages)
import './pages/word_list_app';
import './pages/new_term_form';
import './pages/bulk_translate';
import './pages/word_upload';
import './pages/expression_interactable';
import './pages/translation_page';
import './pages/starter_vocab';
import './pages/term_edit_page';
import './pages/anki_sync';
