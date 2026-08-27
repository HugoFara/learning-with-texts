/**
 * Languages API - Type-safe wrapper for language management operations.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.0.0
 */

import {
  apiGet,
  apiPost,
  apiPut,
  apiDelete,
  type ApiResponse
} from '@shared/api/client';

// =========================================================================
// Type Definitions
// =========================================================================

/**
 * Language list item (summary for list display).
 */
export interface LanguageListItem {
  id: number;
  name: string;
  hasExportTemplate: boolean;
  textCount: number;
  archivedTextCount: number;
  wordCount: number;
  feedCount: number;
  articleCount: number;
}

/**
 * Full language data for editing.
 */
export interface LanguageFull {
  id: number;
  name: string;
  dict1Uri: string;
  dict2Uri: string;
  translatorUri: string;
  dict1PopUp: boolean;
  dict2PopUp: boolean;
  translatorPopUp: boolean;
  sourceLang: string | null;
  targetLang: string | null;
  exportTemplate: string;
  textSize: number;
  characterSubstitutions: string;
  regexpSplitSentences: string;
  exceptionsSplitSentences: string;
  regexpWordCharacters: string;
  removeSpaces: boolean;
  splitEachChar: boolean;
  rightToLeft: boolean;
  ttsVoiceApi: string;
  showRomanization: boolean;
  parserType: string | null;
  localDictMode: number;
}

/**
 * Language definition (preset configuration).
 */
export interface LanguageDefinition {
  glosbeIso: string;
  googleIso: string;
  biggerFont: boolean;
  wordCharRegExp: string;
  sentSplRegExp: string;
  makeCharacterWord: boolean;
  removeSpaces: boolean;
  rightToLeft: boolean;
  /** Tokenizer the preset asks for; '' leaves it inferred from the flags. */
  parserType: string;
}

/**
 * Request body for creating a language.
 */
export interface LanguageCreateRequest {
  name: string;
  dict1Uri?: string;
  dict2Uri?: string;
  translatorUri?: string;
  dict1PopUp?: boolean;
  dict2PopUp?: boolean;
  translatorPopUp?: boolean;
  sourceLang?: string | null;
  targetLang?: string | null;
  exportTemplate?: string;
  textSize?: number;
  characterSubstitutions?: string;
  regexpSplitSentences?: string;
  exceptionsSplitSentences?: string;
  regexpWordCharacters?: string;
  removeSpaces?: boolean;
  splitEachChar?: boolean;
  rightToLeft?: boolean;
  ttsVoiceApi?: string;
  showRomanization?: boolean;
  parserType?: string | null;
  localDictMode?: number;
}

/**
 * Request body for updating a language.
 * Same fields as create, all optional except name.
 */
export type LanguageUpdateRequest = LanguageCreateRequest;

/**
 * Response for language list.
 */
export interface LanguageListResponse {
  languages: LanguageListItem[];
  currentLanguageId: number;
}

/**
 * Response for single language.
 */
export interface LanguageGetResponse {
  language: LanguageFull;
  allLanguages: Record<string, number>;
}

/**
 * Response for language create operation.
 */
export interface LanguageCreateResponse {
  success: boolean;
  id?: number;
  error?: string;
}

/**
 * Response for language update operation.
 */
export interface LanguageUpdateResponse {
  success: boolean;
  reparsed?: number;
  message?: string;
  error?: string;
}

/**
 * Response for language delete operation.
 */
export interface LanguageDeleteResponse {
  success: boolean;
  error?: string;
  relatedData?: {
    texts: number;
    archivedTexts: number;
    words: number;
    feeds: number;
  };
}

/**
 * Response for language stats.
 */
export interface LanguageStatsResponse {
  texts: number;
  archivedTexts: number;
  words: number;
  feeds: number;
}

/**
 * Response for language refresh (reparse) operation.
 */
export interface LanguageRefreshResponse {
  success: boolean;
  sentencesDeleted: number;
  textItemsDeleted: number;
  sentencesAdded: number;
  textItemsAdded: number;
}

/**
 * Response for language definitions.
 */
export interface LanguageDefinitionsResponse {
  definitions: Record<string, LanguageDefinition>;
}

/**
 * Response for set default operation.
 */
export interface LanguageSetDefaultResponse {
  success: boolean;
}

// =========================================================================
// API Methods
// =========================================================================

/**
 * Languages API methods.
 */
export const LanguagesApi = {
  /**
   * Get all languages with statistics.
   *
   * @returns Promise with language list and current language ID
   */
  async list(): Promise<ApiResponse<LanguageListResponse>> {
    return apiGet<LanguageListResponse>('/languages');
  },

  /**
   * Get a single language by ID.
   *
   * @param id Language ID
   * @returns Promise with language data and all languages dictionary
   */
  async get(id: number): Promise<ApiResponse<LanguageGetResponse>> {
    return apiGet<LanguageGetResponse>(`/languages/${id}`);
  },

  /**
   * Create a new language.
   *
   * @param data Language data
   * @returns Promise with create result including new ID
   */
  async create(
    data: LanguageCreateRequest
  ): Promise<ApiResponse<LanguageCreateResponse>> {
    return apiPost<LanguageCreateResponse>(
      '/languages',
      data as unknown as Record<string, unknown>
    );
  },

  /**
   * Update an existing language.
   *
   * @param id   Language ID
   * @param data Updated language data
   * @returns Promise with update result
   */
  async update(
    id: number,
    data: LanguageUpdateRequest
  ): Promise<ApiResponse<LanguageUpdateResponse>> {
    return apiPut<LanguageUpdateResponse>(
      `/languages/${id}`,
      data as unknown as Record<string, unknown>
    );
  },

  /**
   * Delete a language.
   *
   * @param id Language ID
   * @returns Promise with delete result
   */
  async delete(id: number): Promise<ApiResponse<LanguageDeleteResponse>> {
    return apiDelete<LanguageDeleteResponse>(`/languages/${id}`);
  },

  /**
   * Get statistics for a language (text counts, word counts, etc.).
   *
   * @param id Language ID
   * @returns Promise with language stats
   */
  async getStats(id: number): Promise<ApiResponse<LanguageStatsResponse>> {
    return apiGet<LanguageStatsResponse>(`/languages/${id}/stats`);
  },

  /**
   * Refresh (reparse) all texts for a language.
   * This is needed when parsing-related settings change.
   *
   * @param id Language ID
   * @returns Promise with refresh statistics
   */
  async refresh(id: number): Promise<ApiResponse<LanguageRefreshResponse>> {
    return apiPost<LanguageRefreshResponse>(`/languages/${id}/refresh`, {});
  },

  /**
   * Get all predefined language definitions (presets).
   *
   * @returns Promise with language definitions keyed by name
   */
  async getDefinitions(): Promise<ApiResponse<LanguageDefinitionsResponse>> {
    return apiGet<LanguageDefinitionsResponse>('/languages/definitions');
  },

  /**
   * Set a language as the current/default language.
   *
   * @param id Language ID
   * @returns Promise with success status
   */
  async setDefault(
    id: number
  ): Promise<ApiResponse<LanguageSetDefaultResponse>> {
    return apiPost<LanguageSetDefaultResponse>(
      `/languages/${id}/set-default`,
      {}
    );
  }
};
