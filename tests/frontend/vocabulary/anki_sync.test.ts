/**
 * Pulling reviews from Anki over AnkiConnect (#264, step 1): the AnkiConnect
 * client and the batching that sends the reviews to LWT.
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

const apiPost = vi.fn();
vi.mock('@shared/api/client', () => ({ apiPost: (...args: unknown[]) => apiPost(...args) }));
vi.mock('alpinejs', () => ({ default: { data: vi.fn() } }));

import { AnkiConnect, AnkiConnectError } from '../../../src/frontend/js/modules/vocabulary/services/anki_connect';
import { planPush, sendReviews } from '../../../src/frontend/js/modules/vocabulary/pages/anki_sync';

const originalFetch = global.fetch;

/** A fake AnkiConnect answering per action. */
function ankiAnswering(handlers: Record<string, (params: Record<string, unknown>) => unknown>) {
  const calls: { action: string; params: Record<string, unknown> }[] = [];
  global.fetch = vi.fn(async (_url: unknown, init?: RequestInit) => {
    const body = JSON.parse(String(init?.body)) as { action: string; version: number; params: Record<string, unknown> };
    expect(body.version).toBe(6);
    calls.push({ action: body.action, params: body.params });
    const handler = handlers[body.action];
    const payload = handler
      ? { result: handler(body.params), error: null }
      : { result: null, error: `unsupported action ${body.action}` };
    return { json: async () => payload } as Response;
  }) as typeof fetch;
  return calls;
}

describe('AnkiConnect client', () => {
  afterEach(() => {
    global.fetch = originalFetch;
  });

  it('maps each card to its term through LwtId and keeps only real answers', async () => {
    const calls = ankiAnswering({
      findNotes: () => [100, 101, 102],
      notesInfo: () => [
        { noteId: 100, cards: [1000], fields: { LwtId: { value: '7', order: 4 } } },
        { noteId: 101, cards: [1001], fields: { LwtId: { value: '8', order: 4 } } },
        // A note without an id cannot be matched to a term.
        { noteId: 102, cards: [1002], fields: { LwtId: { value: '', order: 4 } } },
      ],
      getReviewsOfCards: () => ({
        1000: [{ id: 1770000000000, ease: 3 }, { id: 1770086400000, ease: 0 }],
        1001: [{ id: 1770000500000, ease: 1 }],
      }),
    });

    const { reviews, cards } = await new AnkiConnect('http://127.0.0.1:8765').pullReviews('LWT Term', 'LwtId');

    // Every revlog row per card, answers or not: the push plan needs them all.
    expect(cards).toEqual([
      { term_id: 7, card_id: 1000, reviews: [[1770000000000, 3], [1770086400000, 0]] },
      { term_id: 8, card_id: 1001, reviews: [[1770000500000, 1]] },
    ]);
    expect(reviews).toEqual([
      { term_id: 7, reviewed_at: 1770000000000, ease: 3 },
      { term_id: 8, reviewed_at: 1770000500000, ease: 1 },
    ]);
    expect(calls[0]).toEqual({ action: 'findNotes', params: { query: '"note:LWT Term"' } });
    // Card ids go to getReviewsOfCards as strings, as AnkiConnect expects.
    expect(calls.find((c) => c.action === 'getReviewsOfCards')?.params).toEqual({ cards: ['1000', '1001'] });
  });

  it('reads a large collection in chunks', async () => {
    const noteIds = Array.from({ length: 1200 }, (_, i) => i + 1);
    const calls = ankiAnswering({
      findNotes: () => noteIds,
      notesInfo: (params) => (params.notes as number[]).map((id) => ({
        noteId: id, cards: [id * 10], fields: { LwtId: { value: String(id), order: 4 } },
      })),
      getReviewsOfCards: () => ({}),
    });

    await new AnkiConnect('http://127.0.0.1:8765').pullReviews('LWT Term', 'LwtId');

    expect(calls.filter((c) => c.action === 'notesInfo')).toHaveLength(3);
    expect(calls.filter((c) => c.action === 'getReviewsOfCards')).toHaveLength(3);
  });

  it('reports an unreachable Anki distinctly from an error Anki returns', async () => {
    global.fetch = vi.fn(async () => { throw new TypeError('Failed to fetch'); }) as typeof fetch;
    const unreachable = await new AnkiConnect('http://127.0.0.1:8765').invoke('version').catch((e) => e);
    expect(unreachable).toBeInstanceOf(AnkiConnectError);
    expect(unreachable.unreachable).toBe(true);

    ankiAnswering({});
    const refused = await new AnkiConnect('http://127.0.0.1:8765').invoke('version').catch((e) => e);
    expect(refused).toBeInstanceOf(AnkiConnectError);
    expect(refused.unreachable).toBe(false);
  });

  it('asks Anki for permission and reads the answer', async () => {
    ankiAnswering({ requestPermission: () => ({ permission: 'denied' }) });

    expect(await new AnkiConnect('http://127.0.0.1:8765').requestPermission()).toBe(false);
  });
});

