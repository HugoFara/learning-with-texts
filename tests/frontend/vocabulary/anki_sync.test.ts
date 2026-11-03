/**
 * Pulling reviews from Anki over AnkiConnect (#264, step 1): the AnkiConnect
 * client and the batching that sends the reviews to LWT.
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

const apiPost = vi.fn();
vi.mock('@shared/api/client', () => ({ apiPost: (...args: unknown[]) => apiPost(...args) }));
vi.mock('alpinejs', () => ({ default: { data: vi.fn() } }));

import { AnkiConnect, AnkiConnectError } from '../../../src/frontend/js/modules/vocabulary/services/anki_connect';
import { sendReviews } from '../../../src/frontend/js/modules/vocabulary/pages/anki_sync';

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

    const reviews = await new AnkiConnect('http://127.0.0.1:8765').pullReviews('LWT Term', 'LwtId');

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
