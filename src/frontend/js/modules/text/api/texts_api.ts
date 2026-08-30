/**
 * Texts API - Type-safe wrapper for text operations.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.0.0
 */

import { apiGet, apiPost, apiPut, type ApiResponse } from '@shared/api/client';

/**
 * Dictionary links for a language.
 */
export interface DictLinks {
  dict1: string;
  dict2: string;
  translator: string;
}

/**
 * Text configuration for reading view.
 */
export interface TextReadingConfig {
  textId: number;
  langId: number;
  title: string;
  audioUri: string | null;
  sourceUri: string | null;
  audioPosition: number;
  rightToLeft: boolean;
  textSize: number;
  removeSpaces: boolean;
  dictLinks: DictLinks;
  // Annotation/display settings
  showLearning: number;
  displayStatTrans: number;
  modeTrans: number;
  termDelimiter: string;
  annTextSize: number;
  // Reader layout settings
  readerWidth: number;
  /**
   * Set when the text parsed into (almost) no words, which happens when the
   * language's word characters do not match its script. Null when fine.
   */
  parseWarning: ParseWarning | null;
}

/**
 * Why a text came out with nothing the reader can click, and where to fix it.
 */
export interface ParseWarning {
  headline: string;
  detail: string;
  linkLabel: string;
  linkHref: string;
}

/**
 * One chapter entry for the reader's chapter dropdown.
 */
export interface BookChapter {
  id: number;
  num: number;
  title: string;
}

/**
 * Book/chapter context for the reading screen's navigation, as returned by
 * GET /texts/{id}/book-context. Null when the text is standalone.
 */
export interface BookContext {
  bookId: number;
  bookTitle: string;
  chapterNum: number;
  chapterTitle: string | null;
  totalChapters: number;
  prevTextId: number | null;
  nextTextId: number | null;
  chapters: BookChapter[];
}

/** Envelope for GET /texts/{id}/book-context. */
export interface BookContextResponse {
  book: BookContext | null;
}

/** Player settings shared by GET /texts/{id}/audio. */
export interface AudioPlayerSettings {
  repeatMode: boolean;
  skipSeconds: number;
  playbackRate: number;
}

/** Audio metadata for the reader, as returned by GET /texts/{id}/audio. */
export interface AudioInfo {
  uri: string;
  position: number;
  playerSettings: AudioPlayerSettings;
}

/**
 * Multi-word expression reference data.
 */
export interface MultiWordRef {
  text: string;
  translation: string;
  status: number;
  wordId: number | null;
  startPos: number;
  endPos: number;
}

/**
 * Word data for client-side rendering.
 */
export interface TextWord {
  position: number;
  sentenceId: number;
  text: string;
  textLc: string;
  hex: string;
  isNotWord: boolean;
  wordCount: number;
  hidden: boolean;
  wordId?: number | null;
  status?: number;
  translation?: string;
  romanization?: string;
  notes?: string;
  tags?: string;
  // Multiword references (mw2, mw3, etc.) - now includes full expression details
  [key: `mw${number}`]: MultiWordRef | undefined;
}

/**
 * Response for getting text words.
 */
export interface TextWordsResponse {
  words: TextWord[];
  config: TextReadingConfig;
}

/**
 * Word count statistics for a text.
 */
export interface TextWordCount {
  total: number;
  unique: number;
  unknown: number;
  learning: number;
  learned: number;
  wellKnown: number;
  ignored: number;
}

/**
 * Text statistics response.
 */
export interface TextStatistics {
  wordCounts: TextWordCount;
  statusBreakdown?: Record<number, number>;
}

/**
 * Display mode settings for text reading.
 */
export interface TextDisplayMode {
  annotations: number;
  romanization: boolean;
  translation: boolean;
}

/**
 * Text creation request.
 */
export interface TextCreateRequest {
  title: string;
  langId: number;
  text: string;
  sourceUri?: string;
  audioUri?: string;
  tags?: string[];
}