describe('sendReviews', () => {
  beforeEach(() => {
    apiPost.mockReset();
  });

  it('posts in batches and adds up what LWT reports', async () => {
    apiPost.mockResolvedValue({ data: { terms: 1, applied: 2, duplicates: 1, rebuilt: 0, skipped: 0 } });
    const reviews = Array.from({ length: 5 }, (_, i) => ({ term_id: 7, reviewed_at: 1770000000000 + i, ease: 3 }));
    const batches: number[] = [];

    const summary = await sendReviews(reviews, 2, (done) => batches.push(done));

    expect(apiPost).toHaveBeenCalledTimes(3);
    expect(apiPost.mock.calls[0]).toEqual(['/anki/reviews', { reviews: reviews.slice(0, 2) }]);
    expect(batches).toEqual([2, 4, 5]);
    expect(summary).toEqual({ terms: 3, applied: 6, duplicates: 3, rebuilt: 0, skipped: 0 });
  });

  it('stops at the first failed batch', async () => {
    apiPost.mockResolvedValueOnce({ error: 'Forbidden' });

    await expect(sendReviews([{ term_id: 7, reviewed_at: 1, ease: 3 }], 10)).rejects.toThrow('Forbidden');
  });
});

describe('pushing to Anki', () => {
  afterEach(() => {
    global.fetch = originalFetch;
  });

  it('writes reviews, then due dates grouped by day, then card values', async () => {
    const calls = ankiAnswering({
      insertReviews: () => null,
      setDueDate: () => true,
      multi: (params) => (params.actions as unknown[]).map(() => ({ result: [true], error: null })),
    });

    const written = await new AnkiConnect('http://127.0.0.1:8765').applyPushPlan({
      reviews: [[1770000000000, 1000, -1, 3, 2, 0, 2500, 0, 1]],
      cards: [
        { card_id: 1000, due_days: 2, ivl: 2, reps: 1, lapses: 0 },
        { card_id: 1001, due_days: 2, ivl: 3, reps: 2, lapses: 1 },
        { card_id: 1002, due_days: 9, ivl: 9, reps: 3, lapses: 0 },
      ],
      skipped: 0,
    });

    expect(written).toEqual({ reviews: 1, cards: 3 });
    expect(calls.map((c) => c.action)).toEqual(['insertReviews', 'setDueDate', 'setDueDate', 'multi']);
    expect(calls[1].params).toEqual({ cards: [1000, 1001], days: '2' });
    const first = (calls[3].params.actions as { version: number; params: Record<string, unknown> }[])[0];
    // Without its own version an action inside multi answers in the old format.
    expect(first.version).toBe(6);
    expect(first.params).toEqual({ card: 1000, keys: ['ivl', 'reps', 'lapses'], newValues: [2, 1, 0], warning_check: true });
  });

  it('fails when Anki refuses a card value', async () => {
    ankiAnswering({
      setDueDate: () => true,
      multi: () => [{ result: [[false, 'Use native scheduling commands']], error: null }],
    });

    await expect(new AnkiConnect('http://127.0.0.1:8765').applyPushPlan({
      reviews: [],
      cards: [{ card_id: 1000, due_days: 2, ivl: 2, reps: 1, lapses: 0 }],
      skipped: 0,
    })).rejects.toThrow('Use native scheduling commands');
  });

  it('asks LWT for the plan in batches and joins them', async () => {
    apiPost.mockReset();
    apiPost.mockResolvedValue({ data: { reviews: [[1, 2, -1, 3, 1, 0, 2500, 0, 1]], cards: [], skipped: 1 } });
    const cards = Array.from({ length: 2001 }, (_, i) => ({ term_id: i + 1, card_id: i + 1000, reviews: [] as [number, number][] }));

    const plan = await planPush(cards);

    expect(apiPost).toHaveBeenCalledTimes(2);
    expect(apiPost.mock.calls[0][0]).toBe('/anki/push-plan');
    expect(plan.reviews).toHaveLength(2);
    expect(plan.skipped).toBe(2);
  });
});
