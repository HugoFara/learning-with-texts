# Anki Export & Import

LWT can export your vocabulary to a real Anki package (`.apkg`) and read changes back from a `.apkg` you re-export from Anki. The flow is **manual round-trip** — not a live sync — but the file format is universal, so any Anki client (desktop, AnkiMobile, AnkiDroid, AnkiWeb) can study the deck.

::: warning Importing a deck you built in Anki?
This page covers the **round trip** for terms that started in LWT: export them,
study in Anki, import the file back to update them. Notes are matched by an
`lwt-` identifier that only LWT-exported files carry, so a deck built in Anki or
downloaded from AnkiWeb has nothing to match and **nothing would change**.

To seed LWT from a deck you already study, use
[Import an Anki Deck](/reference/anki-deck-import) instead.
:::

## When to use this

- You already have an Anki workflow and want LWT to seed it from your reading.
- You want to study LWT terms on your phone via AnkiDroid or AnkiMobile.
- You want changes you make in Anki (translation tweaks, suspending a card you no longer need to drill) to flow back into LWT the next time you re-import.

If you only want LWT-side spaced repetition, the built-in [Review module](/reference/term-scores) is simpler.

## Round-trip in three steps

1. **Export** — LWT generates `lwt-{language}-{date}.apkg`. Each LWT term becomes one Anki note with five fields: Term, Translation, Romanization, Notes, LwtId.
2. **Study in Anki** — Import the file into Anki (File → Import). Subsequent exports merge cleanly because each note carries a stable guid (`lwt-{TermID}`), so Anki updates the existing note instead of creating a duplicate.
3. **Re-import to LWT** — Export the deck from Anki (File → Export → Anki Deck Package, include scheduling info). Upload it back into LWT. Translation, romanization, notes, and tag edits flow back. Cards you suspended in Anki demote learning-status terms to **Ignored** in LWT.

## Where to find it

On the **vocabulary list page**, both the "ALL" and "Marked Terms" action dropdowns expose the new options under their **Export** group:

- *Marked Terms → Export Selection to Anki package (.apkg)* — exports only the terms you've ticked
- *ALL → Export Language to Anki package (.apkg)* — exports every term in the current language
- *ALL → Import Anki package (.apkg)…* — opens the upload form

## Endpoints

| Endpoint | Purpose |
|----------|---------|
| `GET /vocabulary/apkg/export?lang_id=N` | Streams a whole-language `.apkg` |
| `POST /vocabulary/apkg/export` | Same, with optional `marked[]` to restrict to a subset |
| `GET /vocabulary/apkg/import` | Upload form |
| `POST /vocabulary/apkg/import` | Accepts a `.apkg` upload, merges into LWT, returns a summary |

`lang_id` defaults to your current language if omitted. An empty `marked[]` is treated as "every term in the language".

## Field mapping (LWT → Anki)

| LWT column | Anki field | Notes |
|------------|-----------|-------|
| `WoText` | Term | Sort field; also drives Anki's duplicate detection |
| `WoTranslation` | Translation | Back of the card |
| `WoRomanization` | Romanization | Shown on the front under the term, if non-empty |
| `WoNotes` | Notes | Shown on the back, if non-empty |
| `WoID` | LwtId + note `guid` | Stable identity for round-trip |
| `word_tag_map` | Note tags | Tag strings preserved; spaces in tag names become `_` |

## Status mapping

| LWT status | Anki card state | On re-import |
|------------|----------------|--------------|
| 1–5 (learning / learned) | review card if LWT has scheduled it, else a new card | suspended-in-Anki → demoted to **Ignored** (98) |
| 98 (Ignored) | suspended | unsuspending in Anki does not reverse this |
| 99 (Well-known) | suspended | unsuspending in Anki does not reverse this |

The status mapping is intentionally one-way for 98/99 → suspended on export. We don't promote terms to **Well-known** based on Anki state because suspension and "well-known" carry different semantics, and silently collapsing them would lose information.

## Scheduling

