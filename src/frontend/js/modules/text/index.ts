/**
 * Text Module - Text reading and management.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.0.0
 */

// API
export * from './api/texts_api';

// Stores
export * from './stores/reading_state';
export * from './stores/text_config';

// Components
export * from './components/text_reader';

// Reading pages
export * from './pages/reading/text_renderer';
export * from './pages/reading/text_display';
export * from './pages/reading/text_keyboard';
export * from './pages/reading/text_multiword_selection';
export * from './pages/reading/text_reading_init';
export * from './pages/reading/annotation_toggle';
export * from './pages/reading/annotation_interactions';
export * from './pages/reading/text_annotations';
export * from './pages/reading/text_styles';

// Shared utilities needed by text pages
import '@shared/forms/bulk_actions';
import '@shared/components/searchable_select';
import '@/media';

// Side-effect imports (pages)
import './pages/text_list';
import './pages/texts_grouped_app';
import './pages/archived_texts_grouped_app';
import './pages/archived_text_form';
import './pages/text_status_chart';
import './pages/youtube_import';
import './pages/webpage_import';
import './pages/file_import';
import './pages/text_check_display';
import './pages/text_print_app';
import './pages/text_suggestions';
