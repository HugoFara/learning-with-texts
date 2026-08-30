/**
 * Local dictionaries API client.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.4.0
 */

import { apiDelete, apiGet, apiPost, apiPut, type ApiResponse } from '@shared/api/client';

/** A local dictionary as the list endpoint returns it. */
export interface LocalDictionary {
  id: number;
  language_id: number;
  name: string;
  description: string | null;
  source_format: string;
  entry_count: number;
  priority: number;
  enabled: boolean;
  created: string;
}

/** Envelope returned by `GET /local-dictionaries?language_id=`. */
export interface LocalDictionaryListResponse {
  dictionaries: LocalDictionary[];
  mode: string;
}

/** Envelope returned by the mutating endpoints. */
export interface LocalDictionaryMutationResponse {
  success: boolean;
  error?: string;
}

/** Envelope returned by `POST /local-dictionaries`. */
export interface LocalDictionaryCreateResponse extends LocalDictionaryMutationResponse {
  dictionary?: LocalDictionary;
}

/** Local dictionary API methods. */
export const LocalDictionariesApi = {
  /**
   * List the dictionaries configured for a language.
   *
   * @param languageId Language whose dictionaries to list
   *
   * @returns Promise with the dictionaries and the language's lookup mode
   */
  async list(languageId: number): Promise<ApiResponse<LocalDictionaryListResponse>> {
    return apiGet<LocalDictionaryListResponse>('/local-dictionaries', {
      language_id: languageId
    });
  },

  /**
   * Create an empty dictionary for a language.
   *
   * @param languageId Language to attach the dictionary to
   * @param name       Dictionary name
   *
   * @returns Promise with the outcome and, on success, the created dictionary
   */
  async create(
    languageId: number,
    name: string
  ): Promise<ApiResponse<LocalDictionaryCreateResponse>> {
    return apiPost<LocalDictionaryCreateResponse>('/local-dictionaries', {
      language_id: languageId,
      name,
      source_format: 'csv'
    });
  },

  /**
   * Enable or disable a dictionary.
   *
   * @param dictId  Dictionary to update
   * @param enabled Desired state
   *
   * @returns Promise with the outcome
   */
  async setEnabled(
    dictId: number,
    enabled: boolean
  ): Promise<ApiResponse<LocalDictionaryMutationResponse>> {
    return apiPut<LocalDictionaryMutationResponse>(`/local-dictionaries/${dictId}`, { enabled });
  },

  /**
   * Delete a dictionary and its entries.
   *
   * @param dictId Dictionary to remove
   *
   * @returns Promise with the outcome
   */
  async remove(dictId: number): Promise<ApiResponse<LocalDictionaryMutationResponse>> {
    return apiDelete<LocalDictionaryMutationResponse>(`/local-dictionaries/${dictId}`);
  }
};
