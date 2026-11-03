/**
 * Streak Display Component - Shows current/best streak and today's summary.
 *
 * Fetches the combined dashboard endpoint and populates reactive state.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.0.0
 */

import Alpine from 'alpinejs';
import { t, tn } from '@shared/i18n/translator';

interface DashboardResponse {
  streak: {
    current_streak: number;
    best_streak: number;
    total_active_days: number;
  };
  today: {
    terms_created: number;
    terms_reviewed: number;
    texts_read: number;
  };
}

interface StreakDisplayState {
  currentStreak: number;
  bestStreak: number;
  totalActiveDays: number;
  todayCreated: number;
  todayReviewed: number;
  todayTextsRead: number;
  loading: boolean;
  error: string;
  init(): void;
  fetchData(): void;
  streakLabel(n: number): string;
}

/**
 * Alpine.js component for the streak and today's summary display.
 */
export function streakDisplay(): StreakDisplayState {
  return {
    currentStreak: 0,
    bestStreak: 0,
    totalActiveDays: 0,
    todayCreated: 0,
    todayReviewed: 0,
    todayTextsRead: 0,
    loading: true,
    error: '',

    init() {
      this.fetchData();
    },

    fetchData() {
      fetch('/api/v1/activity/dashboard')
        .then(r => r.json())
        .then((data: DashboardResponse) => {
          this.loading = false;
          if (data.streak) {
            this.currentStreak = data.streak.current_streak;
            this.bestStreak = data.streak.best_streak;
            this.totalActiveDays = data.streak.total_active_days;
          }
          if (data.today) {
            this.todayCreated = data.today.terms_created;
            this.todayReviewed = data.today.terms_reviewed;
            this.todayTextsRead = data.today.texts_read;
          }
        })
        .catch(() => {
          this.loading = false;
          this.error = t('user.statistics.load_failed');
        });
    },

    streakLabel(n: number): string {
      return tn('user.statistics.streak_days', n);
    },
  };
}

Alpine.data('streakDisplay', streakDisplay);
