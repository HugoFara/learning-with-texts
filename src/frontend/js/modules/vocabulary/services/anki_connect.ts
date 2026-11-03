/**
 * A minimal client for AnkiConnect, and the review pull built on it (#264).
 *
 * AnkiConnect is an Anki add-on serving a JSON API on the learner's own
 * machine (127.0.0.1:8765 by default). A server cannot reach it, so the pull
 * runs here, in the browser: read LWT's notes and their reviews from Anki,
 * map each card back to its term through the note's LwtId field, and post the
 * reviews to LWT, which merges them into each term's history.
 *
 * Only the actions this needs are wrapped. The request shape is AnkiConnect's
 * own: `{action, version, params}` in, `{result, error}` out.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.8.0
 */

/** AnkiConnect API version spoken here. */
const API_VERSION = 6;

/** Notes and cards per AnkiConnect request, to keep each response small. */
const CHUNK = 500;

/** Thrown when AnkiConnect cannot be reached or answers with an error. */
export class AnkiConnectError extends Error {
  constructor(
    message: string,
    /** True when nothing answered at all: Anki closed, or the add-on missing. */
    public readonly unreachable = false
  ) {
    super(message);
    this.name = 'AnkiConnectError';
  }
}

interface AnkiResponse<T> {
  result: T;
  error: string | null;
}

/** One `revlog` row as getReviewsOfCards returns it. */
export interface AnkiRevlogEntry {
  /** Review time in epoch milliseconds (the revlog id). */
  id: number;
  /** Button pressed: 1 Again .. 4 Easy, 0 for "set due date" / "forget". */
  ease: number;
}

interface AnkiNoteInfo {
  noteId: number;
  cards: number[];
  fields: Record<string, { value: string; order: number }>;
}

/** A review on its way to POST /api/v1/anki/reviews. */
export interface PulledReview {
  term_id: number;
  reviewed_at: number;
  ease: number;
}

/** One Anki card of an LWT term, with its revlog as `[id, ease]` pairs. */
export interface CardHistory {
  term_id: number;
  card_id: number;
  reviews: [number, number][];
}

/** One "LWT Term" note as the note sync sends it to LWT. */
export interface SyncNote {
  note_id: number;
  card_id: number;
  deck: string;
  fields: Record<string, string>;
  tags: string[];
  suspended: boolean;
  /** Latest answer on the card, epoch ms, or null. */
  last_review: number | null;
}

/** Anki's half of the note sync plan (POST /api/v1/anki/notes-plan). */
export interface NotePlan {
  fields: { note_id: number; fields: Record<string, string> }[];
  tags: { note_id: number; tags: string[] }[];
  suspend: number[];
  unsuspend: number[];
  add: { term_id: number; deck: string; fields: Record<string, string>; tags: string[]; suspended: boolean }[];
  delete_in_anki: { term_id: number; note_id: number; text: string }[];
  delete_in_lwt: { term_id: number; note_id: number; text: string }[];
  conflicts: { term_id: number; text: string; field: string; lwt: string; anki: string }[];
  summary: { to_anki: number; to_lwt: number; added_to_anki: number; added_to_lwt: number; orphans: number };
}

/** What LWT asks to be written to Anki (POST /api/v1/anki/push-plan). */
export interface PushPlan {
  /** revlog rows for insertReviews, Anki's nine-integer shape. */
  reviews: number[][];
  cards: { card_id: number; due_days: number; ivl: number; reps: number; lapses: number }[];
  skipped: number;
}

export class AnkiConnect {
  constructor(private readonly url: string) {}

  /**
   * Call one AnkiConnect action.
   *
   * @throws AnkiConnectError when nothing answers, or AnkiConnect reports one
   */
  async invoke<T>(action: string, params: Record<string, unknown> = {}): Promise<T> {
    let response: Response;
    try {
      response = await fetch(this.url, {
        method: 'POST',
        body: JSON.stringify({ action, version: API_VERSION, params }),
      });
    } catch {
      throw new AnkiConnectError(`Could not reach AnkiConnect at ${this.url}`, true);
    }

    const body = (await response.json()) as AnkiResponse<T>;
    if (body.error) {
      throw new AnkiConnectError(body.error);
    }
    return body.result;
  }

  /**
   * Ask Anki to trust this page's origin.
   *
   * Anki shows the learner a dialog the first time; after they accept, the
   * origin is remembered, so nobody has to edit webCorsOriginList by hand.
   *
   * @returns Whether access was granted
   */
  async requestPermission(): Promise<boolean> {
    const result = await this.invoke<{ permission: string }>('requestPermission');
    return result.permission === 'granted';
  }

