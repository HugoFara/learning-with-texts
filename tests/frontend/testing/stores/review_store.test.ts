/**
 * Tests for review/stores/review_store.ts - Review Alpine.js store
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

// Hoist mock functions so they're available during vi.mock hoisting
const { mockReviewApi } = vi.hoisted(() => ({
  mockReviewApi: {
    getNextWord: vi.fn(),
    updateStatus: vi.fn(),
    getTomorrowCount: vi.fn(),
    getIntervals: vi.fn(() => Promise.resolve({ data: { intervals: {} } }))
  }
}));

// Mock Alpine.js before importing the store
vi.mock('alpinejs', () => {
  const stores: Record<string, unknown> = {};
  return {
    default: {
      store: vi.fn((name: string, data?: unknown) => {
        if (data !== undefined) {
          stores[name] = data;
        }
        return stores[name];
      })
    }
  };
});

// Mock ReviewApi
vi.mock('../../../../src/frontend/js/modules/review/api/review_api', () => ({
  ReviewApi: mockReviewApi
}));

import Alpine from 'alpinejs';
import {
  getReviewStore,
  initReviewStore,
  isReviewStoreInitialized
} from '../../../../src/frontend/js/modules/review/stores/review_store';

describe('review/stores/review_store.ts', () => {
  let localStorageMock: Record<string, string>;

  beforeEach(() => {
    vi.clearAllMocks();
    vi.useFakeTimers();

    // Mock localStorage
    localStorageMock = {};
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(
      (key) => localStorageMock[key] ?? null
    );
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(
      (key, value) => {
        localStorageMock[key] = value;
      }
    );

    // Re-initialize store for each test
    initReviewStore();
  });

  afterEach(() => {
    vi.restoreAllMocks();
    vi.useRealTimers();
  });

  // ===========================================================================
  // Store Initialization Tests
  // ===========================================================================

  describe('Store initialization', () => {
    it('registers store with Alpine', () => {
      expect(Alpine.store).toHaveBeenCalledWith('review', expect.any(Object));
    });

    it('initializes with default values', () => {
      const store = getReviewStore();

      expect(store.reviewKey).toBe('');
      expect(store.selection).toBe('');
      expect(store.reviewType).toBe(1);
      expect(store.isTableMode).toBe(false);
      expect(store.wordMode).toBe(false);
      expect(store.langId).toBe(0);
      expect(store.currentWord).toBeNull();
      expect(store.isLoading).toBe(false);
      expect(store.isFinished).toBe(false);
      expect(store.answerRevealed).toBe(false);
      expect(store.error).toBeNull();
      expect(store.isInitialized).toBe(false);
    });

    it('initializes progress to zero', () => {
      const store = getReviewStore();

      expect(store.progress.total).toBe(0);
      expect(store.progress.remaining).toBe(0);
      expect(store.progress.wrong).toBe(0);
      expect(store.progress.correct).toBe(0);
    });

    it('initializes timer state', () => {
      const store = getReviewStore();

      expect(store.timer.startTime).toBe(0);
      expect(store.timer.serverTime).toBe(0);
      expect(store.timer.elapsed).toBe('00:00');
      expect(store.timer.intervalId).toBeNull();
    });

    it('accepts initial values', () => {
      initReviewStore({ reviewType: 3, isTableMode: true });
      const store = getReviewStore();

      expect(store.reviewType).toBe(3);
      expect(store.isTableMode).toBe(true);
    });
  });

  // ===========================================================================
  // configure Tests
  // ===========================================================================

  describe('configure', () => {
    const mockConfig = {
      reviewKey: 'test-key',
      selection: 'selection-123',
      reviewType: 2,
      isTableMode: false,
      wordMode: true,
      langId: 5,
      wordRegex: '\\w+',
      property: 'text',
      title: 'Review Test',
      langSettings: {
        name: 'English',
        dict1Uri: 'http://dict1.com/lwt_term',
        dict2Uri: 'http://dict2.com/lwt_term',
        translateUri: 'http://trans.com/lwt_term',
        textSize: 120,
        rtl: false,
        langCode: 'en'
      },
      progress: {
        total: 100,
        remaining: 50,
        wrong: 10,
        correct: 40
      },
      timer: {
        startTime: 1000,
        serverTime: 1005
      }
    };

    it('sets all configuration values', () => {
      const store = getReviewStore();

      store.configure(mockConfig);

      expect(store.reviewKey).toBe('test-key');
      expect(store.selection).toBe('selection-123');
      expect(store.reviewType).toBe(2);
      expect(store.wordMode).toBe(true);
      expect(store.langId).toBe(5);
      expect(store.wordRegex).toBe('\\w+');
      expect(store.property).toBe('text');
      expect(store.title).toBe('Review Test');
    });

    it('sets language settings', () => {
      const store = getReviewStore();

      store.configure(mockConfig);

      expect(store.langSettings.name).toBe('English');
      expect(store.langSettings.dict1Uri).toBe('http://dict1.com/lwt_term');
      expect(store.langSettings.textSize).toBe(120);
    });

    it('sets progress from config', () => {
      const store = getReviewStore();

      store.configure(mockConfig);

      expect(store.progress.total).toBe(100);
      expect(store.progress.remaining).toBe(50);
      expect(store.progress.wrong).toBe(10);
      expect(store.progress.correct).toBe(40);
    });

    it('sets timer from config', () => {
      const store = getReviewStore();

      store.configure(mockConfig);

      expect(store.timer.startTime).toBe(1000);
      expect(store.timer.serverTime).toBe(1005);
    });

    it('loads readAloudEnabled from localStorage', () => {
      localStorageMock['lwt-review-read-aloud'] = 'true';
      const store = getReviewStore();

      store.configure(mockConfig);

      expect(store.readAloudEnabled).toBe(true);
    });

    it('sets isInitialized to true', () => {
      const store = getReviewStore();

      store.configure(mockConfig);

      expect(store.isInitialized).toBe(true);
    });

    it('starts the timer', () => {
      const store = getReviewStore();

      store.configure(mockConfig);

      expect(store.timer.intervalId).not.toBeNull();
    });
  });

  // ===========================================================================
  // nextWord Tests
  // ===========================================================================

  describe('nextWord', () => {
    it('sets isLoading during operation', async () => {
      const store = getReviewStore();
      mockReviewApi.getNextWord.mockResolvedValue({
        data: { term_id: 123, term_text: 'hello', solution: '', group: '' },
        error: undefined
      });

      const promise = store.nextWord();
      expect(store.isLoading).toBe(true);

      await promise;
      expect(store.isLoading).toBe(false);
    });

    it('does not fetch if already loading', async () => {
      const store = getReviewStore();
      store.isLoading = true;

      await store.nextWord();

      expect(mockReviewApi.getNextWord).not.toHaveBeenCalled();
    });

    it('resets state before fetching', async () => {
      const store = getReviewStore();
      store.answerRevealed = true;
      store.currentWord = { wordId: 1 } as never;
      store.error = 'old error';

      mockReviewApi.getNextWord.mockResolvedValue({
        data: { term_id: 123, term_text: 'hello', solution: '', group: '' },
        error: undefined
      });

      const promise = store.nextWord();
      expect(store.answerRevealed).toBe(false);
      expect(store.currentWord).toBeNull();
      expect(store.error).toBeNull();

      await promise;
    });

    it('loads word data successfully', async () => {
      const store = getReviewStore();
      store.reviewKey = 'key';
      store.selection = 'sel';
      store.langId = 1;
      store.reviewType = 2;

      mockReviewApi.getNextWord.mockResolvedValue({
        data: {
          term_id: 456,
          term_text: 'bonjour',
          solution: 'hello',
          group: 'A'
        },
        error: undefined
      });

      await store.nextWord();

      expect(store.currentWord).not.toBeNull();
      expect(store.currentWord?.wordId).toBe(456);
      expect(store.currentWord?.text).toBe('bonjour');
      expect(store.currentWord?.solution).toBe('hello');
      expect(store.currentWord?.group).toBe('A');
    });

    it('handles string term_id', async () => {
      const store = getReviewStore();
      mockReviewApi.getNextWord.mockResolvedValue({
        data: { term_id: '789', term_text: 'hello', solution: '', group: '' },
        error: undefined
      });

      await store.nextWord();

      expect(store.currentWord?.wordId).toBe(789);
    });

    it('sets isFinished when no more words', async () => {
      const store = getReviewStore();
      mockReviewApi.getNextWord.mockResolvedValue({
        data: { term_id: 0 },
        error: undefined
      });
      mockReviewApi.getTomorrowCount.mockResolvedValue({
        data: { count: 15 },
        error: undefined
      });

      await store.nextWord();

      expect(store.isFinished).toBe(true);
      expect(store.tomorrowCount).toBe(15);
    });

    it('stops timer when finished', async () => {
      const store = getReviewStore();
      store.timer.intervalId = 123;

      mockReviewApi.getNextWord.mockResolvedValue({
        data: null,
        error: undefined
      });
      mockReviewApi.getTomorrowCount.mockResolvedValue({
        data: { count: 0 },
        error: undefined
      });

      await store.nextWord();

      expect(store.isFinished).toBe(true);
    });

    it('sets error on API error', async () => {
      const store = getReviewStore();
      mockReviewApi.getNextWord.mockResolvedValue({
        data: null,
        error: 'Network error'
      });

      await store.nextWord();

      expect(store.error).toBe('Network error');
    });

    it('handles exceptions', async () => {
      const store = getReviewStore();
      const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
      mockReviewApi.getNextWord.mockRejectedValue(new Error('fail'));

      await store.nextWord();

      expect(store.error).toBe('Failed to load next word');
      expect(consoleSpy).toHaveBeenCalled();
    });
  });

  // ===========================================================================
  // revealAnswer Tests
  // ===========================================================================

  describe('revealAnswer', () => {
    it('sets answerRevealed to true', () => {
      const store = getReviewStore();
      store.currentWord = { wordId: 1 } as never;

      store.revealAnswer();

      expect(store.answerRevealed).toBe(true);
    });

    it('does nothing if already revealed', () => {
      const store = getReviewStore();
      store.currentWord = { wordId: 1 } as never;
      store.answerRevealed = true;

      store.revealAnswer();

      expect(store.answerRevealed).toBe(true);
    });

    it('does nothing without current word', () => {
      const store = getReviewStore();
      store.currentWord = null;

      store.revealAnswer();

      expect(store.answerRevealed).toBe(false);
    });
  });

  // ===========================================================================
  // updateStatus Tests
  // ===========================================================================

  describe('gradeAnswer', () => {
    beforeEach(() => {
      vi.spyOn(document, 'getElementById').mockReturnValue(null);
      mockReviewApi.getNextWord.mockResolvedValue({
        data: { term_id: 456, term_text: 'next', solution: '', group: '' },
        error: undefined
      });
      mockReviewApi.updateStatus.mockResolvedValue({ data: {}, error: undefined });
    });

    it('sends the grade rather than a status the client worked out', async () => {
      const store = getReviewStore();
      store.currentWord = { wordId: 123 } as never;
      store.answerRevealed = true;
      store.progress = { total: 10, remaining: 5, wrong: 0, correct: 0 };

      await store.gradeAnswer(2);

      expect(mockReviewApi.updateStatus).toHaveBeenCalledWith(123, undefined, undefined, 2);
    });

    it('counts Again as a failure and the rest as recalls', async () => {
      const store = getReviewStore();
      store.currentWord = { wordId: 1 } as never;
      store.answerRevealed = true;
      store.progress = { total: 10, remaining: 5, wrong: 0, correct: 0 };

      await store.gradeAnswer(1);
      expect(store.progress.wrong).toBe(1);
      expect(store.progress.correct).toBe(0);

      store.currentWord = { wordId: 2 } as never;
      store.answerRevealed = true;
      await store.gradeAnswer(2);
      expect(store.progress.wrong).toBe(1);
      expect(store.progress.correct).toBe(1);
    });

    it('does nothing before the answer is revealed', async () => {
      const store = getReviewStore();
      store.currentWord = { wordId: 1 } as never;
      store.answerRevealed = false;

      await store.gradeAnswer(3);

      expect(mockReviewApi.updateStatus).not.toHaveBeenCalled();
    });

    it('leaves the session tally alone when the server rejects the grade', async () => {
      const store = getReviewStore();
      store.currentWord = { wordId: 1 } as never;
      store.answerRevealed = true;
      store.progress = { total: 10, remaining: 5, wrong: 0, correct: 0 };
      mockReviewApi.updateStatus.mockResolvedValue({ error: 'Invalid grade value' });

      await store.gradeAnswer(3);

      expect(store.error).toBe('Invalid grade value');
      expect(store.progress.remaining).toBe(5);
      expect(store.progress.correct).toBe(0);
    });
  });

  describe('interval hints', () => {
    it('fetches what each grade would schedule when the answer is revealed', async () => {
      const store = getReviewStore();
      store.currentWord = { wordId: 42 } as never;
      mockReviewApi.getIntervals.mockResolvedValue({
        data: { intervals: { 1: 0, 2: 2, 3: 5, 4: 12 } },
        error: undefined
      });

      store.revealAnswer();

      expect(mockReviewApi.getIntervals).toHaveBeenCalledWith(42);
      await vi.waitFor(() => expect(store.intervals[3]).toBe(5));
    });

    it('drops hints that arrive after the user has moved on', async () => {
      const store = getReviewStore();
      store.currentWord = { wordId: 42 } as never;
      let resolveIntervals: (value: unknown) => void = () => {};
      mockReviewApi.getIntervals.mockReturnValue(
        new Promise((resolve) => { resolveIntervals = resolve; })
      );

      store.revealAnswer();
      store.currentWord = { wordId: 99 } as never;
      resolveIntervals({ data: { intervals: { 1: 0, 2: 2, 3: 5, 4: 12 } } });

      await Promise.resolve();
      expect(store.intervals).toEqual({});
    });
  });

  describe('updateStatus', () => {
    beforeEach(() => {
      // Mock audio elements
      vi.spyOn(document, 'getElementById').mockReturnValue(null);
    });

    it('does nothing without current word', async () => {
      const store = getReviewStore();
      store.currentWord = null;

      await store.updateStatus(3);

      expect(mockReviewApi.updateStatus).not.toHaveBeenCalled();
    });

    it('does nothing if already loading', async () => {
      const store = getReviewStore();
      store.currentWord = { wordId: 1 } as never;
      store.isLoading = true;

      await store.updateStatus(3);

      expect(mockReviewApi.updateStatus).not.toHaveBeenCalled();
    });

    it('calls API with correct parameters', async () => {
      const store = getReviewStore();
      store.currentWord = { wordId: 123 } as never;
      store.progress = { total: 10, remaining: 5, wrong: 0, correct: 0 };

      mockReviewApi.updateStatus.mockResolvedValue({ data: {}, error: undefined });
      mockReviewApi.getNextWord.mockResolvedValue({
        data: { term_id: 456, term_text: 'next', solution: '', group: '' },
        error: undefined
      });

      await store.updateStatus(3, true);

      expect(mockReviewApi.updateStatus).toHaveBeenCalledWith(123, 3);
    });

    it('updates progress on correct answer', async () => {
      const store = getReviewStore();
      store.currentWord = { wordId: 1 } as never;
      store.progress = { total: 10, remaining: 5, wrong: 0, correct: 0 };

      mockReviewApi.updateStatus.mockResolvedValue({ data: {}, error: undefined });
      mockReviewApi.getNextWord.mockResolvedValue({
        data: { term_id: 2, term_text: 'next', solution: '', group: '' },
        error: undefined
      });

      await store.updateStatus(3, true);

      expect(store.progress.remaining).toBe(4);
      expect(store.progress.correct).toBe(1);
      expect(store.progress.wrong).toBe(0);
    });

    it('updates progress on wrong answer', async () => {
      const store = getReviewStore();
      store.currentWord = { wordId: 1 } as never;
      store.progress = { total: 10, remaining: 5, wrong: 0, correct: 0 };

      mockReviewApi.updateStatus.mockResolvedValue({ data: {}, error: undefined });
      mockReviewApi.getNextWord.mockResolvedValue({
        data: { term_id: 2, term_text: 'next', solution: '', group: '' },
        error: undefined
      });

      await store.updateStatus(2, false);

      expect(store.progress.remaining).toBe(4);
      expect(store.progress.wrong).toBe(1);
      expect(store.progress.correct).toBe(0);
    });

    it('fetches next word after update', async () => {
      const store = getReviewStore();
      store.currentWord = { wordId: 1 } as never;
      store.progress = { total: 10, remaining: 5, wrong: 0, correct: 0 };

      mockReviewApi.updateStatus.mockResolvedValue({ data: {}, error: undefined });
      mockReviewApi.getNextWord.mockResolvedValue({
        data: { term_id: 2, term_text: 'next', solution: '', group: '' },
        error: undefined
      });

      await store.updateStatus(3);

      expect(mockReviewApi.getNextWord).toHaveBeenCalled();
    });

    it('sets error on API error', async () => {
      const store = getReviewStore();
      store.currentWord = { wordId: 1 } as never;
      store.progress = { total: 10, remaining: 5, wrong: 0, correct: 0 };

      mockReviewApi.updateStatus.mockResolvedValue({
        data: null,
        error: 'Failed to update'
      });

      await store.updateStatus(3);

      expect(store.error).toBe('Failed to update');
    });

    it('handles exceptions', async () => {
      const store = getReviewStore();
      store.currentWord = { wordId: 1 } as never;
      store.progress = { total: 10, remaining: 5, wrong: 0, correct: 0 };
      const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});

      mockReviewApi.updateStatus.mockRejectedValue(new Error('fail'));

      await store.updateStatus(3);

      expect(store.error).toBe('Failed to update status');
      expect(consoleSpy).toHaveBeenCalled();
    });
  });

  // ===========================================================================
  // incrementStatus / decrementStatus Tests
  // ===========================================================================

  describe('incrementStatus', () => {
    beforeEach(() => {
      vi.spyOn(document, 'getElementById').mockReturnValue(null);
    });

    it('does nothing without current word', async () => {
      const store = getReviewStore();
      store.currentWord = null;

      await store.incrementStatus();

      expect(mockReviewApi.updateStatus).not.toHaveBeenCalled();
    });

    it('does nothing if answer not revealed', async () => {
      const store = getReviewStore();
      store.currentWord = { wordId: 1, status: 2 } as never;
      store.answerRevealed = false;

      await store.incrementStatus();

      expect(mockReviewApi.updateStatus).not.toHaveBeenCalled();
    });

    it('increments status by 1', async () => {
      const store = getReviewStore();
      store.currentWord = { wordId: 1, status: 2 } as never;
      store.answerRevealed = true;
      store.progress = { total: 10, remaining: 5, wrong: 0, correct: 0 };

      mockReviewApi.updateStatus.mockResolvedValue({ data: {}, error: undefined });
      mockReviewApi.getNextWord.mockResolvedValue({
        data: { term_id: 2, term_text: 'next', solution: '', group: '' },
        error: undefined
      });

      await store.incrementStatus();

      expect(mockReviewApi.updateStatus).toHaveBeenCalledWith(1, 3);
    });

    it('caps status at 5', async () => {
      const store = getReviewStore();
      store.currentWord = { wordId: 1, status: 5 } as never;
      store.answerRevealed = true;
      store.progress = { total: 10, remaining: 5, wrong: 0, correct: 0 };

      mockReviewApi.updateStatus.mockResolvedValue({ data: {}, error: undefined });
      mockReviewApi.getNextWord.mockResolvedValue({
        data: { term_id: 2, term_text: 'next', solution: '', group: '' },
        error: undefined
      });

      await store.incrementStatus();

      expect(mockReviewApi.updateStatus).toHaveBeenCalledWith(1, 5);
    });
  });

  describe('decrementStatus', () => {
    beforeEach(() => {
      vi.spyOn(document, 'getElementById').mockReturnValue(null);
    });

    it('decrements status by 1', async () => {
      const store = getReviewStore();
      store.currentWord = { wordId: 1, status: 3 } as never;
      store.answerRevealed = true;
      store.progress = { total: 10, remaining: 5, wrong: 0, correct: 0 };

      mockReviewApi.updateStatus.mockResolvedValue({ data: {}, error: undefined });
      mockReviewApi.getNextWord.mockResolvedValue({
        data: { term_id: 2, term_text: 'next', solution: '', group: '' },
        error: undefined
      });

      await store.decrementStatus();

      expect(mockReviewApi.updateStatus).toHaveBeenCalledWith(1, 2);
    });

    it('floors status at 1', async () => {
      const store = getReviewStore();
      store.currentWord = { wordId: 1, status: 1 } as never;
      store.answerRevealed = true;
      store.progress = { total: 10, remaining: 5, wrong: 0, correct: 0 };

      mockReviewApi.updateStatus.mockResolvedValue({ data: {}, error: undefined });
      mockReviewApi.getNextWord.mockResolvedValue({
        data: { term_id: 2, term_text: 'next', solution: '', group: '' },
        error: undefined
      });

      await store.decrementStatus();

      expect(mockReviewApi.updateStatus).toHaveBeenCalledWith(1, 1);
    });
  });

  // ===========================================================================
  // skipWord Tests
  // ===========================================================================

  describe('skipWord', () => {
    beforeEach(() => {
      vi.spyOn(document, 'getElementById').mockReturnValue(null);
    });

    it('updates with same status', async () => {
      const store = getReviewStore();
      store.currentWord = { wordId: 1, status: 3 } as never;
      store.progress = { total: 10, remaining: 5, wrong: 0, correct: 0 };

      mockReviewApi.updateStatus.mockResolvedValue({ data: {}, error: undefined });
      mockReviewApi.getNextWord.mockResolvedValue({
        data: { term_id: 2, term_text: 'next', solution: '', group: '' },
        error: undefined
      });

      await store.skipWord();

      expect(mockReviewApi.updateStatus).toHaveBeenCalledWith(1, 3);
    });

    it('does nothing if loading', async () => {
      const store = getReviewStore();
      store.currentWord = { wordId: 1, status: 3 } as never;
      store.isLoading = true;

      await store.skipWord();

      expect(mockReviewApi.updateStatus).not.toHaveBeenCalled();
    });
  });

  // ===========================================================================
  // Timer Tests
  // ===========================================================================

  describe('startTimer', () => {
    it('starts interval timer', () => {
      const store = getReviewStore();
      store.timer.startTime = 1000;
      store.timer.serverTime = 1000;

      store.startTimer();

      expect(store.timer.intervalId).not.toBeNull();
    });

    it('does not start if already running', () => {
      const store = getReviewStore();
      store.timer.intervalId = 123;

      store.startTimer();

      expect(store.timer.intervalId).toBe(123);
    });

    it('updates elapsed time', () => {
      const store = getReviewStore();
      const now = Math.floor(Date.now() / 1000);
      store.timer.startTime = now - 65; // 65 seconds ago
      store.timer.serverTime = now;

      store.startTimer();

      expect(store.timer.elapsed).toBe('01:05');
    });
  });

  describe('stopTimer', () => {
    it('clears interval', () => {
      const store = getReviewStore();
      store.startTimer();

      store.stopTimer();

      expect(store.timer.intervalId).toBeNull();
    });

    it('does nothing if not running', () => {
      const store = getReviewStore();
      store.timer.intervalId = null;

      store.stopTimer(); // Should not throw

      expect(store.timer.intervalId).toBeNull();
    });
  });

  describe('formatElapsed', () => {
    it('formats seconds as MM:SS', () => {
      const store = getReviewStore();

      expect(store.formatElapsed(0)).toBe('00:00');
      expect(store.formatElapsed(5)).toBe('00:05');
      expect(store.formatElapsed(65)).toBe('01:05');
      expect(store.formatElapsed(600)).toBe('10:00');
      expect(store.formatElapsed(3599)).toBe('59:59');
    });

    it('formats as HH:MM:SS for hour or more', () => {
      const store = getReviewStore();

      expect(store.formatElapsed(3600)).toBe('01:00:00');
      expect(store.formatElapsed(3665)).toBe('01:01:05');
      expect(store.formatElapsed(7200)).toBe('02:00:00');
    });
  });

  // ===========================================================================
  // getDictUrl Tests
  // ===========================================================================

  describe('getDictUrl', () => {
    it('returns # without current word', () => {
      const store = getReviewStore();
      store.currentWord = null;

      expect(store.getDictUrl('dict1')).toBe('#');
    });

    it('returns # without template', () => {
      const store = getReviewStore();
      store.currentWord = { text: 'hello' } as never;
      store.langSettings.dict1Uri = '';

      expect(store.getDictUrl('dict1')).toBe('#');
    });

    it('replaces lwt_term with encoded word', () => {
      const store = getReviewStore();
      store.currentWord = { text: 'café' } as never;
      store.langSettings.dict1Uri = 'http://dict.com/lwt_term';

      expect(store.getDictUrl('dict1')).toBe('http://dict.com/caf%C3%A9');
    });

    it('returns correct URL for each dictionary type', () => {
      const store = getReviewStore();
      store.currentWord = { text: 'hello' } as never;
      store.langSettings.dict1Uri = 'http://dict1.com/lwt_term';
      store.langSettings.dict2Uri = 'http://dict2.com/lwt_term';
      store.langSettings.translateUri = 'http://trans.com/lwt_term';

      expect(store.getDictUrl('dict1')).toBe('http://dict1.com/hello');
      expect(store.getDictUrl('dict2')).toBe('http://dict2.com/hello');
      expect(store.getDictUrl('translator')).toBe('http://trans.com/hello');
    });
  });

  // ===========================================================================
  // Modal Tests
  // ===========================================================================

  describe('openModal', () => {
    it('sets isModalOpen to true', () => {
      const store = getReviewStore();

      store.openModal();

      expect(store.isModalOpen).toBe(true);
    });
  });

  describe('closeModal', () => {
    it('sets isModalOpen to false', () => {
      const store = getReviewStore();
      store.isModalOpen = true;

      store.closeModal();

      expect(store.isModalOpen).toBe(false);
    });
  });

  // ===========================================================================
  // playSound Tests
  // ===========================================================================

  describe('playSound', () => {
    it('plays success sound for correct answer', () => {
      const store = getReviewStore();
      const mockAudio = {
        currentTime: 10,
        pause: vi.fn(),
        play: vi.fn().mockResolvedValue(undefined)
      };
      vi.spyOn(document, 'getElementById').mockImplementation((id) => {
        if (id === 'success_sound') return mockAudio as unknown as HTMLElement;
        return null;
      });

      store.playSound(true);

      expect(mockAudio.currentTime).toBe(0);
      expect(mockAudio.play).toHaveBeenCalled();
    });

    it('plays failure sound for incorrect answer', () => {
      const store = getReviewStore();
      const mockAudio = {
        currentTime: 10,
        pause: vi.fn(),
        play: vi.fn().mockResolvedValue(undefined)
      };
      vi.spyOn(document, 'getElementById').mockImplementation((id) => {
        if (id === 'failure_sound') return mockAudio as unknown as HTMLElement;
        return null;
      });

      store.playSound(false);

      expect(mockAudio.currentTime).toBe(0);
      expect(mockAudio.play).toHaveBeenCalled();
    });

    it('handles missing audio element', () => {
      const store = getReviewStore();
      vi.spyOn(document, 'getElementById').mockReturnValue(null);

      // Should not throw
      store.playSound(true);
    });

    it('handles play error silently', () => {
      const store = getReviewStore();
      const mockAudio = {
        currentTime: 0,
        pause: vi.fn(),
        play: vi.fn().mockRejectedValue(new Error('Autoplay blocked'))
      };
      vi.spyOn(document, 'getElementById').mockReturnValue(
        mockAudio as unknown as HTMLElement
      );

      // Should not throw
      store.playSound(true);
    });
  });

  // ===========================================================================
  // setReadAloud Tests
  // ===========================================================================

  describe('setReadAloud', () => {
    it('sets readAloudEnabled', () => {
      const store = getReviewStore();

      store.setReadAloud(true);

      expect(store.readAloudEnabled).toBe(true);
    });

    it('saves to localStorage', () => {
      const store = getReviewStore();

      store.setReadAloud(true);

      expect(localStorage.setItem).toHaveBeenCalledWith(
        'lwt-review-read-aloud',
        'true'
      );
    });

    it('saves false to localStorage', () => {
      const store = getReviewStore();

      store.setReadAloud(false);

      expect(localStorage.setItem).toHaveBeenCalledWith(
        'lwt-review-read-aloud',
        'false'
      );
    });
  });

  // ===========================================================================
  // isReviewStoreInitialized Tests
  // ===========================================================================

  describe('isReviewStoreInitialized', () => {
    it('returns true when store exists', () => {
      expect(isReviewStoreInitialized()).toBe(true);
    });
  });

  // ===========================================================================
  // Window Export Tests
  // ===========================================================================

  describe('Window Exports', () => {
    it('exposes getReviewStore on window', () => {
      expect(window.getReviewStore).toBeDefined();
    });
  });
});
