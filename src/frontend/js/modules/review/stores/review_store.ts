/**
 * Review Store - Alpine.js store for vocabulary review state management.
 *
 * Provides centralized state management for the review interface including
 * current word, progress tracking, timer, and UI state.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.0.0
 */

import Alpine from 'alpinejs';
import { ReviewApi } from '@modules/review/api/review_api';
import type { ReviewGrade } from '@modules/review/api/review_api';
import { successSound, failureSound } from '@shared/utils/audio_feedback';
import { openTermEditModal } from '@modules/vocabulary/components/term_edit_modal';

/**
 * Language settings for the review.
 */
export interface LangSettings {
  name: string;
  dict1Uri: string;
  dict2Uri: string;
  translateUri: string;
  textSize: number;
  rtl: boolean;
  langCode: string;
}

/**
 * Current word being reviewed.
 */
export interface ReviewWord {
  wordId: number;
  text: string;
  translation: string;
  romanization: string;
  status: number;
  sentence: string;
  solution: string;
  group: string;
}

/**
 * Review progress tracking.
 */
export interface ReviewProgress {
  total: number;
  remaining: number;
  wrong: number;
  correct: number;
}

/**
 * Timer state.
 */
export interface ReviewTimer {
  startTime: number;
  serverTime: number;
  elapsed: string;
  intervalId: number | null;
}

/**
 * Review configuration from server.
 */
export interface ReviewConfig {
  reviewKey: string;
  selection: string;
  reviewType: number;
  isTableMode: boolean;
  wordMode: boolean;
  langId: number;
  error?: string;
  wordRegex: string;
  langSettings: LangSettings;
  progress: ReviewProgress;
  timer: {
    startTime: number;
    serverTime: number;
  };
  title: string;
  property: string;
}

/**
 * Review store state interface.
 */
export interface ReviewStoreState {
  // Review configuration
  reviewKey: string;
  selection: string;
  reviewType: number;
  isTableMode: boolean;
  wordMode: boolean;
  langId: number;
  wordRegex: string;
  property: string;
  title: string;

  // Language settings
  langSettings: LangSettings;

  // Current word being reviewed
  currentWord: ReviewWord | null;

  // Progress tracking
  progress: ReviewProgress;

  // Timer
  timer: ReviewTimer;

  // UI state
  isLoading: boolean;
  isFinished: boolean;
  answerRevealed: boolean;
  isModalOpen: boolean;
  readAloudEnabled: boolean;
  tomorrowCount: number;
  error: string | null;
  isInitialized: boolean;

  // What each grade would schedule for the current word, in whole days.
  // Empty until the answer is revealed, since that is when the hints show.
  intervals: Record<string, number>;

  // Methods
  configure(config: ReviewConfig): void;
  nextWord(): Promise<void>;
  revealAnswer(): void;
  updateStatus(status: number, isCorrect?: boolean): Promise<void>;
  gradeAnswer(grade: ReviewGrade): Promise<void>;
  advanceAfterAnswer(isCorrect: boolean): Promise<void>;
  incrementStatus(): Promise<void>;
  decrementStatus(): Promise<void>;
  skipWord(): Promise<void>;
  startTimer(): void;
  stopTimer(): void;
  formatElapsed(seconds: number): string;
  getDictUrl(which: 'dict1' | 'dict2' | 'translator'): string;
  hasDictUrl(which: 'dict1' | 'dict2' | 'translator'): boolean;
  editWord(wordId: number): void;
  editCurrentWord(): void;
  openModal(): void;
  closeModal(): void;
  playSound(correct: boolean): void;
  setReadAloud(enabled: boolean): void;
}

/**
 * Calculate new status based on current status and change direction.
 */
function calculateNewStatus(currentStatus: number, change: number): number {
  let newStatus = currentStatus + change;

  // Clamp to valid range (1-5)
  if (newStatus < 1) newStatus = 1;
  if (newStatus > 5) newStatus = 5;

  return newStatus;
}

