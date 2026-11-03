/**
 * The "sync reviews with Anki" page (#264, steps 1 and 2).
 *
 * Reads LWT's notes and their reviews from the learner's Anki over
 * AnkiConnect and posts the reviews to LWT, which merges them into each
 * term's history and drops any it already has. LWT then answers with what
 * Anki is missing -- its own reviews and the resulting schedule -- and this
 * writes that to Anki. Running it twice in a row changes nothing.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.8.0
 */

import Alpine from 'alpinejs';
import { apiPost } from '@shared/api/client';
import { t, tn } from '@shared/i18n/translator';
import { readPageConfig } from '@shared/utils/page_config';
import {
  AnkiConnect,
  AnkiConnectError,
  type CardHistory,
  type PulledReview,
  type PushPlan,
} from '../services/anki_connect';

interface AnkiSyncConfig {
  ankiConnectUrl: string;
  noteType: string;
  idField: string;
  batchSize: number;
}

/** What POST /anki/reviews reports, summed over the batches. */
export interface PullSummary {
  terms: number;
  applied: number;
  duplicates: number;
  rebuilt: number;
  skipped: number;
}

/** What the sync did in both directions. */
export interface SyncSummary extends PullSummary {
  /** LWT reviews written to Anki. */
  pushed: number;
  /** Anki cards given LWT's schedule. */
  cardsRescheduled: number;
}

const EMPTY: PullSummary = { terms: 0, applied: 0, duplicates: 0, rebuilt: 0, skipped: 0 };

/** Cards per push-plan request, matching AnkiPushPlan::MAX_CARDS. */
const PLAN_BATCH = 2000;

/**
 * Ask LWT what Anki is missing, in batches, as one plan.
 */
export async function planPush(cards: CardHistory[]): Promise<PushPlan> {
  const plan: PushPlan = { reviews: [], cards: [], skipped: 0 };
  for (let i = 0; i < cards.length; i += PLAN_BATCH) {
    const response = await apiPost<PushPlan>('/anki/push-plan', { cards: cards.slice(i, i + PLAN_BATCH) });
    if (response.error || !response.data) {
      throw new Error(response.error ?? 'Empty response');
    }
    plan.reviews.push(...response.data.reviews);
    plan.cards.push(...response.data.cards);
    plan.skipped += response.data.skipped;
  }
  return plan;
}

/**
 * Post reviews to LWT in batches and add up what it did.
 */
export async function sendReviews(
  reviews: PulledReview[],
  batchSize: number,
  onBatch: (done: number, total: number) => void = () => {}
): Promise<PullSummary> {
  const total: PullSummary = { ...EMPTY };
  for (let i = 0; i < reviews.length; i += batchSize) {
    const response = await apiPost<PullSummary>('/anki/reviews', {
      reviews: reviews.slice(i, i + batchSize),
    });
    if (response.error || !response.data) {
      throw new Error(response.error ?? 'Empty response');
    }
    for (const key of Object.keys(total) as (keyof PullSummary)[]) {
      total[key] += response.data[key] ?? 0;
    }
    onBatch(Math.min(i + batchSize, reviews.length), reviews.length);
  }
  return total;
}

export function ankiSync() {
  const config = readPageConfig<AnkiSyncConfig>('anki-sync-config', {
    ankiConnectUrl: 'http://127.0.0.1:8765',
    noteType: 'LWT Term',
    idField: 'LwtId',
    batchSize: 5000,
  });

  return {
    busy: false,
    progress: '',
    error: '',
    summary: null as SyncSummary | null,

    async pull(): Promise<void> {
      this.busy = true;
      this.error = '';
      this.summary = null;
      this.progress = t('vocabulary.anki.sync.connecting');

      try {
        const anki = new AnkiConnect(config.ankiConnectUrl);
        if (!(await anki.requestPermission())) {
          throw new AnkiConnectError(t('vocabulary.anki.sync.error_denied'));
        }

        const { reviews, cards } = await anki.pullReviews(config.noteType, config.idField, (step, done, total) => {
          this.progress = t(`vocabulary.anki.sync.reading_${step}`, { done, total });
        });

        // Anki -> LWT first, so the plan below is computed from both histories.
        const pulled = await sendReviews(reviews, config.batchSize, (done, total) => {
          this.progress = t('vocabulary.anki.sync.sending', { done, total });
        });

        // LWT -> Anki: the reviews Anki lacks, and the schedule they lead to.
        const plan = await planPush(cards);
        const written = await anki.applyPushPlan(plan, (done, total) => {
          this.progress = t('vocabulary.anki.sync.writing', { done, total });
        });

        this.summary = { ...pulled, pushed: written.reviews, cardsRescheduled: written.cards };
        this.progress = '';
      } catch (err) {
        this.progress = '';
        this.error = err instanceof AnkiConnectError && err.unreachable
          ? t('vocabulary.anki.sync.error_unreachable', { url: config.ankiConnectUrl })
          : t('vocabulary.anki.sync.error_failed', { message: err instanceof Error ? err.message : String(err) });
      } finally {
        this.busy = false;
      }
    },

    appliedLabel(): string {
      return tn('vocabulary.anki.sync.applied', this.summary?.applied ?? 0);
    },
    termsLabel(): string {
      return tn('vocabulary.anki.sync.terms', this.summary?.terms ?? 0);
    },
    duplicatesLabel(): string {
      return tn('vocabulary.anki.sync.duplicates', this.summary?.duplicates ?? 0);
    },
    rebuiltLabel(): string {
      return tn('vocabulary.anki.sync.rebuilt', this.summary?.rebuilt ?? 0);
    },
    pushedLabel(): string {
      return tn('vocabulary.anki.sync.pushed', this.summary?.pushed ?? 0);
    },
    cardsLabel(): string {
      return tn('vocabulary.anki.sync.cards', this.summary?.cardsRescheduled ?? 0);
    },
    skippedLabel(): string {
      return tn('vocabulary.anki.sync.skipped', this.summary?.skipped ?? 0);
    },
    hasRebuilt(): boolean {
      return (this.summary?.rebuilt ?? 0) > 0;
    },
    hasSkipped(): boolean {
      return (this.summary?.skipped ?? 0) > 0;
    },
  };
}

Alpine.data('ankiSync', ankiSync);