  /**
   * Every review of every LWT note in the collection.
   *
   * Returned twice over: as rows for LWT to merge, and per card with every
   * revlog row (answers or not), which the push plan compares against.
   *
   * @param noteType Anki note type LWT exports ("LWT Term")
   * @param idField  Field carrying the LWT term id ("LwtId")
   * @param onProgress Called with a short status line as the pull advances
   */
  async pullReviews(
    noteType: string,
    idField: string,
    onProgress: (step: 'notes' | 'reviews', done: number, total: number) => void = () => {}
  ): Promise<{ reviews: PulledReview[]; cards: CardHistory[] }> {
    const noteIds = await this.invoke<number[]>('findNotes', {
      query: `"note:${noteType.replace(/"/g, '\\"')}"`,
    });

    const termByCard = new Map<number, number>();
    for (let i = 0; i < noteIds.length; i += CHUNK) {
      const notes = await this.invoke<AnkiNoteInfo[]>('notesInfo', {
        notes: noteIds.slice(i, i + CHUNK),
      });
      for (const note of notes) {
        const termId = Number.parseInt(note.fields[idField]?.value ?? '', 10);
        if (Number.isInteger(termId) && termId > 0) {
          for (const card of note.cards) termByCard.set(card, termId);
        }
      }
      onProgress('notes', Math.min(i + CHUNK, noteIds.length), noteIds.length);
    }

    const cards = [...termByCard.keys()];
    const reviews: PulledReview[] = [];
    const histories = new Map<number, CardHistory>();
    for (const [card, termId] of termByCard) {
      histories.set(card, { term_id: termId, card_id: card, reviews: [] });
    }
    for (let i = 0; i < cards.length; i += CHUNK) {
      const byCard = await this.invoke<Record<string, AnkiRevlogEntry[]>>('getReviewsOfCards', {
        cards: cards.slice(i, i + CHUNK).map(String),
      });
      for (const [card, entries] of Object.entries(byCard)) {
        const termId = termByCard.get(Number(card));
        if (termId === undefined) continue;
        for (const entry of entries) {
          histories.get(Number(card))?.reviews.push([entry.id, entry.ease]);
          // Grade-less rows ("set due date", "forget") are not answers.
          if (entry.ease >= 1 && entry.ease <= 4) {
            reviews.push({ term_id: termId, reviewed_at: entry.id, ease: entry.ease });
          }
        }
      }
      onProgress('reviews', Math.min(i + CHUNK, cards.length), cards.length);
    }

    return { reviews, cards: [...histories.values()] };
  }

  /**
   * Every "LWT Term" note with what the note sync compares, and Anki's decks.
   *
   * @param lastReviews Latest answer per card (epoch ms), from pullReviews
   */
  async readNotes(
    noteType: string,
    lastReviews: Map<number, number>
  ): Promise<{ notes: SyncNote[]; decks: string[] }> {
    const decks = await this.invoke<string[]>('deckNames');
    const noteIds = await this.invoke<number[]>('findNotes', {
      query: `"note:${noteType.replace(/"/g, '\\"')}"`,
    });

    const notes: SyncNote[] = [];
    for (let i = 0; i < noteIds.length; i += CHUNK) {
      const infos = await this.invoke<(AnkiNoteInfo & { tags: string[] })[]>('notesInfo', {
        notes: noteIds.slice(i, i + CHUNK),
      });
      const firstCards = infos.map((n) => n.cards[0]).filter((c): c is number => c !== undefined);
      const cards = await this.invoke<{ cardId: number; deckName: string; queue: number }[]>('cardsInfo', {
        cards: firstCards,
      });
      const cardById = new Map(cards.map((c) => [c.cardId, c]));
      for (const info of infos) {
        const card = cardById.get(info.cards[0]);
        if (!card) continue;
        const fields: Record<string, string> = {};
        for (const [name, field] of Object.entries(info.fields)) fields[name] = field.value;
        notes.push({
          note_id: info.noteId,
          card_id: card.cardId,
          deck: card.deckName,
          fields,
          tags: info.tags,
          // Anki's queue -1 is a suspended card.
          suspended: card.queue === -1,
          last_review: lastReviews.get(card.cardId) ?? null,
        });
      }
    }

    return { notes, decks };
  }

