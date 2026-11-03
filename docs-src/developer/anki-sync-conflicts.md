---
title: "Spike: Conflict Resolution for Live Anki Sync"
description: How LWT and Anki should merge when both change the same terms between syncs — reviews, fields, tags, flags, creations and deletions — and why neither plain last-writer-wins nor a CRDT is the right tool.
---

# Spike: Conflict Resolution for Live Anki Sync

**Status:** Spike result (#264). **Steps 1 and 2 of the build order are
implemented**, and tested against a real Anki in a container (see
[Testing against real Anki](#testing-against-real-anki)): `/vocabulary/anki/sync`
pulls Anki's reviews into LWT through `MergeReviewHistory`, which fits an
older review into the history instead of skipping it, then sends LWT's reviews
and schedule back to Anki. The `.apkg` importer merges the same way. Step 3
(fields, tags, flags, existence) is not built. The roadmap asked for
this before any: *"Spike conflict resolution FIRST (the Anki problem) — it
looks easy and isn't. Decide last-writer-wins+tombstones vs CRDT before
committing."*

**Decision:** neither, as the primary mechanism. Merge **reviews** as an
event log (union of both histories, folded through LWT's scheduler), and merge
**everything else** three-way against a stored *sync base* — the state both
sides agreed on at the last sync. Last-writer-wins survives only as the
tie-break for a true conflict, where both sides changed the same field to
different values.

## What is being synced

Live sync would run in the browser, between LWT's API and AnkiConnect on
`localhost:8765` — a server cannot reach a user's AnkiConnect (#264). Two
replicas, one of which (Anki) we do not control and cannot add metadata to
beyond note fields.

Everything that can change on both sides between two syncs:

| Data | LWT | Anki | Shape |
|---|---|---|---|
| Reviews | `review_log` rows | `revlog` rows | Append-only, timestamped |
| Scheduling state | `term_schedule` (FSRS S, D, due) | card `ivl`, `due`, `data` | Derived from reviews |
| Content | translation, romanization, sentence, notes | note fields | Scalar per field |
| Tags | `wordtags` | note tags | Set |
| Flags | status 98 ignored / 99 well known | card suspended | Boolean-ish |
| Existence | term created / deleted | note added / deleted | — |

## Constraints found

Checked in the code and in AnkiConnect's API.

**LWT side**

- `words` has `WoCreated` and `WoStatusChanged` but **no modification
  timestamp** for content: an edit to a translation leaves no trace of when.
- Deleting a term removes the row. **No tombstones.**
- `review_log` is append-only with second precision; `term_schedule` is a
  cache of folding it through `Fsrs6Scheduler`.
- Exported notes carry the term id twice: in the guid (`lwt-<id>`) and in an
  `LwtId` field (`AnkiSchema::FIELD_LWT_ID`).

**Anki side, through AnkiConnect**

These details come from the typed client `yanki-connect` 4.0.6, written
against AnkiConnect 25.11.9.0; the official README at
`git.sr.ht/~foosoft/anki-connect` was unreachable when this was written.
**Since verified** against AnkiConnect's own source (the AnkiWeb package) and
by running it, for everything steps 1 and 2 use; see below.

- `notesInfo` returns fields, tags, cards and a `mod` time per **note** — one
  timestamp for the whole note, not per field.
- `notesInfo` does **not** return the guid. Identity has to come from the
  `LwtId` field, which is why it must stay in the note type.
- `cardReviews(deck, startID)` returns revlog rows after a review id (epoch
  milliseconds); `getLatestReviewID(deck)` gives the high-water mark;
  `getReviewsOfCards` reads per card.
- `insertReviews` writes revlog rows, in the same 9-tuple shape.
- Scheduling can only be written through `setDueDate` (due date, optional
  interval reset) or `setSpecificValueOfCard`, which writes raw card columns
  and warns that it can damage the collection. There is no call to set a
  card's FSRS memory state (`cards.data`).
- Deletions leave no record AnkiConnect exposes: a deleted note simply stops
  appearing in `findNotes`.
- Clocks: LWT's server and the user's desktop are different machines.

## Why not last-writer-wins + tombstones

- **No writer times on LWT content.** LWW needs a timestamp per value. LWT has
  none for fields; adding `WoUpdated` gives one per *term*, and Anki's `mod`
  is per *note*. Edit the translation in LWT and the notes field in Anki, and
  whole-record LWW discards one of the two edits even though they never
  touched the same field.
- **Two clocks.** The comparison would be between a server clock and a
  desktop clock; minutes of skew decide which edit survives.
- **Tombstones on one side only.** LWT could keep them; Anki will not, so a
  deletion in Anki is invisible to LWW unless we remember the note existed —
  which is the sync base below anyway.

## Why not a CRDT

A CRDT makes replicas converge without coordination by carrying merge metadata
with the data. Here:

- **Anki cannot carry the metadata.** We can store our own bookkeeping only in
  note fields, which the user can see and edit. Anki's own sync to AnkiWeb and
  its other devices would ignore it.
- **There are only two replicas, and they meet at a sync point.** A CRDT's
  value is convergence among many peers merging in any order. Two parties that
  sync explicitly can keep one snapshot of what they last agreed on and get the
  same result with far less machinery.
- **The parts that need CRDT-like behaviour are naturally so already.** Reviews
  are a grow-only set of timestamped events, and tags are a set; both merge
  cleanly with the approach below.

## The design: event log for reviews, three-way merge for the rest

### Reviews: union of both logs, folded through one scheduler

Reviews never conflict: each is a fact that happened at a time. The merged
history is the union of `review_log` and `revlog` for the pair, de-duplicated,
ordered by time; LWT's schedule is that history folded through
`Fsrs6Scheduler`. This is the `.apkg` importer's existing policy
(`ScheduleReplay`) with its one limit removed: today an Anki review older than
LWT's last review is skipped, because catching it means rebuilding the state
from both histories. Live sync makes that case common — study on the phone in
the morning, in LWT at noon, sync in the evening — so the fold has to restart
from the earliest unseen review rather than append after the latest.

- **De-duplication.** A review that went LWT → Anki through `insertReviews`
  comes back in `cardReviews`. Key each review by (term, timestamp to the
  second, grade) and drop repeats. Exports already round-trip LWT's own history
  this way, and the importer already relies on it.
- **Into Anki.** Push LWT's unseen reviews with `insertReviews`, then set the
  card's due date (`setDueDate`) and its interval, reps and lapses
  (`setSpecificValueOfCard`, with `warning_check`) so the next Anki session
  agrees. Measured against Anki 26.9.3 and AnkiConnect, FSRS on and off:
  - `insertReviews` only appends history. The card's interval, due date, reps,
    lapses and FSRS stability are untouched, and Anki's next answer comes out
    exactly as on a twin card without the inserted review: Anki does not
    replay revlog when answering. So the schedule has to be set separately.
  - AnkiConnect refuses to write FSRS memory state ("Coupled scheduler state
    must use native scheduling transitions"). With FSRS on, Anki's estimate
    for a card therefore lags until the learner reoptimises, which recomputes
    it from the history this push sends. The due date is right regardless.
  - `setDueDate` adds Anki's own grade-less "rescheduled" revlog row, which
    the pull ignores like any ease-0 row.
  - `insertReviews` rejects a whole batch if one id already exists, so the
    plan only ever sends ids Anki does not have.

### Everything else: three-way merge against a sync base

Keep, per synced pair, the values both sides had at the last successful sync:

```text
anki_sync_base
  term id ↔ Anki note id, card id
  base fields     (translation, romanization, sentence, notes — as synced)
  base tags       (set)
  base flags      (ignored / well-known ↔ suspended)
  last review ids (LWT review_log id, Anki revlog id)
  synced at
```

For each field, compare LWT's current value `L` and Anki's `A` with the base
`B`:

| L vs B | A vs B | Result |
|---|---|---|
| same | same | nothing to do |
| changed | same | take L, write to Anki |
| same | changed | take A, write to LWT |
| changed | changed, `L = A` | converged, nothing to do |
| changed | changed, `L ≠ A` | **true conflict** — see below |

No clock is consulted except in the last row, and edits to *different* fields
of the same term both survive — the case LWW loses.

**Tags** merge as sets: added = (L − B) ∪ (A − B), removed = (B − L) ∪ (B − A),
result = (B ∪ added) − removed. A tag added on one side and removed on the
other between the same two syncs cannot happen, since it was either in B or
not. This is an OR-set without per-element metadata, because the base supplies
what the metadata would.

**Flags** are a field like the others, with LWT's 98 mapped to suspended.
Status 99 (well known) has no Anki equivalent; leave it LWT-only rather than
inventing one.

**Existence** comes out of the base without tombstones:

| In base | LWT | Anki | Result |
|---|---|---|---|
| no | present | absent | new in LWT → `addNotes` with `LwtId` |
| no | absent | present (has `LwtId`) | term deleted before it was ever synced → skip, as the importer does |
| no | absent | present (no `LwtId`, in the synced deck) | new in Anki → create a term in the deck's language |
| yes | present | absent | deleted in Anki |
| yes | absent | present | deleted in LWT |
| yes | absent | absent | gone on both sides → drop the base row |

**Delete versus edit.** If one side deleted and the other changed anything
since the base (a field, a tag, *or a review*), keep the item and re-create it
on the deleting side. Losing a week of reviews to a stray delete is worse than
a resurrected card the user can delete again.

### True conflicts

Both sides changed the same field to different values. Options, in order of
preference:

1. **Show it.** List conflicts in the sync report with both values; the user
   picks. Nothing is lost silently.
2. **Default when unattended:** newest edit wins, using Anki's note `mod` and a
   new `WoUpdated` on LWT's side — and append the losing value to the term's
   notes, so it is recoverable. Clock skew only matters here, in the one case
   that is genuinely ambiguous.

`WoUpdated` is the one schema addition this needs from `words`. It must be set
by content edits only, not by status changes or review writes, so it cannot be
a plain `ON UPDATE CURRENT_TIMESTAMP`.

## Running a sync safely

A sync touches two stores with no shared transaction. Make each run
**plan → apply to Anki → commit LWT and the base**:

1. Read both sides, compute the merge as a plan (pure function: base, L, A →
   actions for each side plus the new base).
2. Apply Anki's actions through AnkiConnect (`multi` batches them).
3. Apply LWT's actions and write the new base in one database transaction.

If step 2 fails part-way, the base is unchanged, so the next run recomputes
the same plan; the Anki writes that did land now show as "converged" (`L = A`)
and are skipped. Every action must therefore be idempotent: field writes
are, `addNotes` is guarded by the `LwtId` lookup, and `insertReviews` is
guarded by the de-duplication key.

The merge itself belongs on the server, behind an endpoint that takes Anki's
snapshot and returns Anki's actions; the browser only carries data between
AnkiConnect and LWT. That keeps the merge in PHP, testable without Anki, and
the same for any future client.

## Scenarios the implementation must pass

The spike's acceptance list, to become the merge function's unit tests:

1. Translation edited in LWT only → Anki gets it.
2. Notes edited in Anki only → LWT gets it.
3. Translation in LWT, notes in Anki → both kept.
4. Same field, same new value on both → no conflict reported.
5. Same field, different values → conflict reported; unattended, the newer
   wins and the other is appended to notes.
6. Tag added in LWT, another tag added in Anki, a third removed in Anki →
   union of additions minus the removal.
7. Reviews in Anki at 08:00 and in LWT at 12:00, sync at 20:00 → both in the
   history in time order; LWT's schedule equals folding all of them; no review
   counted twice on the next sync.
8. A review pushed with `insertReviews` and read back → not replayed again.
9. Term suspended in Anki → status 98 in LWT; unsuspended → previous status
   restored (needs the base to remember it).
10. Note deleted in Anki, term unchanged in LWT → term deleted.
11. Note deleted in Anki, term reviewed in LWT since → note re-created with
    the merged history.
12. New note in the synced Anki deck without `LwtId` → new term in that deck's
    language.
13. Sync interrupted after Anki writes, before LWT commit → next run converges
    with no duplicate notes or reviews.

## Open questions to settle before building

- ~~Does Anki recompute `cards.data` from inserted revlog rows?~~ Answered by
  measurement: no; only reoptimising does. See *Into Anki* above.
- ~~Confirm every AnkiConnect detail above.~~ Done for steps 1 and 2, against
  AnkiConnect's source and a running Anki.
- For step 3: which Anki deck(s) map to which LWT language: one deck per language, chosen
  once, is the simplest rule.
- Whether status 1–5 should follow Anki at all, or stay derived from LWT's own
  schedule (phase 2 decision 1 says derived).

## Recommendation

The `.apkg` round trip already covers the common case without a protocol. If
live sync is built, sequence it so each step is useful alone:

1. **Pull Anki reviews** and fold them in, with the interleaving fix. One
   direction, reviews only, no field conflicts possible. ✅ Implemented: the
   browser reads every review of every `LWT Term` note with
   `getReviewsOfCards` rather than `cardReviews` from a high-water mark, and
   the server drops those it already has. Simpler and needs no stored cursor;
   revisit if large collections make the pull slow.
2. **Push LWT reviews** (`insertReviews` + `setDueDate` +
   `setSpecificValueOfCard`). ✅ Implemented: after the pull, the browser asks
   `POST /api/v1/anki/push-plan` what Anki lacks (`AnkiPushPlan`) and writes
   it. FSRS memory state cannot be written; see above.
3. **Fields, tags, flags and existence** through the sync base, with the
   conflict report.

Step 1 alone delivers most of what "sync with Anki" means to a learner who
reviews on the phone and reads in LWT.

## Testing against real Anki

`scripts/anki/container` builds Anki 26.9.3 with AnkiConnect, headless (Qt's
offscreen platform), from first-party sources: Anki from PyPI, AnkiConnect
from AnkiWeb checked against a pinned hash. It trusts LWT's dev-server origins
and shares `cypress/anki-exchange` as `/exchange` for package imports.

```bash
npm run anki:build        # once
npm run anki:up           # Anki on 127.0.0.1:8765
npm run serve             # LWT on :8000, in another terminal
npx cypress run --spec cypress/e2e/24-anki-live.cy.ts
npm run anki:down
```

`24-anki-live.cy.ts` runs the learner's loop for real: export terms from LWT,
import them into Anki (`importPackage`), answer them there (`answerCards`),
sync, answer one in LWT, sync again, and check Anki's history and card, then
that a third sync changes nothing. It skips itself when nothing answers on
8765, so CI is unaffected. `ANKI_FSRS=1` on `podman run` starts Anki with FSRS
on; the spec passes in both modes.