**Scheduling is exported, not imported.** A term LWT has scheduled arrives in Anki as a review card that is already due when LWT says it is due, carrying:

- its **interval**, review count and lapse count;
- its **FSRS memory state** — stability, difficulty and desired retention — in the card's `data`, which is where Anki's own FSRS keeps it, so Anki carries on from LWT's estimate rather than starting the term over;
- its **review history**, one `revlog` row per answer, with the grade unchanged (both apps number Again..Easy 1–4).

A suspended term keeps its schedule behind the suspension, so unsuspending it in Anki resumes rather than restarts.

Two simplifications worth knowing:

- A term in *relearning* is exported as an ordinary review card. Anki holds relearning cards in its learning queue, where the due date is a timestamp rather than a day number; the card comes due at the same moment either way.
- The ease factor is Anki's default 2500 rather than a measured value. LWT has never computed an SM-2 ease, and writing 0 would make Anki's own SM-2 collapse the interval for anyone with FSRS switched off.

**Nothing flows the other way.** Re-importing a deck updates fields and suspension only; whatever schedule Anki has built stays in Anki, and LWT keeps its own. If you answer the same word in both apps you still keep two schedules — exporting only gives Anki a sensible starting point instead of a blank one. See [#228](https://github.com/HugoFara/lwt/issues/228).

## What round-trips, what doesn't

| Field | Round-trip |
|-------|-----------|
| Term text | ✅ |
| Translation | ✅ |
| Romanization | ✅ |
| Notes | ✅ |
| Tags | ✅ |
| Suspended ↔ Ignored (one direction) | ✅ |
| LWT schedule → Anki (state, interval, history) | ✅ (export only) |
| Anki SRS state → LWT | ❌ (deliberate) |
| New notes added in Anki (no LWT id) | ❌ (silently skipped in v1) |
| Deletions in Anki | ❌ (LWT terms are never auto-deleted) |

## Note type and deck convention

- **Note type:** a single LWT-defined type called *LWT Term*. Five fields, one card template (Term → Translation). Re-importing into Anki repeatedly does not create new note types — Anki dedupes on the type's id (`1607392319000`).
- **Deck:** one Anki deck per LWT language, named `LWT::{LanguageName}`. The double-colon makes Anki nest it under a top-level `LWT` deck so all your LWT decks live under one parent.

## Requirements

An `.apkg` is a zip containing a SQLite collection, so this feature needs PHP's **`pdo_sqlite`** extension. It is compiled in by default on most Linux builds; on Windows and some shared hosts you may need to enable `extension=pdo_sqlite` in `php.ini` and restart PHP. If it is missing, export and import stop with a message telling you exactly that — nothing else in LWT is affected.

## Troubleshooting

**"Anki .apkg support requires the pdo_sqlite PHP extension."** See [Requirements](#requirements) above — enable `extension=pdo_sqlite` in your `php.ini`.

**"No terms to export for language X."** The language has zero terms. Add some by reading a text first.

**Anki imports the file but creates duplicates each time.** That happens when the note guid changes between exports. LWT pins the guid to the LWT term ID, so this only occurs if you re-imported a `.apkg` from a *different* LWT install — guids are LWT-instance-specific.

**Re-importing my edited `.apkg` reports "skipped (term not found): N".** The original LWT term was deleted between export and re-import. New terms are not auto-recreated to avoid losing the language link; this will become an explicit prompt in a future iteration.

**My Anki scheduling resets every time I import the file again.** Anki only resets scheduling on full collection imports. Importing a `.apkg` deck merges by guid and preserves Anki's local schedule on existing notes.

**The summary says "skipped (no LWT id): N".** Those are notes you created directly in Anki (not via an LWT export). v1 ignores them; later we'll surface a "create as new term" flow with a language picker.

## See also

- [Term Scores](/reference/term-scores) — LWT's own SRS algorithm
- Issue [#228](https://github.com/HugoFara/lwt/issues/228) — design discussion and roadmap for richer Anki interop (real-time AnkiConnect bridge, etc.)
