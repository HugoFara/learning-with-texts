/**
 * The "pull reviews from Anki" page (#264, step 1).
 *
 * Reads LWT's notes and their reviews from the learner's Anki over
 * AnkiConnect, then posts the reviews to LWT in batches. The server merges
 * them into each term's history and drops any it already has, so pulling the
 * same collection twice is harmless.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.8.0
 */

import Alpine from 'alpinejs';
import { apiPost } from '@shared/api/client';
import { t, tn } from '@shared/i18n/translator';
import { readPageConfig } from '@shared/utils/page_config';
import { AnkiConnect, AnkiConnectError, type PulledReview } from '../services/anki_connect';

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

const EMPTY: PullSummary = { terms: 0, applied: 0, duplicates: 0, rebuilt: 0, skipped: 0 };

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
    summary: null as PullSummary | null,

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

        const reviews = await anki.pullReviews(config.noteType, config.idField, (step, done, total) => {
          this.progress = t(`vocabulary.anki.sync.reading_${step}`, { done, total });
        });

        this.summary = await sendReviews(reviews, config.batchSize, (done, total) => {
          this.progress = t('vocabulary.anki.sync.sending', { done, total });
        });
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