/**
 * Initial values that can be set before store creation.
 */
interface ReviewStoreInitialValues {
  reviewType?: number;
  isTableMode?: boolean;
}

/**
 * Create the review store data object.
 */
function createReviewStore(initialValues?: ReviewStoreInitialValues): ReviewStoreState {
  return {
    // Review configuration
    reviewKey: '',
    selection: '',
    reviewType: initialValues?.reviewType ?? 1,
    isTableMode: initialValues?.isTableMode ?? false,
    wordMode: false,
    langId: 0,
    wordRegex: '',
    property: '',
    title: '',

    // Language settings
    langSettings: {
      name: '',
      dict1Uri: '',
      dict2Uri: '',
      translateUri: '',
      textSize: 100,
      rtl: false,
      langCode: ''
    },

    // Current word being reviewed
    currentWord: null,

    // Progress tracking
    progress: {
      total: 0,
      remaining: 0,
      wrong: 0,
      correct: 0
    },

    // Timer
    timer: {
      startTime: 0,
      serverTime: 0,
      elapsed: '00:00',
      intervalId: null
    },

    // UI state
    isLoading: false,
    isFinished: false,
    answerRevealed: false,
    intervals: {},
    isModalOpen: false,
    readAloudEnabled: false,
    tomorrowCount: 0,
    error: null,
    isInitialized: false,

    /**
     * Configure the store with settings from server.
     * Note: Named 'configure' instead of 'init' because Alpine auto-calls init() on stores.
     */
    configure(config: ReviewConfig): void {
      this.reviewKey = config.reviewKey;
      this.selection = config.selection;
      this.reviewType = config.reviewType;
      this.isTableMode = config.isTableMode;
      this.wordMode = config.wordMode;
      this.langId = config.langId;
      this.wordRegex = config.wordRegex;
      this.property = config.property;
      this.title = config.title;
      this.langSettings = config.langSettings;
      this.progress = { ...config.progress };
      this.timer.startTime = config.timer.startTime;
      this.timer.serverTime = config.timer.serverTime;

      // Load read aloud preference from localStorage
      const savedReadAloud = localStorage.getItem('lwt-review-read-aloud');
      if (savedReadAloud !== null) {
        this.readAloudEnabled = savedReadAloud === 'true';
      }

      this.isInitialized = true;
      this.startTimer();
    },

    /**
     * Fetch and display the next word.
     */
    async nextWord(): Promise<void> {
      if (this.isLoading) return;

      this.isLoading = true;
      this.answerRevealed = false;
      this.currentWord = null;
      this.intervals = {};
      this.error = null;

      try {
        const response = await ReviewApi.getNextWord({
          reviewKey: this.reviewKey,
          selection: this.selection,
          wordMode: this.wordMode,
          lgId: this.langId,
          wordRegex: this.wordRegex,
          type: this.reviewType
        });

        if (response.error) {
          this.error = response.error;
          this.isLoading = false;
          return;
        }

        if (!response.data || response.data.term_id === 0) {
          // No more words to test
          this.isFinished = true;
          this.stopTimer();

          // Fetch tomorrow count
          const tomorrowResponse = await ReviewApi.getTomorrowCount(
            this.reviewKey,
            this.selection
          );
          if (tomorrowResponse.data?.count) {
            this.tomorrowCount = tomorrowResponse.data.count;
          }
        } else {
          const data = response.data;
          this.currentWord = {
            wordId: typeof data.term_id === 'string'
              ? parseInt(data.term_id, 10)
              : data.term_id,
            text: data.term_text,
            translation: '', // Will be revealed with answer
            romanization: '',
            status: 1,
            sentence: '',
            solution: data.solution || '',
            group: data.group
          };
        }
      } catch (err) {
        console.error('Error fetching next word:', err);
        this.error = 'Failed to load next word';
      }

      this.isLoading = false;
    },

    /**
     * Reveal the answer for the current word.
     */
    revealAnswer(): void {
      if (this.answerRevealed || !this.currentWord) return;
      this.answerRevealed = true;

      // Fetch the hints in the background: the grade buttons appear straight
      // away and show their interval when it arrives, rather than making the
      // user wait on a round-trip to answer.
      const wordId = this.currentWord.wordId;
      void ReviewApi.getIntervals(wordId).then((response) => {
        if (this.currentWord?.wordId !== wordId) return;
        this.intervals = response.data?.intervals ?? {};
      });
    },

    /**
     * Update the status of the current word.
     *
     * @param status    New status value (1-5)
     * @param isCorrect Whether the user answered correctly (true=knew it, false=didn't know)
     */
    async updateStatus(status: number, isCorrect: boolean = true): Promise<void> {
      if (!this.currentWord || this.isLoading) return;

      this.isLoading = true;

      try {
        const response = await ReviewApi.updateStatus(
          this.currentWord.wordId,
          status
        );

        if (response.error) {
          this.error = response.error;
          this.isLoading = false;
          return;
        }

        await this.advanceAfterAnswer(isCorrect);
      } catch (err) {
        console.error('Error updating status:', err);
        this.error = 'Failed to update status';
        this.isLoading = false;
      }
    },

    /**
     * Grade the current word and move on.
     *
     * Unlike updateStatus(), which sends a status the client worked out for
     * itself, this sends the grade and lets the server do both halves: nudge
     * WoStatus the same +-1 as before, and feed the term's FSRS schedule.
     * Anything but Again counts as a recall for the session tally.
     *
     * @param grade 1 Again, 2 Hard, 3 Good, 4 Easy
     */
    async gradeAnswer(grade: ReviewGrade): Promise<void> {
      if (!this.currentWord || !this.answerRevealed || this.isLoading) return;

      this.isLoading = true;

      try {
        const response = await ReviewApi.updateStatus(
          this.currentWord.wordId,
          undefined,
          undefined,
          grade
        );

        if (response.error) {
          this.error = response.error;
          this.isLoading = false;
          return;
        }

        await this.advanceAfterAnswer(grade !== 1);
      } catch (err) {
        console.error('Error grading answer:', err);
        this.error = 'Failed to update status';
        this.isLoading = false;
      }
    },

    /**
     * Record an answer against the session tally and move to the next word.
     *
     * @param isCorrect Whether the user recalled the term
     */
    async advanceAfterAnswer(isCorrect: boolean): Promise<void> {
      // Update progress
      this.progress.remaining--;
      if (isCorrect) {
        this.progress.correct++;
      } else {
        this.progress.wrong++;
      }

      // Play feedback sound
      this.playSound(isCorrect);

      // Reset loading state before fetching next word
      // (nextWord() checks isLoading and returns early if true)
      this.isLoading = false;

      // Fetch next word
      await this.nextWord();
    },

    /**
     * Increment the current word's status.
     */
    async incrementStatus(): Promise<void> {
      if (!this.currentWord || !this.answerRevealed) return;

      const newStatus = calculateNewStatus(this.currentWord.status, 1);
      await this.updateStatus(newStatus, true);
    },

    /**
     * Decrement the current word's status.
     */
    async decrementStatus(): Promise<void> {
      if (!this.currentWord || !this.answerRevealed) return;

      const newStatus = calculateNewStatus(this.currentWord.status, -1);
      await this.updateStatus(newStatus, false);
    },

    /**
     * Skip the current word without changing its status.
     */
    async skipWord(): Promise<void> {
      if (!this.currentWord || this.isLoading) return;

      // Update with same status (no change)
      await this.updateStatus(this.currentWord.status);
    },

    /**
     * Start the elapsed timer.
     */
    startTimer(): void {
      if (this.timer.intervalId !== null) return;

      const updateTimer = () => {
        const now = Math.floor(Date.now() / 1000);
        const clientOffset = now - this.timer.serverTime;
        const elapsed = now - this.timer.startTime - clientOffset;
        this.timer.elapsed = this.formatElapsed(Math.max(0, elapsed));
      };

      // Update immediately
      updateTimer();

      // Then update every second
      this.timer.intervalId = window.setInterval(updateTimer, 1000);
    },

    /**
     * Stop the elapsed timer.
     */
    stopTimer(): void {
      if (this.timer.intervalId !== null) {
        window.clearInterval(this.timer.intervalId);
        this.timer.intervalId = null;
      }
    },

    /**
     * Format seconds as MM:SS or HH:MM:SS.
     */
    formatElapsed(seconds: number): string {
      const hours = Math.floor(seconds / 3600);
      const minutes = Math.floor((seconds % 3600) / 60);
      const secs = seconds % 60;

      const pad = (n: number) => n.toString().padStart(2, '0');

      if (hours > 0) {
        return `${pad(hours)}:${pad(minutes)}:${pad(secs)}`;
      }
      return `${pad(minutes)}:${pad(secs)}`;
    },

    /**
     * Get dictionary URL for the current word.
     */
    getDictUrl(which: 'dict1' | 'dict2' | 'translator'): string {
      if (!this.currentWord) return '#';

      let template = '';
      switch (which) {
        case 'dict1':
          template = this.langSettings.dict1Uri;
          break;
        case 'dict2':
          template = this.langSettings.dict2Uri;
          break;
        case 'translator':
          template = this.langSettings.translateUri;
          break;
      }

      if (!template) return '#';

      return template.replace('lwt_term', encodeURIComponent(this.currentWord.text));
    },

    /**
     * Check if a dictionary URL is configured.
     */
    hasDictUrl(which: 'dict1' | 'dict2' | 'translator'): boolean {
      switch (which) {
        case 'dict1': return !!this.langSettings.dict1Uri;
        case 'dict2': return !!this.langSettings.dict2Uri;
        case 'translator': return !!this.langSettings.translateUri;
      }
    },

    /**
     * Open the term editor for a word in the review table.
     *
     * The term already knows its language, so no reading context is needed.
     *
     * @param wordId Term ID
     */
    editWord(wordId: number): void {
      void openTermEditModal(0, 0, wordId);
    },

    /**
     * Open the term editor for the word currently under review.
     */
    editCurrentWord(): void {
      if (!this.currentWord) return;
      this.closeModal();
      void openTermEditModal(0, 0, this.currentWord.wordId);
    },

    /**
     * Open the word details modal.
     */
    openModal(): void {
      this.isModalOpen = true;
    },

    /**
     * Close the word details modal.
     */
    closeModal(): void {
      this.isModalOpen = false;
    },

    /**
     * Play success or failure sound.
     */
    playSound(correct: boolean): void {
      const play = correct ? successSound : failureSound;
      play().catch(() => {
        // Ignore autoplay errors
      });
    },

    /**
     * Set read aloud preference (CSP-compatible setter).
     */
    setReadAloud(enabled: boolean): void {
      this.readAloudEnabled = enabled;
      localStorage.setItem('lwt-review-read-aloud', String(enabled));
    }
  };
}

/**
 * Initialize the review store as an Alpine.js store.
 *
 * @param initialValues Optional initial values to set before Alpine renders
 */
export function initReviewStore(initialValues?: ReviewStoreInitialValues): void {
  Alpine.store('review', createReviewStore(initialValues));
}

/**
 * Get the review store instance.
 */
export function getReviewStore(): ReviewStoreState {
  return Alpine.store('review') as ReviewStoreState;
}

/**
 * Check if the review store has been initialized.
 */
export function isReviewStoreInitialized(): boolean {
  try {
    return Alpine.store('review') !== undefined;
  } catch {
    return false;
  }
}

// Register the store with defaults (will be re-initialized with config in initReviewApp)
initReviewStore();

// Expose for global access
declare global {
  interface Window {
    getReviewStore: typeof getReviewStore;
  }
}

window.getReviewStore = getReviewStore;