  /**
   * Write Anki's half of a note sync plan.
   *
   * @param confirmDeletions Also delete the notes whose terms LWT deleted
   * @returns The notes added, for LWT to record against their terms
   */
  async applyNotePlan(
    plan: NotePlan,
    noteType: string,
    confirmDeletions: boolean
  ): Promise<{ term_id: number; note_id: number; card_id: number }[]> {
    for (let i = 0; i < plan.fields.length; i += CHUNK) {
      await this.invokeAll(plan.fields.slice(i, i + CHUNK).map((change) => ({
        action: 'updateNoteFields',
        params: { note: { id: change.note_id, fields: change.fields } },
      })));
    }
    for (let i = 0; i < plan.tags.length; i += CHUNK) {
      await this.invokeAll(plan.tags.slice(i, i + CHUNK).map((change) => ({
        action: 'updateNoteTags',
        params: { note: change.note_id, tags: change.tags },
      })));
    }
    if (plan.suspend.length > 0) await this.invoke('suspend', { cards: plan.suspend });
    if (plan.unsuspend.length > 0) await this.invoke('unsuspend', { cards: plan.unsuspend });

    const added: { term_id: number; note_id: number; card_id: number }[] = [];
    for (let i = 0; i < plan.add.length; i += CHUNK) {
      const batch = plan.add.slice(i, i + CHUNK);
      for (const deck of new Set(batch.map((a) => a.deck))) {
        await this.invoke('createDeck', { deck });
      }
      const ids = await this.invoke<(number | null)[]>('addNotes', {
        notes: batch.map((a) => ({
          deckName: a.deck,
          modelName: noteType,
          fields: a.fields,
          tags: a.tags,
          // The word may already be a note of the learner's own.
          options: { allowDuplicate: true },
        })),
      });
      const newIds = ids.filter((id): id is number => id !== null);
      const infos = newIds.length > 0
        ? await this.invoke<AnkiNoteInfo[]>('notesInfo', { notes: newIds })
        : [];
      const cardByNote = new Map(infos.map((n) => [n.noteId, n.cards[0]]));
      const toSuspend: number[] = [];
      batch.forEach((a, index) => {
        const noteId = ids[index];
        const cardId = noteId === null ? undefined : cardByNote.get(noteId);
        if (noteId === null || cardId === undefined) return;
        added.push({ term_id: a.term_id, note_id: noteId, card_id: cardId });
        if (a.suspended) toSuspend.push(cardId);
      });
      if (toSuspend.length > 0) await this.invoke('suspend', { cards: toSuspend });
    }

    if (confirmDeletions && plan.delete_in_anki.length > 0) {
      await this.invoke('deleteNotes', { notes: plan.delete_in_anki.map((d) => d.note_id) });
    }

    return added;
  }

  /**
   * Write the LwtId of notes LWT has just made terms of.
   */
  async writeLwtIds(created: { note_id: number; term_id: number }[], idField: string): Promise<void> {
    for (let i = 0; i < created.length; i += CHUNK) {
      await this.invokeAll(created.slice(i, i + CHUNK).map((c) => ({
        action: 'updateNoteFields',
        params: { note: { id: c.note_id, fields: { [idField]: String(c.term_id) } } },
      })));
    }
  }

  /**
   * Run several actions in one request, failing on the first error.
   *
   * Each action inside multi carries its own API version; without one it
   * defaults to version 4, which answers with the bare result instead of
   * {result, error}.
   */
  private async invokeAll(actions: { action: string; params: Record<string, unknown> }[]): Promise<void> {
    if (actions.length === 0) return;
    const replies = await this.invoke<AnkiResponse<unknown>[]>('multi', {
      actions: actions.map((action) => ({ ...action, version: API_VERSION })),
    });
    for (const reply of replies) {
      if (reply.error) throw new AnkiConnectError(String(reply.error));
    }
  }

  /**
   * Write what LWT planned: missing reviews, then each card's schedule.
   *
   * insertReviews refuses a whole batch if one id already exists, so the plan
   * only ever carries ids Anki does not have.
   *
   * @returns How many reviews and cards were written
   */
  async applyPushPlan(
    plan: PushPlan,
    onProgress: (done: number, total: number) => void = () => {}
  ): Promise<{ reviews: number; cards: number }> {
    const total = plan.reviews.length + plan.cards.length;
    for (let i = 0; i < plan.reviews.length; i += CHUNK) {
      await this.invoke('insertReviews', { reviews: plan.reviews.slice(i, i + CHUNK) });
      onProgress(Math.min(i + CHUNK, plan.reviews.length), total);
    }

    // setDueDate takes one day count for many cards.
    const byDays = new Map<number, number[]>();
    for (const card of plan.cards) {
      byDays.set(card.due_days, [...(byDays.get(card.due_days) ?? []), card.card_id]);
    }
    for (const [days, cards] of byDays) {
      await this.invoke('setDueDate', { cards, days: String(days) });
    }

    // Interval, reps and lapses are protected fields: AnkiConnect wants
    // warning_check to write them.
    // Each answer inside multi is its own {result, error} (given a version,
    // see invokeAll), and this action reports a refused write as
    // [[false, reason]] rather than as an error.
    for (let i = 0; i < plan.cards.length; i += CHUNK) {
      const replies = await this.invoke<AnkiResponse<unknown>[]>('multi', {
        actions: plan.cards.slice(i, i + CHUNK).map((card) => ({
          action: 'setSpecificValueOfCard',
          version: API_VERSION,
          params: {
            card: card.card_id,
            keys: ['ivl', 'reps', 'lapses'],
            newValues: [card.ivl, card.reps, card.lapses],
            warning_check: true,
          },
        })),
      });
      for (const reply of replies) {
        const refused = Array.isArray(reply.result) && Array.isArray(reply.result[0]) ? reply.result[0][1] : null;
        if (reply.error || refused) {
          throw new AnkiConnectError(String(reply.error ?? refused));
        }
      }
      onProgress(plan.reviews.length + Math.min(i + CHUNK, plan.cards.length), total);
    }

    return { reviews: plan.reviews.length, cards: plan.cards.length };
  }
}
