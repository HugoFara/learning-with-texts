---
title: "Proposal: Term Status Model + FSRS Scheduling"
description: Centralize the scattered word-status model into a single source of truth, and align review scheduling with Anki/FSRS by separating display familiarity from memory state.
---

# Proposal: Term Status Model + FSRS Scheduling

**Status:** **Phase 1 and phase 2a implemented** (#238). Phase 2b (retiring the
legacy Leitner scoring and switching the review queue over) remains proposed.
Tracked in [issue #238](https://github.com/HugoFara/lwt/issues/238).

The three open product questions have been decided — see
[Decisions taken](#decisions-taken). Phase 2 was split in two so the
irreversible part (dropping the old score columns) is a separate, later step
from the additive part (accumulating FSRS state).

## Problem

The word-status model — `1-5` (learning), `98` (ignored), `99` (well-known) — is the
spine of both the reading UI (word colouring) and the review system, yet it is
modelled ad-hoc:

- **Duplicated everywhere.** The literal `[1,2,3,4,5,98,99]` and checks like
  `$status === 5 || $status === 99` ("known") recur across **11+ PHP files**
  (`WordFamilyService`, `StatusHelper`, `ReviewApiHandler`, `MySqlStatisticsRepository`,
  `SubmitAnswer`…). Label/colour/order/CSS tables are **re-defined in ~6 TS files**
  (`word_popover.ts`, `term_edit_modal.ts`, `text_status_chart.ts`,
  `texts_grouped_app.ts`, `html_utils.ts`, `statistics_charts.ts`). A
  `TermStatus` value object already exists
  (`src/Modules/Vocabulary/Domain/ValueObject/TermStatus.php`) but is not the single
  source of truth.
- **Scheduling is a hand-tuned Leitner curve.** "Due-ness" comes from
  `TermStatusService::SCORE_FORMULA_TODAY/TOMORROW`: a per-status linear decay
  (`base(status) − decay(status) × days_since_status_change`, clamped at −125, status
  > 5 ⇒ 100) stored in `WoTodayScore`/`WoTomorrowScore`, shuffled by `WoRandom`. The
  status *is* the box; a review just nudges status ±1
  (`SubmitAnswer::executeWithChange`). There is no real memory model, no per-term
  difficulty, no retention target, and no review history.

So two distinct concerns are conflated in one integer: **how familiar a word is**
(needed by the reading view) and **when it should next be reviewed** (scheduling).

## Goal

1. **Make the status model a single source of truth** (foundational, low-risk).
2. **Align scheduling with Anki/FSRS** by separating *display familiarity* from
   *memory state*, replacing the Leitner score formulas with a principled scheduler.

## Phase 1 — Status as a single source of truth ✅ implemented

This stands alone and was worth doing regardless of Phase 2.

### What shipped

- **`TermStatus` is now the authoritative model.** It holds the abbreviation, CSS
  class, light-theme colour, order and predicates, and exposes
  `TermStatus::definitions()` — the single ordered table of `value / name / abbr /
  cssClass / colour / order / isKnown / isLearning / isIgnored`. `isValid()`,
  `values()` and `isKnownValue()/isIgnoredValue()/isLearningValue()` (non-throwing,
  safe on unvalidated input) round it out.
- **`TermStatusService` and `StatusHelper` delegate to the VO.** `getStatuses()`,
  `getStatusColor()`, `isValidStatus()` and the `is{Learning,Known,Ignored}Status()`
  helpers are now thin adapters; the duplicated name/abbr/colour tables are gone.
  (The scheduling members — `SCORE_FORMULA_*`, `calculateScore()`,
  `makeScoreRandomInsertUpdate()` — were left untouched; they are Phase 2.)
- **The scattered literals are gone.** `in_array($status, [1,2,3,4,5,98,99])`,
  `array_fill_keys([1,2,3,4,5,98,99], …)` and `=== 5 || === 99` / `=== 98` checks
  across the Review, Vocabulary and Admin modules now call `TermStatus::isValid()`,
  `TermStatus::values()` and `isKnownValue()/isIgnoredValue()`.
- **Exposed once to the frontend** via `GET /api/v1/settings/status-definitions`
  (returns `TermStatus::definitions()`).
- **One frontend store.** `shared/stores/statuses.ts` is the single TS source for
  status labels/abbr/order/class (localized through the shared `common.status_*`
  i18n keys, so PHP and TS resolve identical text). The duplicated `STATUS_LABELS` /
  `STATUS_ORDER` tables in `text_status_chart.ts`, `texts_grouped_app.ts` and
  `html_utils.ts`, the `term_edit_modal.ts` option list, and the `app_data.ts`
  `statuses` proxy now all resolve from it.

### Deliberately left for a follow-up

- The two Chart.js **colour palettes** (`statistics_charts.ts`,
  `text_status_chart.ts`) diverge from each other and from the CSS
  `--lwt-status*` variables; unifying them is a *visual* change, kept out of this
  cleanup. The reading view itself already single-sources its colours from CSS.
- `word_popover.ts` / `multi_word_modal.ts` keep their local status lists — those
  encode popover-specific *presentation* (Bulma button colours, short `Known` /
  `Ignore` badges) rather than the status model.

## Phase 2 — FSRS-aligned scheduling

### The core idea: split the two concerns

| Concern | Today | Proposed |
| --- | --- | --- |
| **Display familiarity** (reading colours) | `WoStatus` 1–5/98/99 | keep 1–5/98/99 — but *derive* 1–5 from memory strength |
| **Scheduling** (when to review) | per-status decay score | FSRS memory state per term |

Anki/FSRS models each item's memory with three quantities:

- **Stability (S)** — days for retrievability to fall to 90%.
- **Difficulty (D)** — how hard the item is (≈1–10).
- **Retrievability (R)** — current recall probability, from the power forgetting
  curve `R(t) = (1 + F · t/S)^D_curve` (constants `F`, `D_curve` come from the FSRS
  spec/optimizer). The item becomes due when `R` drops to a **target retention**
  (default 0.9).

Reviews are graded on **4 buttons** — Again / Hard / Good / Easy — and each grade
updates `S` and `D` via the FSRS update functions, yielding the next due date.

### What changes

1. **Schema** — add per-term scheduling state (new columns or a `term_schedule`
   table keyed by `WoID`): `stability`, `difficulty`, `due`, `last_review`, `reps`,
   `lapses`, `state` (new/learning/review/relearning). Retire
   `WoTodayScore`/`WoTomorrowScore`/`WoRandom` and the SQL score formulas.
2. **A `Scheduler` service** (in `Modules/Review`) implementing the FSRS update +
   next-interval computation, behind an interface so the algorithm is swappable
   (FSRS now, room for SM-2/custom later). The open-source FSRS reference
   (`open-spaced-repetition`, permissively licensed) is ~a few hundred lines to port;
   verify whether a maintained PHP port can be vendored instead of hand-porting.
3. **Review UX** — the binary correct/incorrect (± 1 status) becomes the 4-grade
   rating. `SubmitAnswer` calls the scheduler instead of `calculateNewStatus`.
4. **`review_log` table** — record `(WoID, grade, state, S, D, elapsed, reviewed_at)`
   per review. FSRS can schedule from current state alone, but logs are required to
   later **optimise** the FSRS parameters per user (Anki's "FSRS optimizer").
5. **Derive display status from stability** — bucket `S` into the familiar 1–5 tiers
   (e.g. `S<1d⇒1`, `<7d⇒2`, `<30d⇒3`, `<90d⇒4`, `≥90d⇒5`) so reading colours reflect
   real memory strength. `98`/`99` stay manual flags meaning "ignored" / "known, not
   scheduled" (≈ Anki suspended). Keep a manual status override that seeds `S`/`D`.

### Migration / continuity

Existing terms have only `WoStatus` + `WoStatusChanged`. Seed FSRS state from them:
map each status to a starting `S` (reuse the current per-status intervals as the
seed), set a default `D`, and `last_review = WoStatusChanged`. No review history is
lost because there is none today; the `review_log` starts accumulating from rollout.

## Decisions taken

The three questions this proposal was gated on, and how they were resolved.

### 1. Display status stays **manual**, not derived from stability

The original recommendation was to derive the 1–5 reading colours from FSRS
stability. That was rejected: it imports an Anki assumption that does not hold
here. **In Anki every card is reviewed; in LWT review is optional and reading is
the primary loop.** Many users never open the review page — they read, click
words, and set status by hand.

Deriving status from stability would give those users reading colours that drift
on their own, driven by a scheduler they do not use: a word deliberately marked
5 decays to 3 because it was never reviewed. That is a regression in the core
experience.

So `WoStatus` remains manual and authoritative for display, and FSRS state is
purely additive. This also removes the migration's biggest risk for free —
"reading-view colours are unchanged after upgrading" is true by construction
rather than something to verify. A derived-status mode can be added later as an
opt-in setting.

### 2. **Four grades**, no 2-button mode

Hard and Easy are where FSRS gets the information that makes it beat SM-2; a
2-button mode degrades it to roughly the Leitner behaviour we already have.
The legacy binary answer maps to Again/Good (`Rating::fromBinary()`), so old
callers keep working without a separate mode to maintain.

### 3. **Hand-port**, not a vendored dependency

Neither PHP option was viable:

- [`fsrs-rs-php`](https://github.com/open-spaced-repetition/fsrs-rs-php), the
  official binding, requires compiling a Rust PHP extension — manual `.so`
  copy, `php.ini` edit, no Composer install. Disqualifying for a self-hosting
  audience; LWT already had to fix Windows CI for a *bundled* extension
  (`pdo_sqlite`, #259).
- [`scottlaurent/fsrs`](https://packagist.org/packages/scottlaurent/fsrs) is
  pure PHP and MIT, but v0.1 / one commit / no validation against reference
  vectors, and declares PHP 8.1–8.3 against LWT's 8.2–8.5.

`Fsrs6Scheduler` is therefore a hand-port of
[py-fsrs](https://github.com/open-spaced-repetition/py-fsrs) **v6.3.1**,
validated against vectors generated from that exact release (see
[Verification](#verification)). LWT is public-domain under the Unlicense; that
one file carries the reference implementation's MIT notice, as the licence
requires.

::: warning Pin the reference version
py-fsrs's unreleased `main` widens the short-term stability clamp from
`(Good, Easy)` to `(Hard, Good, Easy)`. That materially changes same-day
repeats — a same-day Hard becomes a no-op instead of a ~44% stability cut. The
port follows released **v6.3.1**. Retarget it only together with regenerated
fixtures.
:::

## Phase 2a — additive FSRS state ✅ implemented

Everything below accumulates FSRS data on real reviews **without changing what
users see**. The legacy scoring keeps running untouched, so the two models can
be compared on real data before anything is retired.

- **Two new tables** (`db/migrations/20260805_200000_add_fsrs_scheduling.sql`):
  `term_schedule` (stability, difficulty, due, last review, reps, lapses, state)
  and the append-only `review_log`. `TsState` values match Anki's `cards.type`
  so the `.apkg` exporter can write them straight through later.
- **`Fsrs6Scheduler`** behind `SchedulerInterface`, with `FsrsParameters`
  (21 weights, target retention, maximum interval) — swappable by design.
- **Lazy seeding.** `LegacyStatusSeed` maps each legacy status to the stability
  that reproduces its Leitner interval (1/2/9/27/71 days for statuses 1–5), with
  `lastReview = WoStatusChanged`. Rows appear on a term's first graded review
  rather than via a bulk backfill, so a 100k-term vocabulary costs nothing at
  upgrade time and nobody's queue floods. 98/99 are never scheduled.
- **`RecordScheduledReview`** is called from `SubmitAnswer` as a *shadow write*:
  it runs only after the legacy update succeeds, and swallows storage errors so
  a scheduling failure can never break the review the user just submitted.
- **`SubmitAnswer::executeWithGrade()`** is the FSRS-native entry point; the
  grade also drives the legacy ±1 status nudge, so reading behaviour is
  identical whichever endpoint is used.

**Not in 2a:** nothing reads `term_schedule` to choose review words yet, there
is no 4-grade UI, and interval fuzzing and the parameter optimiser are omitted
(fuzzing only exists to spread Anki's daily load; the optimiser needs
accumulated history).

## Phase 2b — retire the legacy scoring (in progress)

**Shipped so far:**

- **The queue reads the schedule.** `ScheduleSql` supplies a term's due date to
  next-word, the due and tomorrow counts, and the table listing, replacing
  `WoTodayScore < 0` / `WoTomorrowScore < 0` / `ORDER BY WoTodayScore, WoRandom`.
  Rows are seeded lazily, so a term without one falls back to the date
  `LegacyStatusSeed` would have given it and the queue is unchanged on upgrade.
  That reproduces the old test to within a day: the legacy formula rounded
  before comparing, which put status 2 at day 3 (interval 2) and status 5 at day
  72 (71). The two-pass `WoRandom` sampling is gone with it.
- **The 4-grade UI.** `PUT /review/status` takes a `grade`, `GET
  /review/intervals` previews what each grade would schedule, and the review
  card shows four buttons with their intervals. Keys 1-4 grade (read off
  `e.code`, so layouts where digits are shifted behave the same); setting a
  status directly moved to Shift+1-5.

**Found on the way:** the 2a shadow write was never firing from the review SPA.
It lives in `SubmitAnswer::executeWithChange()`, but the client computed the new
status itself and sent that, which routes through `execute()` — no scheduling
write. So `term_schedule` stayed empty on installs that only ever used the main
review screen. The graded path fixes this for the SPA; the binary `change`
endpoint was always fine.

- **Scheduling reaches Anki.** The `.apkg` exporter writes a scheduled term as
  a review card — `type`/`queue` 2, due in days from the collection creation
  day, interval, reps and lapses — with the FSRS memory state in `cards.data`
  as `{"s":…,"d":…,"dr":…}` and its history as `revlog` rows. Grades pass
  through unchanged, both apps numbering Again..Easy 1–4. Suspension still wins
  the queue but no longer discards the state. Relearning is flattened to review
  (Anki keys learning-queue due dates by timestamp, not day number) and the
  ease factor is Anki's default 2500, since LWT has never computed an SM-2
  ease and 0 would collapse the interval for anyone with FSRS off.

- **Scheduling comes back too** (#264). The importer reads each card's `revlog`
  and replays the grades through `Fsrs6Scheduler`, rather than copying the
  card's `cards.data` memory state across. Copying would either mix Anki's FSRS
  parameters with ours in one table, or — for an SM-2 collection — import a
  memory state that nothing computed. `revlog` records what the learner did,
  which is the durable half; replaying it keeps one model answering for the
  whole vocabulary and works the same whether the collection used FSRS or not.

  Only reviews later than LWT's own last review are replayed, which is the
  entire conflict policy and needs no sync protocol: both sides timestamp their
  reviews, so the merge is "apply what happened after the state we already hold,
  in the order it happened". It has to be there anyway — an exported file
  carries LWT's history back out with it, so replaying everything would apply
  each review twice and collapse the interval. Two limits: comparison is at the
  second precision `review_log` stores, so an Anki review in the same second as
  ours is dropped (the safe way round); and an Anki review that predates our
  last one is skipped rather than interleaved, since catching those means
  rebuilding state from both histories. Ignored and well-known terms are left
  alone — they were never in the queue.

- **The legacy scoring is retired.** `SCORE_FORMULA_*`,
  `makeScoreRandomInsertUpdate()` and the three columns are gone, along with
  the daily UPDATE across the whole `words` table that kept them fresh. The
  scope was larger than this document claimed: sixteen files reference the
  columns directly, but the SQL-fragment builder had **37** call sites across
  fifteen more. `ReviewService` — 847 lines duplicating the queue with no
  caller — was deleted rather than migrated.

**Verified against real Anki** (pylib 26.08.1, #264). The full loop was run:
LWT export → Anki import → answered with Anki's own v3/FSRS scheduler → Anki
export → LWT import. Memory state crosses unchanged (stability 100.3036 and
difficulty 2.1043 arrive as 100.303596 / 2.104), due dates and intervals match,
`revlog` rows survive, and suspension is preserved. Coming back, a Good raised
stability 100.30 → 161.81 and an Again dropped 18.50 → 1.88 with the lapse
counted — with only the two new reviews replayed, not the three already ours.

**Anki's defaults break the loop in both directions, and both fail quietly.**
Neither is a defect in the file we write; both are options the user has to set:

- Importing into Anki, *Import any learning progress* defaults **off**. Without
  it every card arrives new — `type`/`queue` 0, `ivl` 0, no revlog, no memory
  state, suspension dropped.
- Exporting from Anki, *Support older Anki versions* defaults **off**, so Anki
  writes the collection zstd-compressed as `collection.anki21b` and leaves
  `collection.anki2` as a stub holding one note reading "Please update to the
  latest Anki version…". Reading that stub succeeds, so the import used to
  report one unrecognised note and nothing else. `ApkgReader` now refuses a
  package containing `collection.anki21b` and names both settings; supporting
  the format itself would need zstd, which PHP has no bundled extension for.

## Trade-offs & open questions

The three gating questions are resolved in [Decisions taken](#decisions-taken).
What remains open, all deferred to phase 2b or later:

- **Per-user vs. global parameters.** FSRS ships sensible defaults; per-user
  optimisation needs enough `review_log` history and an optimiser job. 2a starts
  accumulating that history — revisit once real data exists.
- **Interval fuzzing.** Omitted deliberately. It exists to spread Anki's daily
  review load; whether LWT wants it depends on how the 2b queue behaves.
- **Seed quality.** The status→stability mapping reproduces the *legacy*
  intervals, which were hand-tuned rather than fitted. Once `review_log` has
  data, check whether seeded terms behave sensibly or whether the mapping should
  be re-derived.
- **Scope of 2b.** Retiring the score columns touches 16 PHP files, the review
  UI, and stats. Sequence it after 2a has proven itself.

## Scope sketch (when picked up)

- **Phase 1:** `TermStatus` VO (expand), `TermStatusService` + `StatusHelper`
  (fold in), ~11 PHP call sites (adopt VO), status-definitions API + bootstrap, ~6 TS
  files → `shared/stores/statuses.ts`.
- **Phase 2a (done):** migration (`term_schedule` + `review_log`),
  `SchedulerInterface` + `Fsrs6Scheduler` + `FsrsParameters`, `LegacyStatusSeed`,
  `MySqlTermScheduleRepository`, `RecordScheduledReview`, and the shadow-write
  hook in `SubmitAnswer`.
- **Phase 2b:** review UI (4-grade), review queue ordered by `TsDue`, stats that
  read `WoTodayScore` → read `TsDue`, removal of `SCORE_FORMULA_*` /
  `WoTodayScore` / `WoTomorrowScore` / `WoRandom` across their 16 call sites,
  and `cards.data` + `revlog` in the `.apkg` exporter (#228).

## Verification

**Done for phase 2a:**

1. **Reference vectors.** `Fsrs6SchedulerTest` replays 7 review sequences (24
   graded reviews) generated from py-fsrs v6.3.1, asserting stability,
   difficulty, retrievability and interval at every step to 1e-9. The fixture
   and its generator live in
   `tests/backend/Modules/Review/Domain/Scheduling/fixtures/`. Regenerate with:

   ```bash
   python3 -m venv .venv
   ./.venv/bin/pip install 'fsrs==6.3.1'
   ./.venv/bin/python generate_reference_vectors.py > fsrs6_reference_vectors.json
   ```

   These vectors already caught one real porting bug (the short-term clamp
   described above), which is exactly what they are for.

2. **Property tests** alongside them: difficulty saturates within [1, 10] under
   40 consecutive Again and 40 consecutive Easy; a lapse never increases
   stability; Hard < Good < Easy intervals from identical state; retrievability
   is exactly 0.9 after one stability period; a stricter retention target
   schedules sooner.

3. **Integration** (`TermScheduleRepositoryIntegrationTest`, real MySQL): lazy
   seeding from each status, state upsert vs. append-only log, lapse counting,
   due counting, and null for unowned/missing terms.

4. **Gates:** psalm 0 errors, phpcs PSR12 clean, full PHPUnit suite green.

**Still to do for phase 2b:** E2E through a real review session across all four
grades, and confirmation that reading-view colours are untouched on a populated
upgrade (true by construction under decision 1, but worth seeing).
