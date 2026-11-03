/**
 * The "sync with Anki" page (#264, steps 1 to 3).
 *
 * Reads LWT's notes and their reviews from the learner's Anki over
 * AnkiConnect and posts the reviews to LWT, which merges them into each
 * term's history and drops any it already has. LWT then answers with what
 * Anki is missing -- its own reviews and the resulting schedule -- and this
 * writes that to Anki. Then the notes themselves: fields, tags, suspension,
 * additions and deletions merge against what both sides agreed on last time
 * (see AnkiNoteSync). Deletions wait for the learner to confirm them, and
 * conflicting edits are listed rather than decided. Running it twice in a row
 * changes nothing.
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
  type NotePlan,
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
  /** Field, tag and suspension changes written to Anki. */
  notesToAnki: number;
  /** Field, tag and suspension changes written to LWT. */
  notesToLwt: number;
  /** LWT terms added to Anki as notes. */
  addedToAnki: number;
  /** Anki notes added to LWT as terms. */
  addedToLwt: number;
  /** Terms and notes deleted after confirmation. */
  deleted: number;
}

/** Deletions waiting for the learner's yes. */
interface PendingDeletion {
  text: string;
  side: 'lwt' | 'anki';
}

/**
 * The latest answer of each card, epoch ms: what the note sync weighs a
 * deletion against.
 */
export function lastReviews(cards: CardHistory[]): Map<number, number> {
  const latest = new Map<number, number>();
  for (const card of cards) {
    const answers = card.reviews.filter(([, ease]) => ease > 0).map(([id]) => id);
    if (answers.length > 0) latest.set(card.card_id, Math.max(...answers));
  }
  return latest;
}

async function post<T>(path: string, body: Record<string, unknown>): Promise<T> {
  const response = await apiPost<T>(path, body);
  if (response.error || !response.data) {
    throw new Error(response.error ?? 'Empty response');
  }
  return response.data;
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
    conflicts: [] as NotePlan['conflicts'],
    pendingDeletions: [] as PendingDeletion[],

    async pull(): Promise<void> {
      await this.sync(false);
    },

    async confirmDeletions(): Promise<void> {
      await this.sync(true);
    },

    async sync(confirmDeletions: boolean): Promise<void> {
      this.busy = true;
      this.error = '';
      this.summary = null;
      this.conflicts = [];
      this.pendingDeletions = [];
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

        // The notes: fields, tags, suspension, additions, deletions.
        this.progress = t('vocabulary.anki.sync.reading_notes', { done: 0, total: 0 });
        const { notes, decks } = await anki.readNotes(config.noteType, lastReviews(cards));
        const notePlan = await post<NotePlan>('/anki/notes-plan', { notes, decks });
        this.progress = t('vocabulary.anki.sync.writing', { done: 0, total: notePlan.summary.to_anki });
        const added = await anki.applyNotePlan(notePlan, config.noteType, confirmDeletions);
        const committed = await post<{ created: { note_id: number; term_id: number }[]; deleted: number }>(
          '/anki/notes-commit',
          { notes, decks, added, confirm_deletions: confirmDeletions }
        );
        await anki.writeLwtIds(committed.created, config.idField);

        this.conflicts = notePlan.conflicts;
        if (!confirmDeletions) {
          this.pendingDeletions = [
            ...notePlan.delete_in_lwt.map((d) => ({ text: d.text, side: 'lwt' as const })),
            ...notePlan.delete_in_anki.map((d) => ({ text: d.text, side: 'anki' as const })),
          ];
        }
        this.summary = {
          ...pulled,
          pushed: written.reviews,
          cardsRescheduled: written.cards,
          notesToAnki: notePlan.summary.to_anki,
          notesToLwt: notePlan.summary.to_lwt,
          addedToAnki: added.length,
          addedToLwt: committed.created.length,
          deleted: committed.deleted + (confirmDeletions ? notePlan.delete_in_anki.length : 0),
        };
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
    notesToAnkiLabel(): string {
      return tn('vocabulary.anki.sync.notes_to_anki', this.summary?.notesToAnki ?? 0);
    },
    notesToLwtLabel(): string {
      return tn('vocabulary.anki.sync.notes_to_lwt', this.summary?.notesToLwt ?? 0);
    },
    addedToAnkiLabel(): string {
      return tn('vocabulary.anki.sync.added_to_anki', this.summary?.addedToAnki ?? 0);
    },
    addedToLwtLabel(): string {
      return tn('vocabulary.anki.sync.added_to_lwt', this.summary?.addedToLwt ?? 0);
    },
    deletedLabel(): string {
      return tn('vocabulary.anki.sync.deleted', this.summary?.deleted ?? 0);
    },
    hasDeleted(): boolean {
      return (this.summary?.deleted ?? 0) > 0;
    },
    hasConflicts(): boolean {
      return this.conflicts.length > 0;
    },
    conflictLine(conflict: NotePlan['conflicts'][number]): string {
      return t('vocabulary.anki.sync.conflict_line', {
        term: conflict.text,
        field: t(`vocabulary.anki.sync.field_${conflict.field}`),
        lwt: conflict.lwt,
        anki: conflict.anki,
      });
    },
    hasPendingDeletions(): boolean {
      return this.pendingDeletions.length > 0;
    },
    deletionLine(deletion: PendingDeletion): string {
      return t(
        deletion.side === 'lwt' ? 'vocabulary.anki.sync.deleted_in_anki' : 'vocabulary.anki.sync.deleted_in_lwt',
        { term: deletion.text }
      );
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
