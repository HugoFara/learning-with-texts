/**
 * Review API - Type-safe wrapper for test/review operations.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.0.0
 */

import { apiGet, apiPut, type ApiResponse } from '@shared/api/client';

/**
 * Word test data returned from the API.
 */
export interface WordTestData {
  term_id: number | string;
  solution?: string;
  term_text: string;
  group: string;
}

/**
 * Tomorrow count response.
 */
export interface TomorrowCountResponse {
  count: number;
}

/**
 * Review status update request.
 */
export interface ReviewStatusRequest {
  wordId: number;
  status?: number;
  change?: number;
  grade?: ReviewGrade;
}

/**
 * The four FSRS grades. Values match the server's Rating enum and Anki's
 * `revlog.ease`, so a review needs no translation on the way out.
 */
export type ReviewGrade = 1 | 2 | 3 | 4;

/**
 * What each grade would schedule, in whole days, keyed by grade.
 */
export interface ReviewIntervalsResponse {
  intervals: Record<string, number>;
}

/**
 * Review status update response.
 */
export interface ReviewStatusResponse {
  status?: number;
  controls?: string;
  error?: string;
}

/**
 * Parameters for getting next word to review.
 */
export interface NextWordParams {
  reviewKey: string;
  selection: string;
  wordMode: boolean;
  lgId: number;
  wordRegex: string;
  type: number;
}

/**
 * Language settings for a review.
 */
export interface ReviewLangSettings {
  name: string;
  dict1Uri: string;
  dict2Uri: string;
  translateUri: string;
  textSize: number;
  rtl: boolean;
  langCode: string;
}

/**
 * Review configuration response from server.
 */
export interface ReviewConfigResponse {
  reviewKey: string;
  selection: string;
  reviewType: number;
  isTableMode: boolean;
  wordMode: boolean;
  langId: number;
  wordRegex: string;
  langSettings: ReviewLangSettings;
  progress: {
    total: number;
    remaining: number;
    wrong: number;
    correct: number;
  };
  timer: {
    startTime: number;
    serverTime: number;
  };
  title: string;
  property: string;
}

/**
 * Word data for table review.
 */
export interface TableReviewWord {
  id: number;
  text: string;
  translation: string;
  romanization: string;
  sentence: string;
  sentenceHtml: string;
  status: number;
  score: number;
}

/**
 * Table review words response.
 */
export interface TableWordsResponse {
  words: TableReviewWord[];
  langSettings: ReviewLangSettings;
}

/**
 * Review API methods.
 */
export const ReviewApi = {
  /**
   * Get the next word to review.
   *
   * @param params Review parameters
   * @returns Promise with word review data
   */
  async getNextWord(params: NextWordParams): Promise<ApiResponse<WordTestData>> {
    return apiGet<WordTestData>('/review/next-word', {
      review_key: params.reviewKey,
      selection: params.selection,
      word_mode: params.wordMode,
      language_id: params.lgId,
      word_regex: params.wordRegex,
      type: params.type
    });
  },

  /**
   * Get count of words due for review tomorrow.
   *
   * @param reviewKey Review session key
   * @param selection Word selection criteria
   * @returns Promise with count
   */
  async getTomorrowCount(
    reviewKey: string,
    selection: string
  ): Promise<ApiResponse<TomorrowCountResponse>> {
    return apiGet<TomorrowCountResponse>('/review/tomorrow-count', {
      review_key: reviewKey,
      selection
    });
  },

  /**
   * Update word status during review.
   *
   * @param wordId Word ID
   * @param status New status (1-5, 98, 99) or undefined for increment
   * @param change Status change amount (+1 or -1)
   * @returns Promise with update result
   */
  async updateStatus(
    termId: number,
    status?: number,
    change?: number,
    grade?: ReviewGrade
  ): Promise<ApiResponse<ReviewStatusResponse>> {
    return apiPut<ReviewStatusResponse>('/review/status', {
      term_id: termId,
      status,
      change,
      grade
    });
  },

  /**
   * Get what each grade would schedule for a term.
   *
   * @param termId Term being reviewed
   * @returns Promise with the interval in days per grade
   */
  async getIntervals(
    termId: number
  ): Promise<ApiResponse<ReviewIntervalsResponse>> {
    return apiGet<ReviewIntervalsResponse>('/review/intervals', {
      term_id: termId
    });
  },

  /**
   * Get review configuration.
   *
   * @param params Review parameters (lang, text, or selection)
   * @returns Promise with review configuration
   */
  async getReviewConfig(params: {
    lang?: number;
    text?: number;
    selection?: number;
  }): Promise<ApiResponse<ReviewConfigResponse>> {
    return apiGet<ReviewConfigResponse>('/review/config', params);
  },

  /**
   * Get all words for table review mode.
   *
   * @param reviewKey Review session key
   * @param selection Word selection criteria
   * @returns Promise with table words
   */
  async getTableWords(
    reviewKey: string,
    selection: string
  ): Promise<ApiResponse<TableWordsResponse>> {
    return apiGet<TableWordsResponse>('/review/table-words', {
      review_key: reviewKey,
      selection
    });
  }
};