/**
 * Text save response.
 *
 * A text long enough to need splitting is created as a book of chapters
 * instead, so a create can come back with a `bookId` — `textId` is then the
 * first chapter. An update never splits and always answers with `bookId: null`.
 */
export interface TextSaveResponse {
  textId: number | null;
  bookId: number | null;
  message: string;
  error?: string;
}

/**
 * Display mode update response.
 */
export interface DisplayModeResponse {
  updated?: boolean;
  error?: string;
}

/**
 * Word data returned from mark-all operations.
 */
export interface MarkedWordData {
  wid: number;
  hex: string;
  term: string;
  status: number;
}

/**
 * Response for mark-all operations.
 */
export interface MarkAllResponse {
  count: number;
  words?: MarkedWordData[];
}

/**
 * Print item from API - represents a word or punctuation in print view.
 */
export interface PrintItem {
  position: number;
  text: string;
  isWord: boolean;
  isParagraph: boolean;
  wordId: number | null;
  status: number | null;
  translation: string;
  romanization: string;
  tags: string;
}

/**
 * Configuration for print view.
 */
export interface PrintConfig {
  textId: number;
  title: string;
  sourceUri: string;
  audioUri: string;
  langId: number;
  textSize: number;
  rtlScript: boolean;
  hasAnnotation: boolean;
  savedAnn: number;
  savedStatus: number;
  savedPlacement: number;
}

/**
 * Response for getting print items.
 */
export interface PrintItemsResponse {
  items: PrintItem[];
  config: PrintConfig;
}

/**
 * Annotation item from API - represents a term in improved annotated view.
 */
export interface AnnotationItem {
  order: number;
  text: string;
  wordId: number | null;
  translation: string;
  isWord: boolean;
}

/**
 * Configuration for annotation view.
 */
export interface AnnotationConfig {
  textId: number;
  title: string;
  sourceUri: string;
  audioUri: string;
  langId: number;
  textSize: number;
  rtlScript: boolean;
  hasAnnotation: boolean;
  ttsClass: string | null;
}

/**
 * Response for getting annotation.
 */
export interface AnnotationResponse {
  items: AnnotationItem[] | null;
  config: AnnotationConfig;
}

/**
 * Texts API methods.
 */
export const TextsApi = {
  /**
   * Get word count statistics for one or more texts.
   *
   * @param textIds Array of text IDs or comma-separated string
   * @returns Promise with text statistics
   */
  async getStatistics(
    textIds: number[] | string
  ): Promise<ApiResponse<TextStatistics>> {
    const ids = Array.isArray(textIds) ? textIds.join(',') : textIds;
    return apiGet<TextStatistics>('/texts/statistics', { text_ids: ids });
  },

  /**
   * Create a new text.
   *
   * @param data Text creation data
   * @returns Promise with new text ID or error
   */
  async create(data: TextCreateRequest): Promise<ApiResponse<TextSaveResponse>> {
    return apiPost<TextSaveResponse>('/texts', {
      title: data.title,
      language_id: data.langId,
      text: data.text,
      source_uri: data.sourceUri,
      audio_uri: data.audioUri,
      tags: data.tags
    });
  },

  /**
   * Update an existing text and reparse it.
   *
   * Same payload as create. Reparsing discards the text's sentences and word
   * occurrences and builds them again, so reading progress in the text is lost
   * — which is what the form POST did too.
   *
   * @param textId Text ID to update
   * @param data   Text fields
   * @returns Promise with the saved text ID or error
   */
  async update(
    textId: number,
    data: TextCreateRequest
  ): Promise<ApiResponse<TextSaveResponse>> {
    return apiPut<TextSaveResponse>(`/texts/${textId}`, {
      title: data.title,
      language_id: data.langId,
      text: data.text,
      source_uri: data.sourceUri,
      audio_uri: data.audioUri,
      tags: data.tags
    });
  },

  /**
   * Update an archived text.
   *
   * Archived texts carry no sentences or word occurrences, so unlike
   * {@link update} this saves the row without reparsing it.
   *
   * @param textId Archived text ID to update
   * @param data   Text fields
   * @returns Promise with the saved text ID or error
   */
  async updateArchived(
    textId: number,
    data: TextCreateRequest
  ): Promise<ApiResponse<TextSaveResponse>> {
    return apiPut<TextSaveResponse>(`/texts/archived/${textId}`, {
      title: data.title,
      language_id: data.langId,
      text: data.text,
      source_uri: data.sourceUri,
      audio_uri: data.audioUri,
      tags: data.tags
    });
  },

  /**
   * Update display mode for text reading.
   *
   * @param textId Text ID
   * @param mode   Display mode settings
   * @returns Promise with update result
   */
  async setDisplayMode(
    textId: number,
    mode: Partial<TextDisplayMode>
  ): Promise<ApiResponse<DisplayModeResponse>> {
    return apiPut<DisplayModeResponse>(`/texts/${textId}/display-mode`, mode);
  },

  /**
   * Mark all unknown words in a text as well-known.
   *
   * @param textId Text ID
   * @returns Promise with count and words data
   */
  async markAllWellKnown(
    textId: number
  ): Promise<ApiResponse<MarkAllResponse>> {
    return apiPut<MarkAllResponse>(`/texts/${textId}/mark-all-wellknown`, {});
  },

  /**
   * Mark all unknown words in a text as ignored.
   *
   * @param textId Text ID
   * @returns Promise with count and words data
   */
  async markAllIgnored(
    textId: number
  ): Promise<ApiResponse<MarkAllResponse>> {
    return apiPut<MarkAllResponse>(`/texts/${textId}/mark-all-ignored`, {});
  },

  /**
   * Archive or delete multiple texts in one call.
   *
   * The JSON path for the destructive bulk actions, so they work against a
   * configurable API base URL instead of a same-origin form POST. The server
   * scopes the action to the current user's texts.
   *
   * @param action 'archive' or 'delete'
   * @param ids    Text IDs to act on
   * @returns Promise with the number of texts affected
   */
  async bulkAction(
    action: 'archive' | 'delete',
    ids: number[]
  ): Promise<ApiResponse<{ count: number }>> {
    return apiPut<{ count: number }>('/texts/bulk-action', { action, ids });
  },

  /**
   * Get all words for a text (for client-side rendering).
   *
   * @param textId Text ID
   * @returns Promise with words array and config
   */
  async getWords(textId: number): Promise<ApiResponse<TextWordsResponse>> {
    return apiGet<TextWordsResponse>(`/texts/${textId}/words`);
  },

  /**
   * Get print items for a text (for client-side print rendering).
   *
   * @param textId Text ID
   * @returns Promise with print items and config
   */
  async getPrintItems(textId: number): Promise<ApiResponse<PrintItemsResponse>> {
    return apiGet<PrintItemsResponse>(`/texts/${textId}/print-items`);
  },

  /**
   * Get annotation data for improved/annotated text view.
   *
   * @param textId Text ID
   * @returns Promise with annotation items and config
   */
  async getAnnotation(textId: number): Promise<ApiResponse<AnnotationResponse>> {
    return apiGet<AnnotationResponse>(`/texts/${textId}/annotation`);
  },

  /**
   * Get the book/chapter context for the reader's chapter navigation.
   *
   * @param textId Text ID
   * @returns Promise with `{ book }`; book is null for a standalone text
   */
  async getBookContext(textId: number): Promise<ApiResponse<BookContextResponse>> {
    return apiGet<BookContextResponse>(`/texts/${textId}/book-context`);
  },

  /**
   * Get the audio metadata + player settings for the reader's media player.
   *
   * @param textId Text ID
   * @returns Promise with audio uri, saved position, and player settings
   */
  async getAudio(textId: number): Promise<ApiResponse<AudioInfo>> {
    return apiGet<AudioInfo>(`/texts/${textId}/audio`);
  }
};
