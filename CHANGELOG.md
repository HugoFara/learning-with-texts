# Changelog

This project's changelog. Versions marked with "-fork" come from the community,
other versions come from the canonical LWT ("official" branch on Git).
For git tags, official releases are marked like "v1.0.0", while unofficial
ones are marked like "v1.0.0-fork".

## [Unreleased]

### Fixed

* **Dictionary-imported terms are visible to the reader** (#283). Importing a
  dictionary created one term per entry but never linked them to the words in
  your texts. The reader decides a word is unknown from that link rather than
  from whether the term exists, so imported terms stayed invisible: the word
  kept its unmarked colour, and marking it known failed — HTTP 500 from the
  term form, and silently nothing from the status shortcut. Since an import
  covers essentially the whole language, this affected more or less every word
  in a text. The import now links what it creates, a migration repairs the
  terms already imported, and adding a term that turns out to exist updates it
  instead of failing.

* **A failed API write no longer reports success** (#284). Handlers signal
  failure by returning an `error` payload rather than by throwing, and the
  routers passed that straight to `Response::success()` — so the request came
  back HTTP 200 and the interface, seeing a success, did nothing at all. Such
  a payload is now recognised and sent as 400. The body is unchanged, so
  anything reading the message out of it keeps working.

* **API error messages reach the interface** (#284). The API client only ever
  looked for `message` on a failed request, while the API sends `error` — so
  even a well-formed error was shown as a bare "HTTP 400: Bad Request". It now
  reads both.

## [3.5.0-fork] - 2026-08-27

### Added

* **Reviews are graded Again / Hard / Good / Easy** (#238). The review card's
  Wrong/Correct pair becomes the four grades FSRS needs, each showing when it
  would bring the term back before you commit to it. Keys 1-4 grade, as in Anki;
  setting a status outright moved to Shift and the number keys. All three
  correct grades still raise the term's status by one, so reading colours are
  unchanged — what differs is what the scheduler learns.

* **Anki decks arrive already scheduled** (#228, #238). A term LWT has scheduled
  now exports as a review card rather than a new one, carrying its due date,
  interval, review and lapse counts, FSRS memory state and review history, so
  Anki's own FSRS continues from LWT's estimate instead of starting over.
  Suspended terms keep their schedule. Nothing flows the other way.

### Changed

* **The database lives in a named Docker volume** (#275). `docker-compose.yml`
  no longer bind-mounts the database directory from the host, which on Windows
  forced `lower_case_table_names=2` and made every migration fail. **An existing
  install will look empty after upgrading**: the old data is still in
  `./lwt_db_data` but is no longer mounted. Dump the database before upgrading
  and import it afterwards.

* **The review queue follows the FSRS schedule** (#238). It picks and orders by
  each term's due date instead of the retired Leitner score. A term you have not
  graded keeps the schedule it already had, so nothing floods or empties on
  upgrade. Statuses 2 and 5 come up a day earlier than before, where the old
  formula rounded.

* **Similar terms suggest the parts of a compound, not its siblings** (#137).
  For a compound like *Geschwindigkeitsbegrenzung*, every word built on
  *Geschwindigkeit* used to crowd out *Begrenzung*, which explains the other
  half. Suggestions are now picked one at a time, and the term shrinks to what
  is still unexplained after each pick, so a candidate repeating an earlier
  suggestion scores near nothing.

* **Similar terms follow word families, not just spelling** (#136). Ranking on
  shared letter pairs alone cannot reach an irregular form — *bought* and *buy*
  have no letters in common. Terms sharing the searched term's lemma now come
  first, using whichever lemmatizer the language is configured with. Languages
  with the lemmatizer set to *none* are unaffected.

### Removed

* **The legacy Leitner scoring is gone** (#238). `WoTodayScore`,
  `WoTomorrowScore` and `WoRandom` cached a formula over a term's status and the
  date it changed; everything reads the due date now, so a migration drops all
  three. Nothing is lost — the values were derived, and every term keeps its
  schedule. The nightly recomputation across your whole vocabulary goes with
  them, and the vocabulary list's **Score** column becomes **Due**, counting
  days until the term returns.

### Fixed

* **Chinese texts could not be read** (#278). A Chinese language from the
  built-in preset produced a text with no clickable words at all. Chinese and
  Japanese presets now point at a real tokenizer — **jieba** for Chinese, MeCab
  for Japanese — falling back to character-by-character parsing where that
  tokenizer is not installed, so the text stays readable either way.

* **Choosing a parser in the language form did nothing** (#278). The Parser Type
  menu wrote its value to the database and no part of the parsing pipeline read
  it. The setting is now honoured, and the menu lists the parsers from
  `config/parsers.php`, so jieba and MeCab Python appear on an install that has
  them. Every language that exists today parses exactly as it did.

* **A text that parses into nothing now says so** (#278). A language whose *Word
  Characters* setting does not match its texts does not fail — it parses into
  nothing, and the text opens looking normal while refusing every click. The
  reading view and the check-text page now say what happened and link to the
  language's settings.

* **An incomplete schema no longer takes the review page and the vocabulary list
  down with it** (#275, #285). The review queue reads each term's due date from
  `term_schedule`; on an install where that migration failed, naming the missing
  table made MySQL reject the whole statement and both pages answered 500. They
  now fall back to the schedule a term's status implies — the same answer on
  such an install, since nothing there has been graded.

* **A failed migration gets retried again** (#285). Retries only happened when
  an upgrade brought new migration files, which on a fresh install never
  happens: the first run records all of them, so a failure stayed failed at one
  attempt until some later release added a file. Failures are now retried on the
  following requests, up to three attempts, and an upgrade restores that budget.
  This is what left installs without `term_schedule`.

* **Expected migration failures stopped being logged as failures** (#285). A
  healthy fresh install wrote ~178 `Migration failed:` lines while every
  migration succeeded, because legacy migrations rename tables a fresh install
  never had. Those now log as skipped, and `Migration failed:` means it.

* **Adding a term failed outright on a large vocabulary** (#277). Opening the
  term editor read every term of the language into memory to look for similar
  ones — fatal for a vocabulary seeded from a dictionary import, where clicking
  a word died on PHP's memory limit. Candidates are now selected in the
  database. Suggestions are unchanged.

## [3.4.2-fork] - 2026-08-16

### Fixed

* **Finishing the RSS feed wizard saved nothing** (#262). The wizard's last
  step posted the finished feed to `/feeds/edit`, and that route became a
  redirect to the feeds manager in 3.4.0 when the duplicated server-rendered
  feeds list was retired. A redirect discards the body, so the wizard ran to
  completion and produced no feed. Affects 3.4.0 and 3.4.1. The manual "add a
  feed" tab and the curated-source browser were unaffected.
* **The feed wizard showed no article to pick from.** Steps 2 and 3 render the
  fetched article so you can click the part to import, but the controller
  handed the view the extractor's whole result array instead of the article's
  HTML. The picker showed the word "Array", and the "Array to string
  conversion" notice behind it is fatal wherever PHP warnings are — so on those
  installs the wizard could not get past step 2 at all. Affects 3.4.0 and
  3.4.1. The article is also cached in the session again, as it was meant to
  be, so stepping back and forth no longer refetches it every time.

### Changed

* **The text editor and the feed forms save through `/api/v1`** (#262).
  Creating or editing a text now uses `POST /api/v1/texts` and
  `PUT /api/v1/texts/{id}`; the feed forms use `POST /api/v1/feeds` and
  `PUT /api/v1/feeds/{id}`. Both surfaces work against a configurable API base
  URL rather than the page origin, which is what a bundled mobile client needs.
  The text editor's "Check" button still asks the server for its parsing
  report, and the feed wizard's URL steps still drive the server-side session.

### Security

* **The language on a new text or feed is checked for ownership** (#262).
  `texts.TxLgID` and `news_feeds.NfLgID` have foreign keys to `languages`, but
  a foreign key proves the row exists, not that the caller owns it, and the
  form handlers passed the submitted value straight through. On a multi-user
  install a crafted request could file a text or feed under another user's
  language. The API endpoints these forms now use check ownership, and the
  form-POST routes that skipped the check are retired. Single-user installs
  were never affected.

## [3.4.1-fork] - 2026-08-12

### Fixed

* **Foreign keys earlier upgrades had already dropped are now put back**
  (#273). 3.4.0 stopped an upgrade from destroying constraints, but it could
  only preserve what a database still had: everything lost to a previous
  release stayed lost, because the migration that created it is recorded as
  applied and never runs again. A 3.3.0 database upgraded to 3.4.0 ended up
  with 14 of the 28 constraints the schema declares — no cascade from a text to
  its occurrences or sentences, no orphan protection on `word_tag_map`,
  `books`, `local_dictionaries`, `news_feeds` or `whisper_jobs`.

  `SchemaConstraints::FOREIGN_KEYS` now states what the current schema should
  have, and the upgrade adds whatever is absent. Both rehearsal databases — a
  healthy 3.3.0 and one broken the way #247 described — come out of the upgrade
  with all 28, and deleting a text once again takes its 2269 occurrences with
  it.

  Rows that a missing constraint would have prevented are already in these
  databases, so constraints are added under `FOREIGN_KEY_CHECKS = 0`: existing
  data is left alone and writes are gated from here on. A constraint InnoDB
  still refuses is logged and listed on the admin **Server Data** page rather
  than failing the upgrade.

  `SchemaConstraintsTest` reads the same definitions back out of
  `db/schema/baseline.sql` and `db/migrations/*.sql` and fails if the list and
  the schema disagree, so a new table's foreign keys cannot quietly skip the
  repair.

  **Timed on a large database** (3.05M occurrences, 155k terms, 760 texts,
  210 MB): the whole upgrade takes **8 to 10 seconds**, once, and later
  upgrades on the same database finish in under a second. Creating the
  constraints is not what costs — each takes well under a second, because the
  columns already carry a covering index. The cost is the column realignment,
  which rewrites the table, so all the columns of one table are now realigned
  in a single `ALTER` instead of one each: `word_occurrences` has four of them,
  and rewriting a three-million-row table four times took 27 seconds where one
  pass takes under ten. Should a column be un-alterable — a foreign key still
  pointing at it — the batch falls back to a statement per column rather than
  leaving the whole table unaligned.

## [3.4.0-fork] - 2026-08-12

### Fixed

* **A migration that failed was recorded as applied, so the schema silently
  stayed broken** (#247, #271). `Migrations::update()` logged each failing
  statement and wrote the migration down as applied anyway, so it was never
  retried and the gap only surfaced much later as a 500 in whatever feature
  needed the missing table.

  That is how installs ended up without `books` and `local_dictionaries`. Both
  declare a foreign key on `languages(LgID)` typed `INT(11) UNSIGNED`, and where
  `languages.LgID` was still `tinyint(3)` — the widening in
  `20251221_120000_add_inter_table_foreign_keys.sql` having never taken effect —
  InnoDB refused the whole `CREATE TABLE` with errno 150, even under
  `FOREIGN_KEY_CHECKS = 0`. EPUB import, Edit Language and dictionary import
  then crashed with *Table 'books' doesn't exist*.

  The runner now records the real outcome (`status`, `attempts` and `error` on
  `_migrations`) and retries a failure when a later upgrade may have fixed its
  prerequisite, capped so ordinary requests never re-run broken SQL. Reference
  columns are realigned with the key they point at, on the widest member of each
  family, so a column is only ever widened and no value can be truncated; that
  runs again after the migrations, which widen keys as they go. Errors that mean
  "nothing to do" (a legacy table a fresh install never had, a column baseline
  already creates) are not counted as failures. A repair migration recreates the
  tables and columns the failed migrations never created, so an affected
  database mends itself on first boot with no manual SQL. Anything still failing
  is listed on the admin **Server Data** page instead of only in `error_log`.

* **Upgrading dropped every foreign key and only put some back** (#272).
  `update()` clears the constraints before running migrations, because
  `ALTER TABLE MODIFY` is refused on a column one points at, but only *pending*
  migrations recreate theirs — and the migrations that created the rest were
  applied long ago. A 3.3.0 database upgraded to this release went from 14
  constraints to none. Nothing looked broken, which is why it went unnoticed,
  but cascade deletes and orphan protection were gone.

  The set is now captured before the drop and whatever the run did not recreate
  is restored after it, under the same `FOREIGN_KEY_CHECKS = 0` the migrations
  create their keys under. Keys whose table or column has since been renamed are
  skipped, since the migration that renamed them owns the new shape. Restoring a
  backup does the same around its own migration run.

* **`DELETE /api/v1/books/{id}` and `PUT /api/v1/books/{id}/progress` returned
  405 despite being fully implemented.** `Endpoints::ROUTES` is keyed by path,
  and a URL carrying an ID matches no key exactly, so `getMethodsForEndpoint()`
  falls back to the *first path segment*. The `books/chapters` and
  `books/progress` keys therefore never matched anything — they read as
  authorisation but were decorative — and the bare `books` entry allowed only
  `GET` and `POST`. Both requests were rejected before dispatch, so
  `BookApiHandler::routeDelete()` and `updateProgress()` were unreachable code.
  No caller had ever exercised them, so nothing regressed; the routes simply
  did not exist. `books` now allows all four methods.

  `EndpointMethodReachabilityTest` guards the class: it asserts real request
  shapes (with IDs) resolve through `Endpoints::resolve()`, and fails with an
  explanation pointing at the first-segment rule. It fails against the previous
  registry on exactly those two routes.

### Added

* **Books are shell-free.** `/books` and `/book/{id}` render entirely from
  `/api/v1` through the new `bookList` and `bookDetail` Alpine components. The
  views became scaffolds carrying a config blob — the query-string filter and
  page for the list, the book ID for the detail — and `BookController` no
  longer calls `BookFacade` to render either page. The detail page issues one
  request, not two, because `GET /books/{id}` already nests the chapters.

  The list keeps its behaviour: the language filter and pagination now update
  the address bar via `history.replaceState`, so a bookmarked `?lg_id=&page=`
  still opens where the reader left off, and deleting the last row of a page
  steps back rather than stranding the reader on an empty one.

  Psalm's `MixedArrayAccess` / `MixedAssignment` / `MixedOperand` suppressions
  for `src/Modules/Book/Views` were removed — with no row data left to walk,
  Psalm reported them as unnecessary.

### Removed

* **Seven orphaned view templates**, taking `src/**/Views` from 78 files to 71.
  None had a caller — checked against literal `include`s, `render('stem')`, and
  the `$this->viewPath . 'file.php'` concatenation the controllers use. No view
  is included under a computed name, so that enumeration is exhaustive.

  Five belonged to the **legacy AJAX review page, which no longer exists**:
  `ajax_review_config.php`, `review_interaction_globals.php`,
  `review_term_area.php`, `status_change_config.php` and `review_finished.php`
  — half the Review module's views. `/review` is the module's only route, and
  it renders `review_desktop.php`. `review_ajax.ts` and its 431-line test go
  with them: a "test" → "review" rename had moved the TypeScript onto
  `#term-review`/`#review-finished-area`/`#reviews-done-today` while the views
  still emitted `#term-test`/`#test-finished-area`/`#tests-done-today`, so the
  two halves had been mutually unreachable, not merely unrouted. Nothing
  imported the module's named exports; `modules/review/index.ts` pulled it in
  for side effects alone. No controller plumbing survived either — no PHP
  outside the deleted files builds `$reviewData`, `$statusChange`,
  `$testStatus`, `$waitTime`, `$dict1Uri`, `$langSettings` or `$tomorrowTests`.

  The other two were superseded and left behind: `Feed/Views/new.php` (the flat
  add-feed form; `/feeds/new` has gone through `wizard_step1.php` since the
  wizard landed) and `Vocabulary/Views/list_filter.php` (`list_alpine.php`
  carries the filter markup now). `word_list_filter.test.ts` matches the latter
  by name only — it covers the Alpine store `word_list_filter.ts`, so no
  coverage is lost.

* **The last of the frame-era result plumbing** (#266, #262): four result views
  still emitted a `<script type="application/json">` blob that
  `word_result_init.ts` applied to `window.parent.document` — the reading
  *frame*. No frameset has existed for some time (`frames-r` and `frame-l` are
  emitted nowhere), so for a same-tab navigation `window.parent` was the result
  page itself and every update found nothing. `#learnstatus`, which
  `updateLearnStatus` wrote into, is likewise emitted nowhere in the app.

  The confirmation pages stay; only the dead payload goes, along with the
  server-side work that existed solely to build it — two `QueryBuilder` queries
  and the sentence masking behind `edit_term_result`, and a
  `getTodoWordsContent()` call on each of four paths. `save_result.php` is gone
  entirely: its only remaining output was the message the controller already
  echoed a few lines above, so single-word saves had been printing it twice.

  Also removed: `word_result_init.ts`, the orphaned `updateLearnStatus`,
  `updateNewWordInDOM`, `updateBulkWordInDOM`, `updateTestWordInDOM` and
  `completeWordOperation` helpers, and the `data-lwt-cleanup-frames` marker in
  `show.php`. `updateExistingWordInDOM`, `updateWordStatusInDOM` and the
  `markWord*InDOM` pair stay — the reading view's keyboard shortcuts and popup
  actions call them after an API write.

  `upload_result.php` survives, because its config blob feeds a real Alpine
  component that fetches `/api/v1/terms/imported` rather than frame plumbing.
  (An earlier revision of this entry called it CSP-clean; it wasn't. Its
  `x-data` was an inline object literal taking arguments and its page picker
  called `parseInt`, both of which `@alpinejs/csp` rejects. Fixed by emitting
  the config as a JSON blob and moving the parse into a component method.)

* **Two more superseded legacy term routes** (#266, #262): `/vocabulary/term-hover`
  and `/word/set-all-status` each rendered a server-built page whose only real
  payload was a JSON blob applied to a reading *frame* that no longer exists.
  Both were superseded by the API — `POST /api/v1/terms/quick` for hover-create
  (used by `word_popover`, `word_modal` and `text_keyboard`) and
  `TextPositionApiHandler` for mark-all-known/ignored, which calls the very same
  `markAllWordsWithStatus` service. Neither route had a single inbound link.
  Removing them drops `hover_save_result.php`, `all_wellknown_result.php`, the
  `TermDisplayController::hoverCreate` and `TermStatusController::markAllWords`
  handlers, the orphaned `CreateTermFromHover` use case, and their
  `word_result_init.ts` initialisers.

* **The legacy multi-word edit page** (#266, #262): `/word/edit-multi` rendered
  a server-built HTML form that posted back to itself and answered with
  `edit_multi_update_result.php` — a view whose entire output was a JSON blob in
  a `<script type="application/json">` tag, which the reader then applied to the
  opener's DOM. The modern reader stopped using any of it when the frame reader
  was retired: selecting several words now opens the Alpine multi-word modal,
  which talks to `POST /api/v1/terms/multi` and `PUT /api/v1/terms/multi/{id}`.
  Nothing linked to the route any more, so the whole loop was unreachable.
  Removing it drops `MultiWordController`, its three views, the route, the
  `word_result_init.ts` handler, and the now-orphaned `updateMultiWordInDOM`
  and `WordContextService::exportTermAsJson`. No behaviour change — none of it
  could run.

### Security — phase 8 (XSS hardening: kill the "build markup by concatenation" pattern)

* **DOM XSS: Google Translate output was interpolated into `value="…"`**
  (`bulk_translate.ts`). The bulk-translate page turns each machine
  translation into an editable input; a quote in the translation closed the
  value attribute and everything after it became attributes on the input
  (`" onfocus="…" autofocus x="` is enough). Built through
  `document.createElement` now.
* **DOM XSS: multi-word markers were assembled as a markup string.**
  `expression_interactable.ts` serialised a term's attributes into
  `' k="v"'` pairs and `user_interactions.ts` parsed that back through
  `innerHTML` — the same broken "produce a quoted literal by concatenation"
  shape phase 7 removed from PHP. A term whose translation or romanization
  contains `"` escaped its attribute and could add an event handler; both
  fields are plantable through CSV or ePub import, so this was stored, not
  reflected. `newExpressionInteractable` now takes the attribute map it was
  always being handed and applies it with `setAttribute`, which is both safer
  and less code. The marker label is set as `textContent`, so
  `ExpressionService` sends a literal non-breaking space rather than the
  `&nbsp;` entity (fixing, incidentally, a missing `;` that had been there
  since the marker was written).
* **DOM XSS: language names reached both a text node and a data attribute
  unescaped** (`language_list.ts`). Setting a new default language rebuilds
  the other cards' buttons from names read out of the DOM. Names are
  user-supplied, so a language called `" onmouseover="…` injected a handler.
  The button is built with `setAttribute`/`dataset` and the title text is
  escaped.
* **DOM XSS: LibreTranslate connection errors were rendered as markup**
  (`language_form.ts`). The message can quote the `lwt_translator` parameter
  read back off the user-configured translator URL, so it is escaped now.
* **Hardening: `json_encode` into `<script type="application/json">` without
  `JSON_HEX_TAG`, in five more places** — the Glosbe and Google-Translate
  config blocks in `TranslationController` (which carry raw query
  parameters), `ExpressionService`'s two multi-word config blocks (which
  carry `WoTranslation` / `WoRomanization`), and `MediaService`'s
  audio-player config.

  **These were not exploitable.** PHP's `json_encode` escapes `/` as `\/`
  unless `JSON_UNESCAPED_SLASHES` is passed, so a payload emerges as
  `<\/script>`, which the HTML tokenizer does not accept as a closing tag.
  (An earlier entry in this changelog states that `json_encode` "does not
  escape `<`, `>`, or `/` by default" — the claim about `/` is wrong, and so
  is any conclusion drawn from it about a live breakout.) What the flag buys
  is independence from that default: `Home/Views/helpers.php` already passes
  `JSON_UNESCAPED_SLASHES` on a page config block, so the one thing standing
  between this pattern and a real breakout is a flag people do sometimes add.
* **Regression is now held by an invariant, not by vigilance.** This is the
  third sweep of this class, so
  `tests/backend/Core/JsonScriptBlockEscapingTest.php` walks every
  `json_encode` call in `src/`, brace-matches its arguments, and fails if a
  call whose output lands inside a `<script>` element omits `JSON_HEX_TAG`.
  API responses and outbound HTTP bodies are correctly ignored. It found the
  `MediaService` site, which reading had missed.
* **Regression tests**: hostile-input cases for the multi-word marker
  (attribute breakout and a markup label), the bulk-translate input value,
  and a language named `<img src=x onerror=…>` / `" onmouseover="…`. Each was
  checked against the pre-fix code to confirm it actually fails there.

### Changed

* **EPUB import no longer depends on an external Composer package** (#263):
  LWT now ships its own EPUB reader
  (`src/Modules/Book/Infrastructure/Epub/`), replacing `kiwilan/php-ebook`.
  It reads the OCF container, the OPF package document, and both flavours of
  table of contents — the EPUB 2 NCX and the EPUB 3 navigation document — which
  is everything the importer consumed. Dropping it also removed four transitive
  dependencies (`kiwilan/php-archive`, `kiwilan/php-xml-reader`,
  `smalot/pdfparser`, `spatie/temporary-directory`), taking `composer.lock` from
  20 packages to 15. Parsing relies only on `ext-zip` and `ext-dom`, both
  already required. XML is parsed with entity substitution off and network
  access disabled, so a hostile EPUB cannot mount an XXE attack.

  Note that this does not by itself remove the need to run `composer install`
  from a source checkout — `phpmailer/phpmailer`, `league/oauth2-google` and
  `thenetworg/oauth2-azure` are still required.

## [3.3.0-fork] - 2026-08-06

### Added

* **Import an Anki deck to seed known words** (#228): point LWT at a deck you
  already study in Anki and it creates the terms for you, working out how well
  you know each word from Anki's own scheduling — mature cards become *well
  known*, younger ones get a learning status, suspended ones become *ignored*.
  You pick the note type, which field holds the term, and the language, since an
  `.apkg` records none of that. Importing is create-only, so running it twice is
  safe. This is the direction #259 did **not** cover: that one round-trips LWT's
  own exports and silently matches nothing in a deck built in Anki. See
  `docs/reference/anki-deck-import`.

* **FSRS scheduling groundwork** (#238, phase 2a): LWT now records FSRS-6 memory
  state per term — stability, difficulty, due date and a review history.
  Nothing user-visible changes yet: the legacy scoring still drives the review
  queue and reading colours are untouched. Existing terms seed lazily from their
  status, so upgrading neither floods the queue nor costs anything on a large
  vocabulary. See `docs/developer/term-status-fsrs`.

* **Anki `.apkg` export and import** (refs #228): terms round-trip to Anki as a
  real `.apkg`, written and read by LWT itself — no genanki, no Python. Export a
  whole language or just the rows you ticked, from the vocabulary list. Notes
  carry a stable guid, so re-importing updates the terms they came from
  (translation, romanization, notes, tags) and a card suspended in Anki demotes
  a learning term to *Ignored*. Scheduling state is deliberately not exchanged.
  See `docs/reference/anki-export-import`.

### Fixed

* **Selecting several words in the reader produced a term named after hashes**:
  creating a multi-word term captured each word's `data_hex` identity token
  instead of its text, so the term came out as e.g.
  "e6967a5826fe441a 75e3090289e2955d" and the example sentence lost its
  `{...}` markers. `data_hex` used to be a reversible hex encoding of the word,
  which is why reading it once worked; it became a SHA-256 derived token in
  3.2.0 (#237) and this call site was never updated.

* **The "edit term" button in the review table went nowhere** (#266): it pointed
  at `edit_tword.php`, a filename that stopped being routed in v3, and opened it
  in a frame the reader no longer renders. It now links to `/word/edit-term`,
  the same route the Alpine review view already used.

### Removed

* **Dead legacy result-view plumbing** (#266): four result handlers
  (`delete_result`, `insert_wellknown_result`, `insert_ignore_result`,
  `delete_multi_result`) outlived the views that once fed them, and
  `word_status_ajax.ts` waited on a `#word-status-config` element no page has
  emitted since the frame reader was retired. Dropping them plus their now
  orphaned DOM helpers removes ~1000 lines. No behaviour change — none of it
  could run.

## [3.2.2-fork] - 2026-08-05

### Fixed

* **A single-language install could never browse Gutenberg or GDL**: the
  new-text page said "Please select a language above" while the navbar already
  showed one. `currentlanguage` is only written when the user picks from the
  navbar dropdown, and with exactly one language there is nothing to switch to,
  so it could never be set. The navbar looked right only because a `<select>`
  with no `selected` option displays the first one. `CurrentLanguage::resolveId()`
  is now the single source for the active language — the stored setting when it
  still points at an existing language, otherwise the first one the navbar lists.
  Read-only: a plain GET never writes the setting.
* **A CRLF term file broke tag-only imports**: `CompleteImportService`'s
  tags-only path split uploads on `PHP_EOL`, so a CRLF file on Linux left a stray
  `"\r"` on every last field and an LF file on Windows collapsed the upload into
  one line. Line endings are normalised before splitting — the last site of the
  defect fixed in #241, #248 and #249.
* **The term modal covered the word being added**: the word popover anchors
  itself above or below the clicked word, but the Add/Edit modal did not — Bulma
  centred it, so it regularly landed on the word whose sentence the user needs
  while typing a translation. The modal now measures the anchor word when it
  opens and takes the half of the viewport the word is not in, flipping the
  mobile sheet to the top edge when the word sits low. Best-effort rather than
  exact: a word near the middle of a short viewport can still be covered. With
  no anchor available it falls back to the previous placement.
* **The term-edit modal hid the text while reading** (#253): "Add"/"Edit" opened
  a full-viewport Bulma modal that covered the text being read. The backdrop
  dimming is gone, on mobile the card is a capped-height bottom sheet, and the
  form is split into tabs (Translation / Example / Notes / Tags) so it fits
  without scrolling.
* **One over-long headword aborted a whole dictionary import** (#250): a single
  293-character headword in FreeDict German-English failed its multi-row
  `INSERT` under `STRICT_ALL_TABLES` and ended the import, losing all 517,533
  other entries. Unstorable headwords are now skipped and counted rather than
  aborting the run, across every import path. A failed import no longer leaves a
  half-filled dictionary behind.
* **Japanese (MeCab) parsing produced no tokens on Windows**: both MeCab output
  readers split on `PHP_EOL`, but the terminator comes from MeCab, not the
  host — on Windows the output stayed one line and every token collapsed into one.
  Both readers now normalize line endings first.
* **Restoring a backup wiped the database and reported success** (#249): on
  Windows, `Restore::restoreFile` split on `';' . PHP_EOL` while LWT writes
  `";\n"`, so no statement ever matched — the restore dropped every table and
  replayed nothing. Line endings are normalized before splitting, and a dump that
  parses to zero statements is now refused *before* any table is dropped.
* **Restore left the database in pieces when foreign keys were enabled** (#249):
  `CREATE TABLE` statements carry their `FOREIGN KEY` clauses but replay in
  backup-table order, not dependency order, so 11 of 12 failed with errno 1215.
  Checks are suspended for the replay and enforced once every table exists.
* **Choosing a restore file did nothing** (#249): the inline `@change` handler
  used optional chaining, which `@alpinejs/csp` cannot parse. Moved into the
  `backupManager` component as `selectFile()`.

### Changed

* **Three further security advisories** published after the bumps below, all
  fixed within existing constraints. `guzzlehttp/guzzle` 7.15.1 → 7.15.2 clears
  CVE-2026-69246 (high — a noncanonical host bypasses host-based checks) and
  CVE-2026-69245 (medium — a noncanonical cookie domain keeps subdomain scope);
  guzzle reaches LWT through `league/oauth2-google`, so both affect the running
  application. On the build side the `brace-expansion` override floor moves from
  `>=5.0.8` to `>=5.0.9`: GHSA-rgw5-rvv9-x895 bypasses the earlier
  CVE-2026-14257 mitigation and covers every version up to 5.0.8, so the floor
  set for that advisory no longer sufficed. `composer audit` and `npm audit` are
  both clean again.
* **Dependency security bumps** clearing all eight open Dependabot alerts plus
  two more from `npm audit` — ten advisories across five packages, all within
  existing constraints. Affecting the running app: `guzzlehttp/guzzle`
  7.12.1 → 7.15.1 (five advisories) and `guzzlehttp/psr7` 2.12.1 → 2.13.0
  (CVE-2026-59882), both reached via `league/oauth2-google`. Build/test only:
  `brace-expansion` → 5.0.8, `linkify-it` → 5.0.2, `postcss` → 8.5.23.
  `composer audit` and `npm audit` are clean.
* **Routine dependency refresh** inside the declared ranges: `phpmailer`,
  `league/commonmark` (2.9.0), `oauth2-azure`, `phpunit`, plus Symfony and amphp
  transitives on the PHP side; `vite` (8.2.0), `vitest`, `eslint`, `cypress`
  (15.20.0), `lucide` (1.28.0), `@alpinejs/csp`, `typescript-eslint` (8.66.0),
  `globals`, `prettier` and `vue` on the JS side. Held at their current majors:
  `typescript` (6 → 7), `purgecss` (4 → 8), `@types/node` (25 → 26).
* **Word-status model is now a single source of truth** (#238, Phase 1): the
  `TermStatus` value object owns the abbreviation, CSS class, colour, order and
  predicates, replacing scattered `[1,2,3,4,5,98,99]` literals and `=== 98`-style
  checks across the Review, Vocabulary and Admin modules. Exposed to the frontend
  once via `GET /api/v1/settings/status-definitions`, so the duplicated
  TypeScript status tables now resolve from a single store. No behaviour or
  wire-format change; scheduling formulas are untouched.
* **Single `data_hex` word identity in the reading view** (#237): word
  occurrences carry their term identity solely as `data_hex`, and the redundant
  `TERM<hex>` class is dropped. The token is now a SHA-256-derived hex string —
  pure `[0-9a-f]`, so the occurrence-lookup regexes are correct by construction.
  Replaces the 2011 `¤`/hex encoder whose per-byte vs. per-codepoint confusion
  PHP 8.5 surfaced by deprecating `ord()` on multi-byte strings. No API or
  styling change.

## [3.2.1-fork] - 2026-06-30

### Fixed

* **Database error on first boot with CRLF schema files** (#241): a fresh
  install could fail to start with "Internal Server Error - A database error
  occurred." (MySQL error 1064) while applying `db/schema/baseline.sql`.
  `SqlFileParser` split statements on `';' . PHP_EOL` — which is `";\n"` on a
  Linux host — so a schema file with Windows (CRLF) line endings was never
  split, and the whole file was sent to MySQL as a single multi-statement
  query that it rejected. The parser now normalizes CRLF/CR to LF before
  splitting, so parsing no longer depends on the file's origin OS.
* **Mixed-content asset blocking behind a TLS-terminating reverse proxy**
  (#240): the Content-Security-Policy now emits `upgrade-insecure-requests` on
  HTTPS connections, so any stray `http://` subresource is transparently
  upgraded to `https://` instead of being blocked as mixed content. This is
  what left the language-picker (and other) scripts unloaded on installs whose
  proxy doesn't propagate the scheme. The directive is omitted over plain HTTP
  so local development (e.g. `http://localhost:8000`) isn't forced to upgrade.

## [3.2.0-fork] - 2026-06-23

### Added

* **Global Digital Library as a text source** ("Kids' Library"): browse and
  search openly-licensed (CC-BY/CC-BY-SA) children's and early-grade readers —
  including StoryWeaver content — directly on the New Text page, filling the
  gap in easier texts that Gutenberg and Wikisource leave. A new `GdlClient`
  talks to GDL's current WordPress JSON API (the legacy OPDS/`book-api` hosts
  were decommissioned), and `GdlImportService` downloads each book's ePUB,
  extracts the reading text via the Book module's parser, and rejects
  image-only picture books with too little readable text. Difficulty tiers
  come from GDL's per-book reading level. New endpoints
  `GET /api/v1/texts/gdl-search` and `POST /api/v1/texts/extract-epub-url`.
  The home page also shows GDL suggestions with **beginner-aware ordering**:
  readers with a small vocabulary in the current language see the easy GDL
  readers above the Gutenberg classics, advanced readers see them below
  (`GET /api/v1/texts/reader-level`).
* **StarDict dictionary uploads via archives** (#233): the import form now
  accepts `.zip`, `.tar.gz`, `.tar.bz2`, `.tar.xz`, and `.tgz` containing
  the StarDict triplet (`.ifo` + `.idx` + `.dict`/`.dict.dz`). FreeDict
  downloads import directly. Extraction is shared with the curated-import
  flow via a new `ArchiveExtractor` service (zip-bomb cap, path-traversal
  guard, automatic cleanup).
* **Design proposal: single `data_hex` word identity** (#237). New developer
  doc (`docs-src/developer/word-identity-data-hex.md`) proposing to replace the
  reading-view `TERM<hex>` CSS class-as-index with a `data_hex` attribute
  selected via `[data_hex]`, and to hash the identity token (retiring the legacy
  `¤`/Latin-1 `toClassName` encoding flagged by the PHP 8.5 `ord()` deprecation).
  Proposal only — implementation deferred until after the next release.
* **Design proposal: term status model + FSRS scheduling** (#238). New developer
  doc (`docs-src/developer/term-status-fsrs.md`) proposing to (1) make the word-status
  model (1–5/98/99) a single source of truth — the scattered literals and ~6
  duplicated frontend tables collapse onto the existing `TermStatus` value object —
  and (2) align review scheduling with Anki/FSRS by separating display familiarity
  from memory state (Stability/Difficulty/Retrievability, 4-grade reviews), deriving
  the 1–5 reading colours from memory strength. Proposal only — implementation
  deferred until after the next release.
* **Optional email at registration**: the username is the unique identity, so
  sign-up now needs only a username + password. Email becomes an optional
  recovery channel.
* **One-time recovery code** for accounts created without an email: shown once
  at sign-up (and via the mobile client), with a new "reset with recovery code"
  flow at `/password/recover` that rotates the code on use.
* **Self-hosted ALTCHA proof-of-work captcha** on registration — a
  privacy-friendly, dependency-free bot deterrent (no third-party service, no
  user puzzle). Configurable via `ALTCHA_ENABLED` / `ALTCHA_HMAC_KEY`. Paired
  with a hidden honeypot field and a submission-timing check.

### Security (XSS hardening: JSON-into-`<script>` breakout + DOM sinks)

* **`json_encode` into `<script type="application/json">` blocks without
  `JSON_HEX_TAG | JSON_HEX_AMP`**. JSON does not escape `<`, `>`, or `/`
  by default, so a `</script>` inside any user-controlled field closes
  the config block early and lets following markup execute. The codebase
  already standardises on `JSON_HEX_TAG | JSON_HEX_AMP` for these blocks;
  a cluster of views had missed it. Brought the stragglers in line:
  the text-edit config (carries the user-settable
  `LgGoogleTranslateURI`), the language form and wizard, the home
  dashboard warnings (last-read text title + language name), the user
  statistics charts (language names), the three Review interaction/ajax/
  status-change config blocks, the text-edit media selector, and the two
  text-check config blocks in `TextParsingPersistence` (those were
  already pre-escaped server-side, fixed for consistency so the invariant
  greps clean). The previously-protected result views (save/delete/
  set-status/etc.) were already correct and untouched.
* **DOM XSS: term sentence rendered without escaping** in the word popup
  (`word_popup_interface.ts`). `term.sentence` is the raw `WoSentence`
  returned by the term-details API; the `{term}`→`<b>` formatter ran on
  it directly while the adjacent romanization/translation/notes fields
  were all escaped. A stored term sentence containing HTML (plantable via
  CSV/ePub import) executed on popup. Now `escapeHtml` runs first, then
  the `{term}` markers become trusted markup.
* **DOM XSS: single-word tooltip built from raw attribute data**
  (`native_tooltip.ts`). The multi-word branch already escaped its
  fields, but the single-word branch concatenated `data_text`,
  `data_rom`, and `data_trans` (decoded back to raw text by
  `getAttribute`) straight into the tooltip HTML. Escaped all three; the
  annotation highlight escapes consistently so only the controlled red
  `<span>` is inserted.
* **DOM XSS + CSP: Glosbe translations used inline `onclick` with
  unescaped third-party text** (`translation_api.ts`). The API phrase/
  meaning text was interpolated into `onclick="addTranslation('…')"` and
  into element text without escaping — a string-literal breakout and a
  stored/poisoned-response XSS. Switched to the existing delegated
  `data-action="add-translation"` + `data-word` handler (no inline
  handler, CSP-safe) with `escapeHtml` on both the attribute and the
  visible text.
* **Regression tests**: `translation_api.test.ts` adds a hostile-Glosbe
  case asserting no `onclick`, the `data-action` handler is used, and an
  attribute-breakout payload (`"><img onerror=…>`) produces no live
  element. `native_tooltip.test.ts` adds a case feeding hostile
  `data_text`/`data_rom`/`data_trans` and asserts the rendered tooltip
  contains no live `<img>`/`<svg>`/event-handler element.

### Security — phase 7 (XSS hardening: kill `addslashes`-into-attribute pattern)

* **Feed browse view embedded hostile RSS URLs into an Alpine
  `@click.prevent` expression via `addslashes(htmlspecialchars(...))`**.
  The intent — produce a JS string literal — is broken: by the time
  `addslashes` runs, `htmlspecialchars` has already encoded any `'`
  as `&#039;`, so `addslashes` finds no quotes to escape; the browser
  later HTML-decodes `&#039;` back to `'` inside the attribute value,
  and that bare apostrophe terminates the JS string. An attacker who
  controls an RSS feed the user subscribes to (or who plants articles
  via a compromised public feed) can ship an audio/article URL like
  `https://x.com/');…;//` and break out of the literal. Whether real
  JS execution follows depends on `@alpinejs/csp`'s expression parser,
  but the pattern is fundamentally wrong. Fixed by reading the URL
  from `$el.getAttribute('href')` — the URL flows through DOM-string
  context the whole way, so HTML escaping at the attribute boundary
  is the only escape needed.
* **Same anti-pattern in three `@submit="if(!confirm('…'))"` forms**
  (Book index, Book show, Dictionary list). Translation strings are
  dev-controlled today so no live XSS, but any future apostrophe in
  the message silently breaks the confirm prompt and the form
  submits unconditionally. Switched to `data-confirm` attribute +
  `$el.dataset.confirm` so the message flows through DOM context.
* **Dependency vuln scan**: `composer audit` and `npm audit
  --omit=dev` both report 0 advisories. Documented for the record.
* **Regression test**:
  `feed_browse_component.test.ts` openPopup suite now passes an
  `HTMLAnchorElement` and includes a hostile-URL case
  (`https://attacker.example/audio?x=');alert(1);//`) to assert the
  href flows through verbatim.

### Security — phase 6.3 (open-redirect + OAuth state hardening)

* **Open redirect via `auth_redirect`**: `AuthMiddleware::redirectToLogin`
  (and the matching path in `AdminMiddleware`) stores raw
  `$_SERVER['REQUEST_URI']` in `$_SESSION['auth_redirect']`; the post-login
  flow read it back and redirected without validation. A crafted entry
  URL like `https://lwt.example.com//evil.com/phish` becomes
  `REQUEST_URI = //evil.com/phish`, which the browser interprets as
  protocol-relative and follows to `evil.com`. `AuthFormDataManager::
  getRedirectUrl` now refuses anything that isn't a single-`/` same-origin
  path (rejects protocol-relative `//…`, backslash bypass `/\…`, absolute
  URLs, and `javascript:` / `data:` schemes), falling back to the supplied
  default. The store-side stays as-is — the validator is the chokepoint.
* **OAuth state comparison was not timing-safe** in Google and Microsoft
  callback handling: `$state !== $storedState`. Switched to
  `hash_equals($storedState, $state)`. State is consumed in one shot so
  the practical exploit window is tiny, but `hash_equals` is the standard
  choice and removes the question entirely.

### Security — phase 6.2 (invalidate auth tokens on password change/reset)

* **`ChangePassword` and `CompletePasswordReset` updated only the
  password hash** — they did not touch the user's remember-me
  cookie token or API bearer token. An attacker who set a
  remember-me cookie on a victim's shared/compromised browser, or
  who already exfiltrated the API token, retained access *after*
  the victim "secured" the account by changing or resetting their
  password. The reset path is the most acute case since it's the
  canonical "my account was compromised" signal. Both use cases
  now call `User::invalidateRememberToken()` and
  `invalidateApiToken()` before persisting. The current PHP
  session of the actor doing the change is left alone (they keep
  their own session); every other long-lived credential is wiped.
* **Regression tests**: `ChangePasswordTest::testSuccessful
  ChangeInvalidatesRememberAndApiTokens` and
  `CompletePasswordResetTest::testExecuteInvalidatesRememberAnd
  ApiTokensOnSuccess` start a user with both tokens populated and
  assert both are null after the use case runs.

### Security — phase 6.1 (CSRF on auth endpoints)

* **`POST /login`, `/register`, `/password/forgot`, `/password/reset`
  had no CSRF middleware** — only `AuthRateLimitMiddleware`. The
  matching forms already emitted `_csrf_token` via
  `FormHelper::csrfField()`, so the token was there but never
  validated; the field was effectively theatre. The login gap
  enabled login-CSRF (force a victim to log in as the attacker so
  the victim's subsequent vocabulary and reading land in the
  attacker's account); the others enabled forced
  registration / reset requests. Added `CsrfMiddleware` to all
  four POST routes. The pre-login session token is lazily created
  by `CsrfMiddleware::getToken()` when the form view calls
  `FormHelper::csrfField()`, so the same session carries the
  token through to the POST.
* **`POST /email/resend-verification` had no middleware at all**
  (the controller gated auth inline). Added
  `AuthRateLimitMiddleware` + `CsrfMiddleware` so cross-site forms
  cannot make a logged-in victim spam their own inbox.
* **Regression test**: `RoutesTest::testAuthPostRoutesEnforceCsrf`
  asserts that `/login`, `/register`, `/logout`,
  `/email/resend-verification`, `/password/forgot`, and
  `/password/reset` all carry `CsrfMiddleware`. A future route
  edit that drops the middleware fails the test instead of
  silently regressing.

### Security — phase 5 (SSRF hardening)

* **RSS feed URL was unvalidated** in `RssParser::parse` /
  `detectAndParse` / `getFeedTitle`. `LIBXML_NONET` only blocks
  libxml's external-entity loader, not the initial document fetch
  through PHP's stream wrappers — so an authenticated user could
  point a feed at `http://127.0.0.1:9200/_cat/indices`,
  `http://169.254.169.254/latest/meta-data/`, or `/etc/passwd` and
  read it through the feed preview. The parser now pre-fetches via
  `UrlUtilities::safeHttpGet` and feeds the bytes to `loadXML`.
  New `parseXml` / `detectAndParseXml` / `getFeedTitleFromXml`
  methods expose the XML-string variant for in-memory callers.
* **Redirect-bypass in five fetch sites**:
  `WebPageExtractor::fetchPage`,
  `ArticleExtractor::fetchArticleContent` / `handleRedirect` /
  `detectCharsetFromHeaders`, and `GutenbergClient::fetchText` /
  `fetchJson` all ran with `follow_location => true`.
  `validateUrlForFetch` passed for a public host that then 302'd
  the fetch into `127.0.0.1`. All sites now route through
  `safeHttpGet`, which walks `Location` headers manually and
  re-validates each hop. `WiktionaryEnrichmentService::httpGet`
  gets the same treatment as defense-in-depth.
* **New `UrlUtilities::safeHttpGet(string $url, array $opts): ?string`**
  centralizes the safe-fetch pattern: validate the URL, fetch with
  stream-level redirects disabled, walk `Location` manually with
  per-hop re-validation, cap response size and redirect count.
  Companion `resolveRelativeUrl` handles absolute, `//host/path`,
  and `/path` Location shapes. See
  [Security followups](docs-src/developer/security-followups.md)
  for known residuals.

### Security — phase 4.3 (cross-table authz guards)

* **`POST /api/v1/local-dictionaries` and `POST .../import-curated`
  accepted any `language_id`** in multi-user mode. The `LdLgID`
  foreign key has no per-user constraint, so an authenticated user
  could create dictionaries (or trigger curated imports) pinned to
  another user's language. Both handlers now reject the request
  with "Language not found or access denied" if the supplied LgID
  doesn't belong to the caller.
* **`POST /api/v1/feeds` and `PUT /api/v1/feeds/{id}` had the same
  mass-assignment gap on `NfLgID`**. Creation now requires the
  caller to own the supplied langId; update only re-verifies when
  the request actually reassigns the column, so vanilla name/URI
  edits don't pay the extra query.
* **`GET /api/v1/sentences-with-term` accepted an arbitrary
  `language_id`**. Per-user LgIDs are unique in practice so the
  downstream query couldn't return another user's data, but
  accepting a foreign id leaked existence (response shape differs
  between "unknown language" and "language not yours"). The
  request is now refused 403 if the LgID isn't owned by the
  caller, making the policy verifiable rather than incidental.
* **New `Globals::languageBelongsToCurrentUser(int $langId): bool`**
  centralizes the check: a no-op in single-user mode, a
  user-scoped `EXISTS` on `languages` in multi-user mode. Used by
  all three handlers above.

### Security — phase 4.2 (upload defensive depth)

* **Centralized upload filename sanitization** at the
  `InputValidator::getUploadedFile()` boundary. Every downstream
  consumer — dictionary import, EPUB parser, error messages, logs —
  now receives a `basename()`-stripped, bidi-override-free name
  without having to remember to call it. The Whisper handler's
  bespoke `sanitizeFilename` now delegates to this shared helper.
* **`extractTar()` no longer extracts-then-counts**. The legacy path
  shell-exec'd `tar xf` and only counted entries after extraction —
  a 10 GB tar with millions of files exhausted the worker's
  filesystem before it could bail. Now `tar -tf` lists entries
  first; we reject the archive if it exceeds `MAX_FILES` (500) or
  contains absolute paths or `..` components, and extraction adds
  `--no-same-owner --no-same-permissions` to drop archive-supplied
  ownership/perm bits.
* **Subtitle import size cap**: SRT/VTT used `file_get_contents()`
  with no upper bound — a hostile multi-GB upload OOM'd the worker.
  Capped at 10 MB (a feature-length movie's SRT is ~50 KB).
* **JSON dictionary import size cap**: `parseStreaming()` silently
  delegates to `parseSimple()` (no real streaming parser in tree),
  so any file gets fully loaded into memory before `json_decode`.
  Added a `MAX_FILE_SIZE` of 100 MB enforced in `validateFile()`,
  with a TODO comment on `parseStreaming()` so the cap is revisited
  if real streaming lands.
* **CSV importer now handles BOM/encoding correctly**. A UTF-8 BOM
  left in place poisoned the first cell of the first row, silently
  yielding zero entries. UTF-16 input (Excel "Save As CSV" on
  Windows) used to fall through `fgetcsv` and corrupt every value.
  New `skipBom()` helper strips UTF-8 BOMs and rejects UTF-16/UTF-32
  inputs with a clear message asking the user to re-export as
  UTF-8. Applied in `parse()`, `detectDelimiter()`, and
  `detectHeaders()`.
* **`ImportUtilities::createTempFile()` registers a
  `register_shutdown_function` unlink**. The controller's
  `finally { unlink(...) }` block doesn't run after a fatal error,
  so the legacy temp files leaked indefinitely on parse failures.
  The shutdown hook is idempotent — happy-path cleanup is still
  the normal cleanup path.

### Security — phase 4.1 (audio robustness)

* **Audio position lost on mobile tab close**: the save was tied to
  `beforeunload`, which Safari and Chrome mobile cancel during
  teardown — long listening sessions silently lost progress. Switched
  to `pagehide` + `navigator.sendBeacon` so the final write survives
  navigation, tab close, and the bfcache. A debounced periodic save
  (every 5s of playback) also checkpoints progress so a crash can't
  swallow an hour of reading.
* **`TxAudioPosition` truncated to int despite FLOAT column**: every
  save lost up to a second of precision. Both
  `TextPositionApiHandler::saveAudioPosition` and the routing layer
  now thread the position through as `float`. NaN / negative /
  >24h values are clamped to a sane range so a buggy player can't
  poison the column.
* **Whisper upload validation only trusted the client-supplied
  extension and Content-Length**. Re-check the MIME via
  `finfo_file()` against an `audio/*`, `video/*`, `application/ogg`
  allowlist and use the on-disk `filesize()` instead of `$file['size']`
  before queuing the NLP job. Filenames are sanitized with `basename()`
  + a strip of C0/C1 controls and U+202A..U+202E / U+2066..U+2069
  bidi-overrides ("trojan source" payload) before they reach the
  NLP service via CURLFile.
* **No rate limit on `/api/v1/whisper/transcribe`**: a runaway script
  could saturate the model server. The shared `RateLimitMiddleware`
  is now mounted on the entire `/api/v1` prefix (in front of CSRF so
  unsigned floods drop fast) and gets a new endpoint type 'whisper'
  capped at 5 kickoffs per 15 minutes per IP. Polling endpoints
  (`/whisper/status/{id}`, `/whisper/result/{id}`) stay on the
  general 100/min limit so long-running jobs can be polled freely.
* **`MediaService` audio detection used `substr($path, -4)`**, which
  mis-classified `.mp3` (3-letter) as not-audio and skipped `.opus`
  / `.aac` entirely. Switched to a case-insensitive `pathinfo()`
  check that also strips query strings, so signed CDN URLs like
  `audio.mp3?token=...` resolve correctly.

### Fixed

* **Navbar hamburger hidden under the status/camera bar** on edge-to-edge
  phones (and in the Android app shell). The navbar now respects the device
  safe-area insets (`viewport-fit=cover` + `env(safe-area-inset-*)`), so the
  burger and the mobile drawer sit below the status bar.
* **Login/registration field icons appearing to the right of the input**
  instead of inside it on the left. PurgeCSS was stripping Bulma's
  `.icon.is-left`/`is-right` (used only in PHP views); these positional helpers
  are now safelisted.
* **Misspelled settings key in TTS / translation lookups**. Two reads in the
  Dictionary module used `Settings::get('currentlangage')` (missing the `u`)
  instead of the canonical `currentlanguage`, so they silently received an empty
  value — breaking the current-language TTS voice lookup and the term-translation
  header's language context. Corrected both
  (`TranslationService::getCurrentLanguageTtsVoice`, `TranslationController`).
* **PHP 8.5 deprecation warnings**. Running under PHP 8.5 emitted 429 PHP
  deprecations. The bulk (428) were redundant `ReflectionMethod`/
  `ReflectionProperty::setAccessible(true)` calls in the test suite —
  a no-op since PHP 8.1 and deprecated in 8.5; reflection can read and
  invoke private members without them, so the calls were removed. The one
  production case was `StringUtils::toClassName()` passing a (potentially
  multi-byte) `mb_substr` character to `ord()`; switched to `ord($char[0])`
  to make the existing first-byte behavior explicit. (A `TODO` flags a
  possible later move to `mb_ord()` for true codepoints, which would be a
  deliberate behavior change to generated CSS class names.)
* **Bulk vocabulary actions and texts/archived-texts bulk-action 403'd
  on CSRF**: `submitExportForm` in `word_list_app.ts` and
  `handleMultiAction` in `texts_grouped_app.ts` /
  `archived_texts_grouped_app.ts` built dynamic POST forms (for Anki/TSV
  export, multi-select status change, etc.) and submitted them without
  a `_csrf_token` field. `CsrfMiddleware` rejected the requests. Inject
  the token from the meta tag (same fix pattern as `handlePostAction`
  in commit 8c1fc9b60). The feed wizards (step 1/2/3) and other static
  forms were unaffected — they get `FormHelper::csrfField()` server-side.

* **Saving a new text in a language with multi-word expressions threw 500**
  in multi-user mode: `TextParsingPersistence::registerSentencesTextItems`
  (and its twin in `ParsingCoordinator`) built the multi-word
  `INSERT INTO word_occurrences ... UNION ALL ...` with six positional
  bindings, then concatenated two `UserScopedQuery::forTablePrepared()`
  calls into the SQL — each of which injects ` AND WoUsID = ?` AND
  appends `$userId` to the **end** of the bindings array. The two new
  `?` placeholders land at SQL positions 3 and 8, but the corresponding
  user IDs go to bindings 7 and 8, shifting every value after position
  2 by one slot. The result swapped `Ti2LgID`/`Ti2TxID` in the UNION
  branch and triggered an FK violation on `word_occurrences`,
  surfacing as "A database error occurred" after the text row had
  already been inserted (so the text appeared in /texts with zero
  sentences). Rebuilt the SQL/bindings in lockstep so each
  `forTablePrepared()` runs at the exact position its injected `?`
  occupies, keeping placeholders and values aligned. Single-word
  language paths (no multi-word expressions yet) were unaffected and
  worked by accident — only one trailing injection, correct order.

* **Saving 2+ tags on a term/text threw "An unexpected error occurred"**:
  Tagify in the term and text edit forms serializes every selected tag
  into a single comma-joined string posted under one
  `TermTags[TagList][]` / `TextTags[TagList][]` field. The
  `saveWordTagsFromForm` / `saveTextTagsFromForm` /
  `saveArchivedTextTagsFromForm` services treated each array entry as a
  single tag name and passed it straight to `Tag::create`, which
  enforces a 20-char limit and then threw `InvalidArgumentException`
  whenever two tags' combined stripped length exceeded the cap. Split
  each entry on commas in the service layer so individual tags are
  created and linked correctly regardless of how Tagify serializes.

* **Multi-word term selection captured inline translation hints**:
  selecting "petit" (translated as "small") in the reader pre-filled
  the new-expression modal with `petitsmall` because
  `text_multiword_selection.ts`/`getSelectedText` walked `el.textContent`
  on `.word` spans — which include a child `<span class="word-ann">`
  that renders the translation hint. Extract the surface form via the
  `data_hex` attribute (fallback: first text node) so the modal
  pre-fills with the clean term text.

* **Archive / Unarchive from the texts cards 403'd on CSRF**:
  `handlePostAction` in `texts_grouped_app.ts` and
  `archived_texts_grouped_app.ts` built an empty form and submitted
  it. `CsrfMiddleware` rejects any POST/PUT/DELETE/PATCH without an
  `_csrf_token` field or `X-CSRF-TOKEN` header. Inject a hidden
  `_csrf_token` input read from the meta tag before submitting.

* **Gutenberg / Library coverage stats silently failed**: same
  `data.data?.X` mismatch as Whisper. `text_suggestions.ts` read
  `/api/v1/texts/library-preview` as
  `data.data && !data.error → data.data.total_words …`, but
  `Response::success` sends payloads flat. Every preview hit the
  `else` branch and `book.statsError = true`, so every book on
  the Gutenberg suggestions panel showed a permanent stats-load
  error instead of the coverage breakdown. Switched the guard
  to `!data.error && data.total_words !== undefined` and read
  the fields directly.

* **Whisper transcription failed: ffmpeg missing in NLP container**:
  Once the registration + shape + CSRF fixes let a transcribe job
  actually reach the Python service, `whisper.transcribe` died with
  `[Errno 2] No such file or directory: 'ffmpeg'`. openai-whisper
  shells out to ffmpeg for audio decoding; the lwt_nlp Dockerfile
  installed mecab + curl but not ffmpeg. Added ffmpeg to the apt
  install.

* **Whisper response shape mismatch — frontend always saw
  "unavailable"**: every fetch in `whisper_import.ts` read
  `data.data?.field`, but `Response::success()` sends payloads
  flat (no `{data: …}` wrapper). The availability probe parsed
  `{"available":true}` as `data.data?.available === true` →
  `undefined === true` → `false`, and the page permanently showed
  "Whisper transcription is not available." Same shape mismatch
  hid the languages list, the transcribe job_id, the status
  poller, and the result text. Switched all five reads to use
  `data.field` directly.

* **YouTube transcript API unregistered**: same class of bug as
  whisper — `'youtube' → YouTubeApiHandler::class` in HANDLER_MAP
  but no entry in `Endpoints::ROUTES`. Added `youtube`,
  `youtube/configured`, `youtube/video` so the configured-check
  and video-info lookups dispatch correctly.

* **Whisper transcription unreachable — endpoints unregistered**:
  `ApiV1::HANDLER_MAP` mapped `'whisper' → WhisperApiHandler::class`
  and the handler implemented `routeGet` / `routePost` / `routeDelete`,
  but `Endpoints::ROUTES` had no `'whisper'` entry. `/api/v1/whisper/*`
  always 404'd before reaching the handler, so the audio-import flow
  on the text-edit form never even started its availability check.
  Added the whisper paths to the routes registry (GET for available /
  languages / models / status / result, POST for transcribe, DELETE
  for job/{id}; first-segment fallback resolves the dynamic
  `{job_id}` subpaths).

* **fetch-based DELETE/PUT/POST silently 403'd from CSRF**: the
  shared API client (`apiPost`, `apiPut`, `apiDelete`) and the
  local `handleRestDelete` callers (texts grouped app, archived
  texts grouped app, feed index, whisper, text-print annotation,
  mark-all-wellknown/ignored) all sent the mutating fetch without
  an `X-CSRF-TOKEN` header, so CsrfMiddleware rejected them.
  Form-based POSTs were fine because the hidden `_csrf_token`
  field was already part of the form. Added a CSRF meta tag
  (`<meta name="csrf-token">`) to the page head and a `getCsrfToken()`
  helper exported from the shared API client; the client and all
  direct fetch callers now attach `X-CSRF-TOKEN` on state-changing
  requests.

* **Quick translation insert 500'd in multi-user mode**: third site
  of the INSERT-misuse-of-forTablePrepared pattern.
  `TermTranslationApiHandler::addNewTermTranslation` now uses
  `getUserIdForInsert` to add `WoUsID` to the column/value list
  instead of appending the meaningless `AND WoUsID = ?` fragment
  to `VALUES(...)`.

* **`INSERT IGNORE INTO text_tags` 500'd when importing a feed
  article in multi-user mode**: same INSERT-misuse-of-forTablePrepared
  pattern as the term-create bug. `TextCreationAdapter::createText`
  now injects `T2UsID` into the column/value list when multi-user
  is on, so the per-user composite primary key is respected.

* **Saving a new term via the word popup's full edit form 500'd in
  multi-user mode**: `TermCrudApiHandler::createTermFull` appended
  `UserScopedQuery::forTablePrepared('words', …)` (which yields
  `" AND WoUsID = ?"`) to an `INSERT INTO words … VALUES (…)` —
  that fragment is meaningless after `VALUES` and MariaDB rejects
  it. INSERT user-scoping has its own helper:
  `UserScopedQuery::getUserIdForInsert('words')` returns the
  user ID to add to the column/value lists. Switched to that and
  also moved off the legacy `bind('issssissss', …)` type-string
  call (the dynamic-binding count made it brittle).

* **Feed article-import error messages were unreadable HTML**: when
  the article-section selector found no text in an upstream page,
  `ArticleExtractor::formatErrorMessage` emitted a string with `<a>`
  and `<br />` markup intended for legacy HTML rendering. Modern
  toasts use Alpine's `x-text` which renders the tags as literal
  characters. Switched to plain text (`"Title" has no text section
  (URL)`) and added a newline separator when multiple errors
  accumulate per feed.

* **CSP parser errors on archived-texts page**: Alpine's CSP build
  rejected `x-data="archivedTextsGroupedApp()"` (function-call
  syntax forbidden when component is registered via `Alpine.data`)
  and choked on inline `x-text="languages.reduce((sum, lang) => …)"`
  (arrow function with comma between params). Switched to
  `x-data="archivedTextsGroupedApp"` and moved the three complex
  expressions (`totalArchivedSummary`, `archivedCountLabel`,
  `collapseAriaLabel` / `chevronIcon`) into component methods.

* **User-scoped settings silently dropped in multi-user mode**: every
  per-user preference POST (theme, current language, reader text
  size, app language, …) appeared to succeed but never persisted.
  Two bugs cancelled out: `AdminApiHandler::saveSetting` always
  wrote to `StUsID=0`, and `Settings::getWithDefault` for SCOPE_USER
  keys read `Connection::preparedFetchValue("SELECT StValue …")`
  with no column-name argument — `preparedFetchValue` defaults to
  column key `'value'`, so `$row['value']` missed the actual
  `StValue` and returned NULL. Route writes through
  `Settings::saveForUser` and pass `'StValue'` as the explicit
  column name on the read.

* **Asset chunks broken on every HTTPS install behind a TLS proxy**:
  Vite's runtime preload helper emitted `/js/vite/chunks/X.js`
  links that the .htaccess shim 301-redirected to `/dist/js/…`. The
  redirect inherited the request's scheme (HTTP from inside Apache)
  so `<link rel="modulepreload">` resolved to `http://…/dist/X.js`
  and Chrome blocked it as mixed content before the proxy's
  HTTPS-upgrade could fire. Set Vite's `base: '/dist/'` so preload
  URLs match the served path directly, and convert the legacy
  asset paths in .htaccess from `RedirectMatch 301` to internal
  `RewriteRule [L]` (no client-visible redirect).

* **`POST /tags/new` and `POST /tags/text/new` returned 404**: only
  the GET routes were registered, but the controllers' `new()`
  methods handle both GET (show form) and POST (save). Creating a
  term tag or text tag from the UI hit 404 instead of saving.

* **Multi-user SQL queries broken when `MULTI_USER_ENABLED=true`**:
  `UserScopedQuery::forTablePrepared()` returns `" AND ColUsID = ?"`,
  but four `SELECT … FROM …` queries (GetTextForEdit,
  GetTextForReading, ParsingCoordinator, WordBulkService) and one
  `UPDATE … SET` in ImportUtilities had no WHERE clause for the
  fragment to attach to. Visiting `/texts/new`, parsing some texts,
  saving terms in bulk, and linking imported words to text items
  all produced syntax-error 500s. Added a base `WHERE 1=1` on the
  SELECTs and moved the user-scope predicate into the JOIN ON of
  the UPDATE.

* **Review page `/review` rendered a blank 200**:
  `ReviewController::index()` was typed `: void` and discarded
  the `RedirectResponse` returned by `$this->redirect('/text/edit')`.
  Execution fell through to `renderReviewPage()` which emitted
  nothing when `$property === ''`. Changed the signature to
  `?RedirectResponse` and `return $this->redirect(...)`.

* **Logout link was missing from the navbar dropdown**: the
  `/logout` route existed but only Preferences, Profile, Admin,
  and Help showed in the user dropdown — multi-user installs left
  users unable to sign out from the UI. Added a Logout entry
  (translated for all nine supported locales), gated on multi-user
  mode.

* **Service worker leaked previous user's chrome across sessions**:
  the SW keyed cached HTML by URL with no awareness of user
  identity, so when User A logged out and User B logged in in the
  same browser, B briefly saw A's navbar (language list, streak
  badge) until each page re-fetched. Purge the runtime cache on
  navigations to `/login` and `/logout`; app-shell assets stay
  cached.

* **`HEAD` requests returned 404 on every GET-only route**: route
  resolution looked up the literal request method, so HEAD on
  `/login`, `/register`, `/password/forgot`, … all 404'd. RFC 7231
  §4.3.2 says HEAD MUST mirror GET (minus the body) — Apache strips
  the body automatically, so the router now treats HEAD as GET
  during resolution.

* **Glosbe dictionary links used `http://`**: three references
  (`buildGlosbeUrl()` and two user-facing `<a>` tags in the
  translation popup) emitted `http://glosbe.com/…` URLs that CSP's
  `script-src-elem` blocked on HTTPS installs. Switched to
  `https://glosbe.com/`. Also dropped the `de.` subdomain from the
  language-wizard's default Dict 1 URL so non-German users don't
  get the German UI by default.

* **RSS feed wizard 500'd for users with one language**: a freshly
  created user has no `currentlanguage` setting saved (the navbar
  shows the only option by default), so the curated-feed wizard
  posted `NfLgID=0` and `Feed::create()` rejected with "Language ID
  must be positive". `FeedEditController::showNewForm()` now falls
  back to the user's first language when `currentlanguage` is unset.

* **Feed `ArticleExtractor` downgraded HTTPS feeds to HTTP**: when
  an article href started with `..`, the absolute-URL reconstruction
  hardcoded `'http://' . $feedHost['host']`. `parse_url()` had
  already extracted the scheme two lines above — just read it.

* **Gutenberg suggestions endpoint returned 502 on prod**: two
  cumulative issues. `https://gutendex.com/books` 301-redirects to
  `/books/` (trailing slash), doubling the round-trip; and the
  10 s timeout was tight for the upstream's 20-60 s response time
  from small VPSes. Hit the canonical URL with the trailing slash,
  bump the timeout to 60 s. Results are cached per language+page
  so the slow first request only happens once.

* **HTTPS detection broken behind a TLS-terminating reverse proxy**
  (#234): when LWT runs behind Traefik / Caddy / nginx with TLS
  offloading, the connection from the proxy to LWT is plain HTTP and
  `$_SERVER['HTTPS']` stays unset. The cookie-secure and HSTS code
  paths already handled this via `X-Forwarded-Proto`, but
  `UrlUtilities::getAppOrigin()` and `UrlUtilities::urlBase()` did
  not — so the admin "Server Location" panel and any code building
  absolute URLs from the request showed `http://` even when the
  browser was on HTTPS. Consolidated all four scheme-detection sites
  into a single `UrlUtilities::isSecureRequest()` helper that honours
  `X-Forwarded-Proto`, `X-Forwarded-Ssl`, `SERVER_PORT == 443`, and
  the legacy `HTTPS` variable. Added `UrlUtilities::getRequestHost()`
  that prefers `X-Forwarded-Host` over `HTTP_HOST` (with the same
  Host-Header-Injection regex validation as before). Both gated by a
  new `TRUST_PROXY` env var, defaulting to `true` for backwards
  compatibility — operators running LWT directly on the public
  internet without a shielding proxy should set `TRUST_PROXY=false`
  to prevent header-spoofing attacks. Setting `APP_URL` continues to
  short-circuit detection entirely.

* **Term import was broken on every supported MariaDB and silently
  dropped imported rows in multi-user mode** (the "complete" import
  path that handles tags / overwrite modes / pasted text). Three
  bugs landed in one place:

  (1) `LOAD DATA LOCAL INFILE ?` is rejected by MariaDB — the
  filename is parsed at execute time, not at prepare, so the `?`
  placeholder is never substituted. Every server with both
  `local_infile` enabled in MySQL and `mysqli.allow_local_infile`
  enabled in PHP hit `Failed to prepare statement: …syntax error
  near '?'…`. Inline the filename with `Connection::escape()` —
  the path is server-controlled (PHP's `tmp_name` for uploads or
  `WordUploadService::createTempFile()` for pasted text).

  (2) `executeMainImportQuery`'s INSERT branch built an
  `INSERT INTO words (… WoLgID …) SELECT *, $langId AS LgID, …
  FROM temp_words` with no `WoUsID` column or value. In
  multi-user mode that meant every imported row landed with
  `WoUsID = NULL` and was filtered out by the QueryBuilder's
  `WoUsID = currentUserId` clause everywhere the user later
  looked. Wire `UserScopedQuery::insertColumn('words')` into the
  column list and `UserScopedQuery::insertValue('words')` into
  the SELECT projection.

  (3) The same method's UPDATE branch (overwrite modes 3 and 5)
  appended `UserScopedQuery::forTablePrepared('words', $bindings,
  'a')` to its SQL — that helper pushes the user id into
  `$bindings` and adds a `?` to the query — and then called
  `Connection::execute($sql)` with no bindings. The `?` was
  unbound and the statement either errored or, in single-user
  mode, ran without the scope filter (latent — the helper returns
  empty there). Switch to `Connection::preparedExecute($sql,
  $bindings)`.

  (4) `handleTagsImport` had two related issues. The
  `INSERT IGNORE INTO tags (TgText) SELECT name …` had no `TgUsID`
  column, so the new tags landed with NULL owner and never
  appeared on the user's tag page. And the `INSERT IGNORE INTO
  word_tag_map SELECT WoID, TgID …` joined `tags` without a
  user filter, so a foreign user's tag with the same text could
  be reused (writing `(my_WoID, foreign_TgID)` rows into
  `word_tag_map` and polluting the foreign user's
  tag-to-word membership — same shape as F13). Stamp `TgUsID`
  on the tags INSERT, scope BOTH `words` and `tags` in the
  word-tag-map join, and pass the merged `$bindings` to
  `preparedExecute` (pre-fix the call ignored the helper-pushed
  bindings and re-passed `[$langId]`, leaving the user-scope
  `?` unbound). `importTagsOnly` (the tags-only LOAD-DATA path)
  gets the same `TgUsID` stamp.

  Verified end-to-end against a 2-user database with
  `local_infile = ON` and the broken LOAD DATA path active:
  pre-fix, intruder pasting `foo\tbar\timported\nhello\tworld\t
  imported\ntestterm\texample\timported` and clicking Import got
  a 500 from the `LOAD DATA …INFILE ?` syntax error and zero
  rows landed; post-fix the same payload returns
  `¡Importación correcta!`, three rows appear with
  `WoUsID = 2`, the `imported` tag is created with `TgUsID = 2`
  (operator's matching tag at `TgUsID = 1` is untouched), and
  three `word_tag_map` rows link the intruder's words to the
  intruder's tag. Operator's 207 words are unchanged.

* **Books module ignored multi-user scoping**: the `books` table was
  the only feature module not registered in `USER_SCOPED_TABLES`, so
  `MySqlBookRepository`'s `findById`, `delete`, `updateChapterCount`,
  `updateCurrentChapter`, `findBySourceHash`, `existsBySourceHash`
  and `getBookContextForText` ran without a `BkUsID` filter — and
  `findAll` / `count` only filtered when the caller remembered to
  pass `$userId`. Concrete impact: `GET /api/v1/books/{id}`
  returned any user's book details (title, author, chapter count),
  and the chapter dropdown rendered on a foreign text via
  `getBookContextForText` would happily display the foreign book's
  metadata. Add `'books' => 'BkUsID'` to both
  `QueryBuilder::USER_SCOPED_TABLES` and
  `UserScopedQuery::USER_SCOPED_TABLES` so every `query()` call
  through the books repository auto-injects the current-user filter
  on SELECT/UPDATE/DELETE and stamps `BkUsID` on INSERT. Verified
  end-to-end against a 2-user database — intruder hitting
  `/api/v1/books/4` (operator's book) gets `Book not found`
  instead of the title; intruder's `/api/v1/books` list shows
  zero results while operator's row count is unchanged.

* **`sentences-with-term` API leaked another user's sentences**:
  `SentenceService::findSentencesFromWord` queried
  `FROM sentences, word_occurrences WHERE … AND SeLgID = ?` with
  no parent-row filter — `sentences` carries no `UsID` column, so
  any logged-in user calling `GET /api/v1/sentences-with-term?
  language_id={operator_lang}&term_lc=…` (or the same endpoint
  pinned to a foreign WoID via the path segment, e.g.
  `/sentences-with-term/{operator_word_id}`) received the
  operator's sentence text directly. `formatSentence` had the
  same shape: it joined `word_occurrences` and `languages` to
  fetch the rendered sentence content for any SeID, with no
  ownership gate. Add a private `parentTextUserScope()` helper
  that produces ` AND SeTxID IN (SELECT TxID FROM texts WHERE
  TxUsID = ?)`, applied to both raw-SQL branches of
  `findSentencesFromWord`. Add a private `ownsSentence()` guard
  at the top of `formatSentence` that short-circuits with the
  empty-sentence fallback when the SeID's parent text isn't
  owned by the caller — defense in depth, since `find*` now
  filters the SeIDs it returns but `formatSentence` is reachable
  from other paths too. Verified end-to-end: pre-fix, intruder
  hitting `/api/v1/sentences-with-term/2?language_id=1&term_lc=
  bonjour` got `[["<b>Bonjour</b>.","{Bonjour}."], ["<b>Bonjour
  </b> Manon.","{Bonjour} Manon."]]`; post-fix the same request
  returns `[]`.

* **Vocabulary bulk-action endpoints overwrote and deleted any user's
  words**: `PUT /api/v1/terms/bulk-action` (and `terms/family/apply`)
  routed straight into `WordListService::deleteByIdList` /
  `updateStatusByIdList` / `updateStatusDateByIdList` /
  `deleteSentencesByIdList` / `toLowercaseByIdList` /
  `capitalizeByIdList` and `WordFamilyService::bulkUpdateTermStatus`,
  each of which built raw `UPDATE words … WHERE WoID IN (?,?,?)` /
  `DELETE FROM words …` SQL with no `WoUsID` filter. Any logged-in
  user could PUT a JSON body containing another user's WoIDs and
  set their entire vocabulary to status 99, blank their example
  sentences, lowercase the surface forms, or wipe the rows
  outright. `deleteByIdList` additionally issued a raw
  `DELETE FROM word_occurrences WHERE Ti2WordCount > 1 AND
  Ti2WoID IN (…)` first — `word_occurrences` carries no UsID
  column, so even when the second `DELETE FROM words` would have
  failed under a fix on that table alone, the multi-word
  occurrence rows for the foreign user's text were already gone.
  Each raw path now appends `UserScopedQuery::forTablePrepared(
  'words', $bindings)`, and `deleteByIdList` pre-filters its
  WoID list through `filterOwnedWordIds()` (a single user-scoped
  SELECT) before running either DELETE so the
  `word_occurrences` cleanup only targets rows the caller owns.
  Confirmed end-to-end against a 2-user live database — pre-fix:
  intruder posting `{"ids":[1..6],"action":"del"}` deleted five
  of the operator's words and dropped the operator's row count
  from 207 to 202; post-fix: identical request returns
  `success:true,count:5` (the count just echoes the input length)
  but the operator's row count and word state are byte-identical
  across `del`, `s99`, `lower`, `today`, `delsent`, `bulk-status`,
  and `family/apply`. Plus `getOrCreateTermTag` / `getOrCreateTextTag`
  (and the matching `removeTagFrom*` lookups in `TermTagService` /
  `TextTagService`) now scope their `SELECT TgID FROM tags
  WHERE TgText = ?` / `SELECT T2ID FROM text_tags …` by user, so
  a tag-text collision across users no longer reuses the foreign
  user's TgID — which would have written `(foreign-tag, my-words)`
  rows into `word_tag_map` and contaminated the foreign user's
  tag-to-row membership. (Per-user composite uniques on
  `tags.TgText` and `text_tags.T2Text` shipped earlier in
  migration `20260503_120000_per_user_name_uniques.sql`; this
  fix relies on that schema being present.)

* **Admin "Empty Database" wiped every user, not just the caller**:
  `Restore::truncateUserDatabase` ran `DELETE FROM languages/texts/
  words/...` with no `WHERE …UsID = ?` filter — despite the name and
  docblock claiming it removes "all data belonging to the current
  user". Triggered by the admin "Empty Database" button, an admin
  who clicked through the confirmation in a multi-admin install
  destroyed every other user's content. Scope each DELETE to the
  current user via the table's `UsID` column for direct-scoped
  tables (`languages`, `texts`, `words`, `tags`, `text_tags`,
  `news_feeds`); via parent-row subqueries for the link/map and
  derived tables (`text_tag_map`, `word_tag_map`, `feed_links`,
  `word_occurrences`, `sentences`); and via `(StKey, StUsID)` for
  the per-user `currenttext` setting. Single-user mode keeps the
  legacy "wipe everything" behaviour (no user filter to apply).
  Confirmed end-to-end against a 2-admin live database — pre-fix:
  intruder hitting "Empty Database" deleted operator's `hola`,
  `parents`, `parented` words plus the Spanish language and tags;
  post-fix: only intruder's own rows are removed, operator's data
  is fully preserved.

* **Restore in multi-user mode silently destroyed every user's
  data and orphaned cross-install dumps**: `Restore::restoreFile`
  called `dropAllLwtTables()` before loading the dump, wiping
  every account's content even though the admin only intended to
  restore their own backup. Restored rows also kept their original
  `UsID` columns from the source install — on a different host
  those IDs typically reference users that don't exist locally,
  leaving every imported row attached to a phantom owner. Two
  guards: (1) refuse the restore when more than one active user
  account exists, returning a clear error pointing at the manual
  multi-user migration recipe in the post-installation docs;
  (2) on safe single-admin installs, run a post-restore UPDATE
  that rewrites every `UsID` column on user-scoped tables
  (`languages`, `texts`, `words`, `tags`, `text_tags`,
  `news_feeds`, `settings`, `local_dictionaries`) to the current
  admin's UsID, so dumps from other installs become visible.
  Single-user mode is unchanged. The error message is now
  surfaced through `RestoreFromUpload::execute()`, which
  previously discarded the message string from
  `restoreFromHandle()` and unconditionally reported success.
  Note: restore is already disabled by default in multi-user
  mode (`BACKUP_RESTORE_ENABLED` defaults to `false`); these
  guards harden the path for installs that explicitly opt in.

* **Feed module leaked every user's feeds and let any user wipe any
  other user's articles**: three independent bugs landed together
  here. (1) `FeedCrudApiHandler::getFeedList` built raw
  `SELECT … FROM news_feeds WHERE 1=1 …` SQL with no `NfUsID`
  filter, so `GET /api/v1/feeds/list` rendered every user's feeds
  side by side (including their language and per-feed article
  counts). (2) `feed_links` carries no `UsID` column of its own,
  so `MySqlArticleRepository::find/findByIds/delete/deleteByIds/
  deleteByFeed/deleteByFeeds/getCountPerFeed` happily operated on
  any FlID/FlNfID the caller supplied — meaning `DELETE
  /api/v1/feeds/{operatorFeedId}` cascaded into
  `articleRepository->deleteByFeeds([operatorFeedId])`, wiping
  every article in the operator's feed even though the parent
  `news_feeds.deleteMultiple` is auto-scoped and matched zero
  rows. (3) `FeedArticleApiHandler::deleteArticles` ran a raw
  `QueryBuilder::table('feed_links')->whereIn('FlID', $ids)
  ->whereIn('FlNfID', [$feedId])->delete()` with no ownership
  check, letting any logged-in user delete any other user's
  articles by guessing IDs. Three corresponding fixes: append
  `UserScopedQuery::forTablePrepared('news_feeds', $params)` to
  the feed-list COUNT and SELECT raw SQL; add a
  `feedOwnerScope($bindings)` helper that splices an
  `AND FlNfID IN (SELECT NfID FROM news_feeds WHERE NfUsID = ?)`
  subquery into every by-id article method; gate
  `deleteArticles` upstream on `feedFacade->getFeedById()`
  (user-scoped via QueryBuilder, returns null for foreign feeds).
  Confirmed end-to-end with a 2-user live database — pre-fix:
  intruder's feed list contained both feeds and a single
  `DELETE /api/v1/feeds/articles/1` deleted both of operator's
  articles; post-fix: list shows only intruder's feed and both
  delete attempts return `{"success":false, "error":"Feed not
  found"}` with operator's articles intact.
* **Term-tag and text-tag list pages leaked every other user's tags**:
  `MySqlTermTagRepository::paginate`/`count`/`deleteAll` and the
  matching three methods on `MySqlTextTagRepository` built raw
  `SELECT … FROM tags WHERE (1=1) …` / `… FROM text_tags WHERE
  (1=1) …` SQL with no `WHERE TgUsID = ?` / `WHERE T2UsID = ?`
  filter. The list-with-empty-query path ALWAYS used the unscoped
  raw SQL (the empty-query QueryBuilder fast path only covered
  `count('')`), so every visit to `/tags` and `/tags/text`
  rendered every user's tags side by side, and a bulk-delete-all
  with a search filter would have wiped every user's matching
  tags. Append `UserScopedQuery::forTablePrepared(self::TABLE_NAME, …)`
  to all six paths so the listing, count and bulk-delete only
  ever see / touch the caller's rows. Confirmed end-to-end against
  a 2-user install before the fix (intruder saw operator's
  `operator-only` and `py-thon` tags) and after (intruder sees
  only their own `intruder-only` and `py-pi`).
* **Admin "Backup the database" exposed every user's data, not just
  the caller's**: `MySqlBackupRepository::generateBackupSql` and
  `generateOfficialBackupSql` ran `SELECT * FROM languages/texts/
  words/tags/text_tags/news_feeds/...` with no `WHERE …UsID = ?`
  filter. The route is admin-gated, but in a multi-admin install
  (school, family, club) any admin downloading a backup walked off
  with every other user's vocabulary, texts, scores, sentences,
  reading history and feeds — a silent privacy leak with no
  destructive click required. Scope every dump SELECT to the
  current user: directly via the table's UsID column for
  `languages`, `texts`, `words`, `tags`, `text_tags`, `news_feeds`,
  `settings`; via parent-row subqueries for `text_tag_map`,
  `word_tag_map`, `feed_links`. Single-user installs and
  unauthenticated paths keep their full-dump behaviour. The
  loud, opt-in destructive paths (`Empty database` truncate,
  cross-user wipe on restore, foreign-UsID drift on
  cross-install restore) are tracked separately and not changed
  in this fix.
* **Review/SRS module leaked and accepted writes across users**: the
  `/api/v1/review/*` endpoints (`config`, `next-word`, `table-words`,
  `tomorrow-count`, `status`) forwarded `lang=`, `text=`, `selection=`
  and `term_id=` straight from the request to
  `ReviewConfiguration::*Prepared`, whose SQL fragments
  (`words WHERE WoLgID = ?`, `... WoID IN (...)`, etc.) had no
  `WoUsID` filter. A logged-in account with no own data could read
  any other user's words, sentences, scores and translations, and
  could PUT `/api/v1/review/status` with a foreign `term_id` to
  rewrite that user's `WoStatus` (e.g. mark-as-well-known). Append
  the words-table user scope to all four projection branches
  (`lang`, `text`, `words`, `texts`) and add an ownership pre-check
  in `SubmitAnswer::execute` that bails out with `Word not found`
  before touching `updateWordStatus` or the activity counter when
  `getWordStatus` (user-scoped via QueryBuilder) returns null.
* **`StUsID=0` "global default" leaked previous users' choices into
  fresh accounts**: `Settings::getWithDefault` for a SCOPE_USER key
  fell through to the `StUsID=0` row when the current user had no
  per-user value. That row is just whoever-saved-the-setting-first's
  value — typically the original single-user admin's, or a previous
  test account's — so `set-texts-per-page`, `currentwordsort`, etc.
  silently inherited across the multi-user boundary. For SCOPE_USER
  keys with a logged-in user, skip the StUsID=0 hop and go straight
  to the hardcoded default. SCOPE_ADMIN keys keep the fallback
  (admins legitimately set those system-wide), and single-user mode
  (no current user) is unchanged.
* **Flipping `MULTI_USER_ENABLED=false → true` orphaned every legacy
  row and looked like data loss**: the
  `add_user_id_columns.sql` migration's backfill is gated on a
  pre-existing `users.UsUsername='admin'` row, which doesn't exist
  on a fresh `users` table — so the UPDATE is a no-op and every
  `LgUsID/TxUsID/WoUsID` stays NULL. Once user-scope filters kick in
  the operator sees empty lists everywhere. Self-heal during the
  first-admin bootstrap in `Register::execute`: when the new user is
  promoted to admin (because no admins exist), reassign every
  NULL-owner row across `languages`, `texts`, `words`, `tags`,
  `text_tags`, `news_feeds`, `books`, and `local_dictionaries` to
  their UsID. On already-migrated installs the UPDATEs match zero
  rows and cost nothing. The mirror-image edge case (legacy data
  attached to the seed "ghost admin" row instead of NULL — only
  reachable on installs where the original migration's backfill
  *did* run successfully against the ghost) is left to a one-time
  manual SQL recipe; see the [Switching an Existing Install From
  Single-User to Multi-User](docs-src/guide/post-installation.md#switching-to-multi-user)
  guide for the detection query and step-by-step cleanup.
* **Successful registration silently bounced to /login with no
  feedback**: `UserController::register` called
  `UserFacade::setCurrentUser($user)` for an "auto-login", but that
  method only updates the in-process `Globals` — it never writes
  `$_SESSION`. The auth middleware therefore re-redirected to /login
  on the very next request, dropping the FlashMessageService success
  flash on the floor (the login view doesn't read it). Land the
  user on /login deliberately, prefill the username, and surface
  the success notice via `$_SESSION['auth_success']` (already
  rendered by the login view for the password-reset flow).
* **`UserApiHandler` docblock advertised `/api/v1/user/...`
  endpoints that don't exist**: the handler is registered under
  `auth`, not `user`, so every advertised path was a 404. Fix the
  docblock to say `/api/v1/auth/login`, `/api/v1/auth/register`,
  `/api/v1/auth/refresh`, `/api/v1/auth/logout`, and
  `/api/v1/auth/me` — matching the registered routes.
* **Production debug page leaked stack traces, file paths, and SQL on
  multi-user installs**: `Application::isDebugMode()` defaulted to *on*
  in single-user mode and *off* in multi-user, but it was called
  before `EnvLoader::load()`, so `getenv('MULTI_USER_ENABLED')` always
  read empty, the single-user branch was always taken, and every 500
  rendered the debug HTML in production. Move `EnvLoader::load()` to
  the very top of `bootstrap()` so the env values are visible by the
  time we decide.
* **Two users could not coexist if either had picked the same name first**
  (multi-user only): `languages.LgName`, `tags.TgText`, and
  `text_tags.T2Text` each carried a *global* UNIQUE constraint, so
  the second user's attempt to create e.g. their own "Spanish"
  language, "easy" term tag, or "fiction" text tag died with a
  duplicate-key 500. New migration replaces each constraint with a
  composite UNIQUE on `(user_id, name)`. NULL-owner legacy rows
  remain coexistent (NULLs distinct in MariaDB unique indexes).
* **Multi-user `/texts` and other paginated screens 500'd with invalid SQL**:
  every paginated query in `Modules/Text` and `Modules/Vocabulary` was
  concatenating `UserScopedQuery::forTablePrepared(...)` *after* `LIMIT
  ?, ?` (or `ORDER BY ...`), producing a syntax error
  (`... LIMIT ?, ? AND TxUsID = ?`) on every request from a logged-in
  user. With `MULTI_USER_ENABLED=true` this rendered `/texts`,
  `/api/v1/texts/by-language/{id}`, the lemma family and statistics
  endpoints, and the curated-import translation merge step entirely
  unusable. Inline the user-scope clauses in each query's `WHERE`
  (or LEFT-JOIN `ON` for the `text_tags`/`words` join cases) and
  push their bindings before the `LIMIT` bindings.
* **Vocabulary list (`/words`) leaked every user's terms across the
  multi-user boundary**: `WordListQueryService::countWords`,
  `getWordsList`, `getWordsListWithWordCount`, and
  `getFilteredWordIds` had no `UserScopedQuery` calls at all. A fresh
  user with no data would see every other user's terms (and tag and
  language filters did nothing to stop it). Add `words` and
  `languages` user-scope to all four entry points, including both
  halves of the no-text-filter UNION query in
  `getWordsListWithWordCount`.
* **Dictionary import rejected every file as "Invalid file format"**
  (#233): `CsvImporter`/`JsonImporter`'s `canImport()` checked the
  extension of the PHP upload temp path (`/tmp/phpXXXXXX`), which has
  none. Forward the original filename through `canImport()` so format
  detection works for CSV, TSV, and JSON dictionary uploads (and the
  vocabulary term importer that shares the same code path).
* **StarDict archive uploads still failed on the unified import page**
  (#233): the archive-extract-then-import flow lived only in
  `DictionaryController::importFile`, but the unified term/dictionary
  import page routes through `TermImportController`. Every UI upload of
  a `.zip` / `.tar.gz` / `.tar.bz2` bundle was therefore still rejected
  with "Invalid file format". Mirror the archive-aware flow on the live
  route so FreeDict and WikDict downloads import directly from the form.
* **CSV dictionary upload crashed on PHP 8.4+**: `fgetcsv()` raised
  `ErrorException: the $escape parameter must be provided as its default
  value will change` and rendered the dev debug page (with file paths)
  to the end user. Pass an explicit empty `$escape` argument at the three
  call sites in `CsvImporter` for RFC 4180 / forward-compat behavior.
* **Bare `.ifo` upload produced a generic, unhelpful error**: uploading
  just the `.ifo` for `format=stardict` (without its `.idx`/`.dict`
  siblings, which a single web upload can never deliver) used to return
  the same "Invalid file format" string as truly malformed input. It now
  short-circuits with a specific hint that the user must upload an
  archive containing the full bundle.

### Changed

* **Dependency security bumps** closing twelve Dependabot advisories. PHP
  runtime: `guzzlehttp/guzzle` 7.10.0 → 7.12.1, `guzzlehttp/psr7` 2.9.0 →
  2.12.1 (cookie-domain, proxy-downgrade, and URI-host issues). JS build/test
  toolchain (not shipped to users): `vite` → 8.0.16, `undici` → 7.28.0,
  `form-data` → 4.0.6, `markdown-it` → 14.2.0, `esbuild` → 0.28.1. All within
  existing version constraints; `composer audit` and `npm audit` both clean.
* The archive-detect / extract / find-by-extension flow is now extracted
  into `DictionaryImportFileResolver` and shared by the curated import,
  legacy `/dictionaries/import`, and unified `/word/upload` routes. No
  user-visible change beyond the friendlier bare-`.ifo` error above.

## [3.1.1-fork] - 2026-04-26

### Changed

* **EPUB import unified onto `/texts/new`**: picking an `.epub` under
  *Source → File → From computer* now flips the form's action to
  `/book/import` and submits inline, instead of redirecting to a separate
  page where the user had to re-pick the file. Action-card buttons that
  used to link to `/book/import` now point at `/texts/new`. The standalone
  `/book/import` page stays reachable for legacy bookmarks and direct form
  POSTs, with a deprecation banner pointing to the new flow. The POST
  handler now accepts both legacy field names (`LgID`, `thefile`) and the
  new-text form's names (`TxLgID`, `importFile`).
* **Tabbed file source on `/texts/new`**: *From computer* and *From server*
  are now Bulma tabs instead of stacked panels, hiding the unused option
  and reducing vertical clutter.

### Fixed

* **EPUB upload** (#232): forward the original filename to
  `EpubParserService::parse()` / `getMetadata()` so the underlying
  `kiwilan/php-ebook` library can detect the format. PHP upload temp paths
  (`/tmp/phpXXXXXX`) carry no extension, which caused parsing to bail with
  "File has no extension".
* **Tagify chunk under Vite 8 / Rolldown**: switched the Tagify CSS dynamic
  import to a static one in `tagify_tags.ts`. Co-bundling CSS with the JS
  chunk under Rolldown emitted a broken `tagify_exports` named export and
  threw `SyntaxError: local binding for export 'tagify_exports' not found`,
  which halted module init on pages with tag inputs (e.g. `texts/new`).
* **`setTheFocus()` crash on non-input elements**: guard the `.select()`
  call so it only runs on `<input>` / `<textarea>`. The EPUB form's
  language `<select class="setfocus">` was triggering
  `TypeError: e.select is not a function` after focus.
* **Spurious "1 field(s) must not be empty" alert during EPUB import**:
  the `.notempty` validator now walks the ancestor chain and skips fields
  hidden via Alpine `x-show` (inline `display: none`) or the `hidden`
  attribute, so the hidden `TxText` textarea on `/texts/new` no longer
  trips the alert when a file is selected.
* **Theme / language change appearing only after navigation**: the service
  worker used stale-while-revalidate for HTML, so the reload after saving
  a setting served the previous render from cache and the change only
  surfaced on the next page load. Navigation requests now use a
  network-first strategy; static assets stay cache-first. `CACHE_VERSION`
  bumped to `v2` so old caches are dropped on activate.
* **Navbar theme toggle click ignored under CSP-build Alpine**: the
  `@alpinejs/csp` build does not bind `@click` reliably on the same
  element as `x-data`. Moved the listener to an imperative
  `addEventListener` inside the component's `init()` and dropped the
  inline directive. Added a Cypress regression test.
* **Theme save with no visible change**: when in auto mode and OS
  preference matches the would-be light/dark target, the saved value is a
  no-op, which felt like the click had failed. The toggle now shows a
  confirmation toast after reload, and explains when a saved theme is in
  auto-mode following the system preference.

### Translations

* Added missing keys for the EPUB-import deprecation banner
  (`book.deprecated_page_title`, `book.deprecated_page_body`,
  `book.go_to_new_text`) and the `text.new.file.epub_detected`
  notice across all eight non-English locales. Added the new
  `navbar.theme_saved` / `navbar.theme_saved_auto` toast strings
  to every locale.

## [3.1.0-fork] - 2026-04-21

### Added

* **Internationalization (i18n)** (#223): PHP `__()` and JS `t()` /
  Alpine `$t` helpers backed by per-namespace JSON files under `locale/`,
  with English fallback and a per-user `app_language` setting. The
  preferences page exposes a language picker.
* **Spanish translation** of the home page, main navbar and user
  preferences page (#223).
* **Activity tracking with streaks and calendar heatmap** (#227): New
  `activity_log` table tracks daily terms created, terms reviewed, and texts
  read. A backfill migration populates historical data from existing word
  timestamps. The home page now shows current streak, best streak, today's
  activity summary, and a GitHub-style calendar heatmap of the last 12 months.
  New API endpoints at `/api/v1/activity/{dashboard,streak,calendar,today}`.
* **PHP 8.5 support**: All dependencies now support PHP 8.5. Added PHP 8.5 to
  the CI test matrix.

### Changed

* **Update notification**: The "new LWT version available" banner on the
  home page is now dismissible (remembered per-version via localStorage),
  only shown to admins, and can be disabled entirely via a new
  `set-check-for-updates` admin setting in the settings page.
* **Navbar**: Merged the admin dropdown into the user dropdown to save space.
* **Per-user statistics**: Moved the statistics page from `/admin/statistics` to `/profile/statistics` since it shows per-user learning data. The old URL redirects to the new one.
* **Cleaner setting definition keys**: `SettingDefinitions` entries now use
  `default` (string) and `numeric` (bool) instead of the cryptic `dft` (string)
  and `num` (int 0/1). The legacy keys are still accepted on read for
  backwards compatibility but are deprecated.
* **Renamed `dev` branch to `develop`**: All CI workflows and documentation
  updated to reference the new branch name.
* **Upgraded dev tooling to latest major versions**:
  * PHPUnit 10 → 11: migrated all test annotations (`@dataProvider`, `@covers`,
    `@group`, `@runTestsInSeparateProcesses`) to PHP 8 attributes. Replaced the
    duplicate integration testsuite in `phpunit.xml` with `#[Group('integration')]`.
  * ESLint 9 → 10, TypeScript 5 → 6: removed deprecated `baseUrl` from
    `tsconfig.json`, fixed two new `no-useless-assignment` lint errors.
  * Vite 6 → 8 (Rolldown bundler): converted `manualChunks` from object to
    function, removed redundant `inlineDynamicImports`.
  * Also bumped `@eslint/css` 0.14 → 1, `@eslint/js` 9 → 10,
    `@eslint/markdown` 7 → 8, `@types/node` 24 → 25, `jsdom` 27 → 29,
    `globals` 16 → 17.
* **Cleaned up all PHPCS violations in the test suite**: removed 80+ redundant
  `require_once` / bootstrap side-effect blocks (already handled by
  `tests/bootstrap.php`), extracted inline helper classes to separate files,
  fixed line-length warnings. Result: 0 errors, 0 warnings across all tests.
* **Separated build output from static assets**: Build output (Vite bundles,
  theme CSS, base CSS) now goes to `dist/` instead of `assets/`. Static files
  (images, sounds, manifest) stay in `assets/`. The `dist/` directory is fully
  gitignored — cloning requires `npm run build:all`. Includes a DB migration to
  update stored theme directory paths.
* **Replaced legacy PNG/GIF icons with pure CSS in dark themes**: The Dark,
  Dark_Muted, and Underline_Dark themes used a PNG sprite sheet for custom
  checkbox/radio styling. Replaced with CSS borders and inline SVG. Also removed
  36 unused image files (spinners, speaker icons, test result icons, etc.) that
  had already been superseded by Lucide SVG icons.
* **Optimized Vite build chunks**: Reduced the main entry bundle from 228 KB to
  180 KB (gzip: 68→56 KB) by moving page-specific modules (home, media, auth,
  tags, dictionary, sorttable, searchable-select, bulk-actions, word-form-auto)
  to dynamic imports loaded on demand. Tree-shook chart.js (203→179 KB) by
  registering only used components. Pruned 50 unused Lucide icons (143→97) and
  added 5 missing ones. Added a Vite plugin to clean stale hashed bundles
  between builds.

### Fixed

* **Version number not updated since 3.0.0**: `ApplicationInfo::VERSION` was
  never bumped for the 3.0.1 and 3.0.2 releases, causing the app to display
  "3.0.0-fork" in the UI.

### Removed

* **Dead route `/text/set-mode`**: This endpoint (and its view, controller
  method, and frontend JS) was no longer reachable from any UI. The "Show All"
  and "Learning Translations" toggles now save settings via the
  `POST /api/v1/settings` API and reload the page.
* **Dead route `/admin/save-setting`**: This legacy `?k=…&v=…&u=…` endpoint was
  fully superseded by `POST /api/v1/settings`. No UI or frontend code referenced
  it.

### Deprecated

* **Legacy query-parameter routes** now emit `Deprecation`, `Sunset`, and `Link`
  HTTP headers. The routes still work but will be removed in the next major
  version. Affected routes: `/text/read`, `/text/display`, `/text/print-plain`,
  `/text/edit`, `/word/new`, `/word/show`, `/vocabulary/term/status`,
  `/feeds/edit`, `/dictionaries`, `/dictionaries/import`, and the
  `/api.php/v1/*` prefix.

## [3.0.2-fork] - 2026-04-05

### Changed

* **Removed `@vitejs/plugin-legacy`**: The legacy Vite plugin generated ~1.2 MB
  of `nomodule` bundles and polyfills that were never served (ViteHelper only
  emits `<script type="module">` tags). Removing it cuts JS build output by
  roughly 70%. Also switched from Terser to esbuild for minification (Vite's
  built-in default), which speeds up the build.

### Added

* **Reading area width and text size controls**
  ([#225](https://github.com/HugoFara/lwt/issues/225)): The text reading
  toolbar now has a "Display" dropdown with controls for adjusting reading area
  width (40–100% slider) and text size (+/- buttons). Both settings persist
  across sessions as user preferences. The dropdown also groups the multi-word
  expressions toggle, translations toggle, and print link.

### Fixed

* **EPUB import failing** ([#231](https://github.com/HugoFara/lwt/issues/231)):
  EPUB uploads always failed with "Invalid EPUB file" because the extension check
  validated the PHP temp path (`/tmp/phpXXXXXX`) instead of the original filename.
  The validation now uses the uploaded filename for the extension check.
* **Glosbe translation blocked by mixed content and CSP**: The Glosbe JSONP
  translation API was called over plain HTTP, which browsers block on HTTPS pages.
  Upgraded to HTTPS and added `glosbe.com` to the `script-src-elem` CSP directive.
* **Anki export missing sentences**: Words whose sentences lacked `{word}` markup
  were silently excluded from Anki exports. The export now also includes sentences
  that contain the word without markup, and auto-wraps the markup for highlighting.
  Empty translations and sentences are also filtered out.
* **License file not found in page headers**: `PageLayoutHelper` still referenced
  the old `UNLICENSE.md` filename after the license file was renamed to `LICENSE`.

## [3.0.1-fork] - 2026-03-15

### Fixed

* **Docker image build** ([#230](https://github.com/HugoFara/lwt/discussions/230)):
  MeCab system packages and `mecab-python3` now skip gracefully on unsupported
  architectures (arm/v7, s390x, mips64le, etc.) instead of failing the entire
  Docker build. Japanese parsing remains available on amd64 and arm64.

## [3.0.0-fork] - 2026-03-14

### Added

* **Notes Field for Terms** ([#128](https://github.com/HugoFara/lwt/issues/128)):
  Added a dedicated "Notes" field to terms/words, allowing users to add personal
  notes separate from translations. Includes database migration, updated entity
  classes, service layer, UI forms, and API endpoints.
* **Inline Markdown for Translations and Notes** ([#126](https://github.com/HugoFara/lwt/issues/126)):
  Translations and notes now support inline Markdown formatting: `**bold**`,
  `*italic*`, `~~strikethrough~~`, and `[links](url)`. Markdown is rendered in
  the word modal, annotations (translations shown above/below words), word list,
  test screens, and word detail views. Implemented with custom lightweight parsers
  in both TypeScript and PHP with XSS protection (HTML escaped before parsing,
  URLs sanitized to block javascript: schemes).
* Official support for PHP 8.3 and 8.4.
* **Multi-user support** ([#221](https://github.com/HugoFara/lwt/issues/221)):
  Users are now stored in a dedicated `users` table with proper foreign key
  relationships instead of using table prefixes. Includes user authentication,
  registration, and role-based access (user/admin).
* **MVC Architecture** ([#212](https://github.com/HugoFara/lwt/issues/212),
  [#215](https://github.com/HugoFara/lwt/issues/215)): Complete restructure of
  the PHP codebase following the Model-View-Controller pattern:
  * Controllers in `src/backend/Controllers/` (17 controller classes)
  * Services in `src/backend/Services/` (35 service classes)
  * Views in `src/backend/Views/`
  * Router-based URL handling in `src/backend/Router/`
* **Front Controller Pattern** ([#213](https://github.com/HugoFara/lwt/issues/213)):
  All requests now route through `index.php`. The root folder no longer contains
  dozens of PHP files - only `index.php` and `router.php` remain.
* **Alpine.js Frontend Framework** ([#208](https://github.com/HugoFara/lwt/issues/208)):
  Adopted Alpine.js for reactive UI components, reducing reliance on PHP-rendered
  pages for user interactions.
* **TypeScript Migration** ([#209](https://github.com/HugoFara/lwt/issues/209)):
  Frontend JavaScript converted to TypeScript with 90+ `.ts` files organized in
  `src/frontend/js/` with proper module structure (api/, core/, reading/, testing/,
  feeds/, languages/, etc.).
* **jQuery Removal** ([#211](https://github.com/HugoFara/lwt/issues/211)):
  jQuery has been replaced with modern vanilla JavaScript and Alpine.js.
* **Overlib Replacement** ([#214](https://github.com/HugoFara/lwt/issues/214)):
  The 20-year-old overlib library has been removed in favor of modern alternatives.
* **HoverIntent Replacement** ([#210](https://github.com/HugoFara/lwt/issues/210)):
  The `briancherne/jquery-hoverintent` plugin has been replaced with a native
  TypeScript implementation.
* **Iframe Replacement** ([#166](https://github.com/HugoFara/lwt/issues/166)):
  Legacy iframe-based dynamic content loading replaced with Alpine.js components
  and REST API calls. Only dictionary iframes remain for external dictionary display.
* **Extensible Language Parsers** ([#222](https://github.com/HugoFara/lwt/issues/222)):
  Implemented a clean, extensible parser architecture using the Strategy Pattern.
  Each language can now specify its parser type via the `LgParserType` database column:
  * **Regex Parser** (default): Standard regex-based tokenization for most languages
  * **Character Parser**: Character-by-character splitting for CJK languages (Chinese)
  * **MeCab Parser**: Japanese morphological analyzer integration
  New parser infrastructure in `src/backend/Core/Parser/` includes `ParserInterface`,
  `ParserRegistry`, `ParsingCoordinator`, and value objects (`Token`, `ParserResult`,
  `ParserConfig`). The language edit form dynamically shows/hides relevant options
  based on the selected parser. Future parsers can be added by implementing
  `ParserInterface` and registering in `ParserRegistry`.

### Changed

* **Database Engine Migration** ([#220](https://github.com/HugoFara/lwt/issues/220)):
  All permanent tables converted from MyISAM to InnoDB engine. Benefits include:
  * ACID transaction support
  * Row-level locking for better concurrency
  * Foreign key constraints for data integrity
  * Improved crash recovery
* **Inter-Table Foreign Keys** ([#220](https://github.com/HugoFara/lwt/issues/220)):
  Added 16 foreign key constraints for referential integrity between tables:
  * Language references: texts, words, sentences, archivedtexts, newsfeeds → languages (CASCADE)
  * Text references: sentences, textitems2, texttags → texts (CASCADE)
  * Other references: textitems2 → sentences (CASCADE), textitems2 → words (SET NULL),
    wordtags → words/tags, texttags → tags2, archtexttags → archivedtexts/tags2,
    feedlinks → newsfeeds (all CASCADE)
  * Migration: `20251221_120000_add_inter_table_foreign_keys.sql`
  * **Breaking change**: `textitems2.Ti2WoID` now uses NULL instead of 0 for unknown words
* Database schema now includes foreign key constraints linking user-owned data
  (languages, texts, words, tags, feeds) to the `users` table with CASCADE delete.
* **TTS Settings Storage** ([#186](https://github.com/HugoFara/lwt/issues/186)):
  Text-to-Speech language settings (voice, rate, pitch) now use browser localStorage
  instead of cookies. Includes automatic migration from old cookie format.
* **Icon Migration to Lucide SVG**: All 97 legacy Fugue PNG icons (16x16 pixels)
  have been replaced with modern Lucide SVG icons. Benefits include scalable
  vector graphics, CSS customization (colors, sizes), and reduced asset size.
  The `IconHelper` class provides a unified interface for rendering icons.
* **Removed SYSTEM_VARIABLES_ADMIN Privilege Requirement**
  ([#167](https://github.com/HugoFara/lwt/issues/167)): LWT no longer requires
  elevated database privileges for word imports. The `SET GLOBAL max_heap_table_size`
  call has been removed and replaced with chunked batch inserts (500 rows per batch)
  that stream files line-by-line, reducing memory usage for large imports.

### Fixed

* **Tag Duplicate Key Error** ([#120](https://github.com/HugoFara/lwt/issues/120)):
  Fixed rare error when updating a word with tags. When the session cache was
  stale, saving a word with an existing tag would fail with "Duplicate entry
  for key 'TgText'" and remove the tag from the word. Changed tag insertion
  to use `INSERT IGNORE` to handle race conditions and stale cache gracefully.
* **Japanese Annotations** ([#101](https://github.com/HugoFara/lwt/issues/101)):
  Fixed annotations not displaying correctly in Japanese texts. The
  `annotationToJson()` function was using an off-by-one index that didn't
  match the `Ti2Order` values used by the frontend, causing approximately
  50% of annotations to fail to display.
* **Text Parsing** ([#114](https://github.com/HugoFara/lwt/issues/114)): Fixed
  last word of text not being recognized when text ends without punctuation.
  Words at the end of a text are now correctly identified regardless of trailing
  punctuation.
* **PHP 8.3 Compatibility**: Fixed deprecation warnings in `langFromDict()` and
  `targetLangFromDict()` - both functions now check for null query strings before
  calling `parse_str()`.
* **Database Maintenance**: Fixed `initWordCount()` generating invalid SQL for
  split-each-char languages (e.g., Chinese). When `preg_match_all` returned 0
  matches, the CASE statement was malformed. Word count now defaults to 1 minimum.
* **SQL Prefix Queries**: Fixed `prefixQuery()` in database migrations:
  * Now handles `DROP TABLE IF EXISTS` syntax (previously only `IF NOT EXISTS`).
  * Now case-insensitive for SQL keywords (CREATE, DROP, ALTER, INSERT).
* **Punctuation Formatting** ([#125](https://github.com/HugoFara/lwt/issues/125)):
  Fixed punctuation marks (periods, commas, quotation marks) breaking away from
  adjacent words onto separate lines. Punctuation now stays "stuck" to the word
  it belongs to by wrapping word+punctuation pairs in non-breaking groups.

### Security

* Adds session security cookie to protect against session hijacking.
* Fixed SQL injection vulnerabilities in `validateLang()`, `validateText()`,
  `validateTag()`, and `validateArchTextTag()` functions. All validation
  functions now properly sanitize non-numeric input before executing SQL
  queries.
* Fixed SQL injection vulnerabilities in `inc/tags.php`: `getTextTitle()`,
  `saveWordTags()`, `saveTextTags()`, and `saveArchivedTextTags()` now
  validate numeric input before using in SQL queries.
* Fixed SQL syntax errors in tag list functions when handling empty lists or
  failed tag creation. Functions now return gracefully instead of generating
  invalid SQL.

### Deprecated

* Removed testing for PHP 8.0.
* Legacy table prefix system (`$tbpref`) is deprecated in favor of the new
  user-based multi-tenancy. Existing prefixed tables will be migrated to the
  new system automatically.

### Removed

* **Orphaned Frame Settings** ([#116](https://github.com/HugoFara/lwt/issues/116)):
  Removed all legacy frame-related settings from the admin settings page:
  * `set-text-h-frameheight-no-audio` - Frame height without audio
  * `set-text-h-frameheight-with-audio` - Frame height with audio
  * `set-text-l-framewidth-percent` - Width of left frames (text)
  * `set-text-r-frameheight-percent` - Height of right frame (text)
  * `set-test-h-frameheight` - Frame height (test)
  * `set-test-l-framewidth-percent` - Width of left frames (test)
  * `set-test-r-frameheight-percent` - Height of right frame (test)

  These settings controlled frame dimensions in the text reading and test
  interfaces, but became non-functional after the iframe-based layout was
  replaced with Alpine.js components ([#166](https://github.com/HugoFara/lwt/issues/166)).
  Also removed the dead `initFrameResizable()` function from TypeScript.
  Closes #116.

## 2.10.0-fork (April 1 2024)

### Added in 2.10.0-fork

* Support for Japanese with MeCab on Mac! This was added thanks to
[quopquai](https://github.com/quopquai) on [#135](https://github.com/HugoFara/lwt/issues/135).
* New globals ([#163](https://github.com/HugoFara/lwt/issues/163)):
  * On `inc/kernel_utility.php`: `LWT_APP_VERSION` and `LWT_RELEASE_DATE`.
  * On `api.php`: `LWT_API_VERSION` and `LWT_API_RELEASE_DATE`.
  * `src/js/jq_pgm.js`: `LWT_DATA`.
  * `unloadformcheck.js` now declares a new object `lwtFormCheck` that contains
  all the functions needed.
* Important additions to Text-To-Speech (TTS):
  * Word can be read on hover or on click.
  Pull request [#147](https://github.com/HugoFara/lwt/pull/147) by
  [@ProgramComputer](https://github.com/ProgramComputer).
  * You can add a custom text reader with the new voice API feature!
  A courtesy of [@ProgramComputer](https://github.com/ProgramComputer) on pull request
  [#153](https://github.com/HugoFara/lwt/pull/153).
  A feature first requested on [#143](https://github.com/HugoFara/lwt/issues/143).
  Discussion in open on [#174](https://github.com/HugoFara/lwt/discussions/174).
  * New JS functions: `readTextWithExternal` to read a text with an external
  application and `speechDispatcher` that can choose a text reader
  (browser or third party).
* Starts a cleaner database management. Database schema is defined in
`db/schema/baseline.sql` and no longer in PHP code.
* You can choose to add romanization for languages that don't need it in the language
settings ([#119](https://github.com/HugoFara/lwt/issues/119)).
* Docker images are now built for multiple platforms, see PR
[#169](https://github.com/HugoFara/lwt/pull/169), closing discussion
[#141](https://github.com/HugoFara/lwt/discussions/141).
Many thanks to [@ProgramComputer](https://github.com/ProgramComputer)!
* On word review:
  * The "Read words aloud" setting is now saved, PR [#185](https://github.com/HugoFara/lwt/pull/185).
  * UX change: You can click on "Read words aloud" text to check the checkbox.

### Changed in 2.10.0-fork

* Adds missing documentation in the Docker image:
  * MarkDown files in the root folder are now included.
  See [#160](https://github.com/HugoFara/lwt/issues/160).
  It adds `README.md` and `UNLICENSE.md`.
  * Adds `docs/info.html` and `docs/index.html` from [#146](https://github.com/HugoFara/lwt/pull/146).
* Multi-word creation was reviewed to use a simpler JS code.
* Word review:
  * Status + 1 can be set only after word display. Before, you could
  press key up at any time to increase term status, but keydown was effective only
  after solution display ([#159](https://github.com/HugoFara/lwt/issues/159)).
  * When testing sentences, sentences are always displayed, even when less than 70% of words in the sentence are known.
  See [#194](https://github.com/HugoFara/lwt/issues/194).
* New files:
  * `inc/feeds.php` and `inc/tags.php`, stemmed from `inc/session_utility.php`.
  It should not lead to any code or behaviour change.
  * `src/js/overlib_interface.js`, stemmed from `src/js/pgm.js`. It contains all the
  overlib interactions.
  * `src/js/src/js/text_events.js`, stemmed from `src/js/jq_pgm.js`.
  It defines interactions with a text on reading.
* For audio URI, raises the characters length limit from 200 to 2048
([#144](https://github.com/HugoFara/lwt/issues/144)).
* Database:
  * For Linux users, the official database RDBMS recommended is now MariaDB as a drop-in replacement of MySQL.
  * Database creation and migration is now handled directly in SQL, for a safer
behavior.
  * You do no longer need to give to LWT SYSTEM_VARIABLES_ADMIN for text reparsing.
  (new function `checkExpressions`) ([#167](https://github.com/HugoFara/lwt/issues/167)).
  It is still necessary for words import.
* Docker changes:
  * By default, the images now runs at localhot/lwt and no longer in the
  root folder ([installer #4](https://github.com/HugoFara/lwt-docker-installer/pull/4)
  and [#169](https://github.com/HugoFara/lwt/pull/169)).
  * Image updated from `apache-buster` to `apache-bullseye`.
* Composer dependencies up to date 2024-03-24.

### Fixed in 2.10.0-fork

* The database wizard was broken since 2.9.0.
* Word status:
  * It was impossible to change a word status on hover since 2.9.1.
  * Having wrong dictionary link and error reporting enabled prevented word status from being changed.
* Avoids JavaScript error on editing a language when the dictionary field is empty.
* For some feeds, feed wizard session was not working due to ill-configured session.
Solves [#129](https://github.com/HugoFara/lwt/issues/129), thanks to the help of PR
[#168](https://github.com/HugoFara/lwt/pull/168).
* Text reading position was not working consistently when adding several known words.
* Multi-words:
  * Tooltip was not properly saved ([#170](https://github.com/HugoFara/lwt/pull/170)).
  * Translation may be escaped two times ([#170](https://github.com/HugoFara/lwt/pull/170)).
  * Fixes [#170](https://github.com/HugoFara/lwt/pull/170): on creation,
  multi-word was always displayed before the text it encompasses,
  without removing this text.
  * Tootltip title was not properly saved, normally without incidence on user display.
  * Fixes [#69](https://github.com/HugoFara/lwt/issues/69): having the same
  multi-word on multiple texts was displaying it at many positions on creation.
* Japanese:
  * Parsing Japanese texts with MeCab was creating warnings when the text was not
  finishing by a punctuation mark.
  * Multi-words were not saved with MeCab parsing on Japanese.
  * Japanese was always requiring MeCab for TTS, even if it was not used
  ([#155](https://github.com/HugoFara/lwt/pull/155)) and [#182](https://github.com/HugoFara/lwt/pull/182).
* On word review (test):
  * Space keyboard shortcut may have been inoperative.
  * On `api.php`, tests were always set to "multi-word" due to a missing variable
  type conversion. Pull request [#175](https://github.com/HugoFara/lwt/pull/175).
  * Text-To-Speech was not working (suggested by [#187](https://github.com/HugoFara/lwt/pull/187)).
  * Testing terms (`Terms > Multi Actions > Test ALL Terms`) was broken since 2.9.0.
  [#192](https://github.com/HugoFara/lwt/issues/192).
* An explicit dependency to [php-dom](https://www.php.net/manual/en/book.dom.php) was
missing in `INSTALL.sh` (and `composer.json`), as stated in
[#178](https://github.com/HugoFara/lwt/pull/178).
* API: the REST API was not accessible for users running LWT in a root folder.
* On audio, the replay button is fixed:
[issue #138](https://github.com/HugoFara/lwt/issues/138) and
[PR #191](https://github.com/HugoFara/lwt/pull/191).
* The field `SeFirstPos` was missing in table `sentences` of demo database,
resulting in failed installation the first time.
* Editing test annotation would not display since 2.9.1 [#193](https://github.com/HugoFara/lwt/issues/193).

### Deprecated in 2.10.0-fork

* Using any function from `unloadformcheck.js` without using `lwtFormCheck` is deprecated.
* `get_database_prefixes` is deprecated, superseded by `getDatabasePrefix` which is much cleaner.
* Globals defined in `jq_pgm.js` are going into a single global `LWT_DATA`.
* New text creation workflow simplified in `inc/database_connect.php`: `update_default_values` replaced by
`registerSentencesTextItems` and `check_text` by `displayTextStatistics`.

### Full Changelog for 2.10.0-fork

* [2.9.1...2.10.0](https://github.com/HugoFara/lwt/compare/2.9.1...2.10.0)

## 2.9.1-fork (December 29 2023)

### Added in 2.9.1-fork

* New tools for documentation:
  * New JavaScript documentation with [JSDoc](https://jsdoc.app/)! You will find it under `docs/js/`.
  * Better PHP documentation using [phpDocumentor](https://phpdoc.org/). The new documentation is at `docs/php/`.
* New constant `LWT_LANGUAGES_ARRAY`, that replaces `$langDefs` (in `inc/langdefs.php`).
Part of [#163](https://github.com/HugoFara/lwt/issues/163).

### Changed in 2.9.1-fork

* PHP files header docblocks are expanded, notably with a PHP version, package and category name.
* Japanese number are now parsed by MeCab. Big thanks to
[@ProgramComputer](https://github.com/ProgramComputer) for his pull request
[#149](https://github.com/HugoFara/lwt/pull/149)!
* Removes dead feeds from the demo database, as signaled in
[#148](https://github.com/HugoFara/lwt/issues/148).
* Patch update to the REST API to 0.1.1: requests on GET with empty payload were
resulting in error.

### Fixed in 2.9.1-fork

* Fixes [#113](https://github.com/HugoFara/lwt/issues/113): changing a language name
was making text-to-speech unusable and may prompt a warning.
* Since 2.9.0, tests may be unable to run due to a missing key in an AJAX request ("type").
* Since 2.9.0, audio position was not saved properly.
* On `upload_words.php`, "update only empty fields" was broken.
* Translation were not set by default properly when editing terms translations table.
* The field MeCab was set for Japanese even when not asked for.
Signaled on PR [#155](https://github.com/HugoFara/lwt/pull/155), thanks to
[@ProgramComputer](https://github.com/ProgramComputer).
* Since 2.8.1, Japanese word romanization was not displayed any more.
Thanks to 아르노 for signalling the bug on Discord!
* The go slower/faster feature on audio was broken
([#138](https://github.com/HugoFara/lwt/issues/138)), as functions `click_slower`
and `click_faster` were declared two times in `src/js/audio_controller.js`.
* When opening a text, text position was not saved and a `text_id is unknown` error was triggered.
* Do not display an error on step 2 of feed wizard at first initialization
([#129](https://github.com/HugoFara/lwt/issues/129)).
* It was impossible to install the demo database if they was more or less than one instruction a line.
This is fixed, and the SQL file was made more readable.
* Changes `WoStatusChange` default value to '1970-01-01 01:00:01', it was impossible
to install the demo DB out of LWT (related to [#78](https://github.com/HugoFara/lwt/issues/78)).
* Deleting a language deletes the language ([#151](https://github.com/HugoFara/lwt/issues/151)).
Before it was setting the language to empty values.
* On media select, the lwt folder name was prepended to the media folder,
resulting in broken path ([#161](https://github.com/HugoFara/lwt/issues/161)).
* With the REST API, requests with an empty query were resulting in an error.
* `no-minify` composer command was fixed, suggested as a part of
PR [#154](https://github.com/HugoFara/lwt/pull/154/files) by
[@ProgramComputer](https://github.com/ProgramComputer).

### Deprecated in 2.9.1-fork

* PHP documentation using Doxygen (under `docs/html`) is now deprecated and will be removed in 3.0.0.
* Using `$langDefs` is deprecated in favor of `LWT_LANGUAGES_ARRAY` ([#163](https://github.com/HugoFara/lwt/issues/163)).
* Calling the global function `new_pos` to set audio position is deprecated. Please use `lwt_audio_controller.setPosition`.

### Full Changelog for 2.9.1-fork

* [2.9.0...2.9.1](https://github.com/HugoFara/lwt/compare/2.9.0...2.9.1)

## 2.9.0-fork (December 24 2023)

### Added in 2.9.0-fork

* ``langFromDict`` and ``targetLangFromDict`` are now officially going to the
kernel utility functions.
* Text and title boxes change language according to the text's language for
short text creation/edition, long text creation and text check
([#111](https://github.com/HugoFara/lwt/issues/111)).
* Refactored texts with OOP:
  * New class: ``Text`` (in ``inc/classes/Text.php``).
  * New function: ``edit_texts_form`` (in ``edit_texts.php``) that takes a Text object as input.
* Tests (checking if you know words) now use AJAX ([#112](https://github.com/HugoFara/lwt/issues/112)), it has several advantages:
  * Musics play fully
  * Page do not need to reload.
  * The timer continues instead of resetting.
* REST API, the new ``api.php`` is intended to continue developing itself
as a REST API. It features the following interaction:
  * On GET, the endpoints are:
    * `/media-files`: the list of audio and video files in the media folder.
    * `/sentences-with-term`: sentences containing a new term.
    * `/sentences-with-term/{term-id}`: sentences containing a registered term.
    * `/similar-terms`: terms similar to a given one.
    * `/theme-path`: the path for a media using theme.
    * `/terms`
      * `/imported`: imported terms.
      * `/{term-id}/translations`: translations for a term.
    * `/review`
      * `/next-word`: next word to review.
      * `/tomorrow-count`: number of reviews for the next day.
    * `/phonetic-reading`: phonetic reading for a term or sentence.
    * `/texts/statistics`: word statistics on texts.
    * `/version`: REST API version.
  * On POST, the endpoints are:
    * `/settings`: save a setting.
    * `/terms` followed by:
      * `/{term-id}` for any term update, in particular:
        * `/status`, to change the status, followed by either:
          * `/down`: decrement status of a term by one unit.
          * `/up`: increment status of a term by one unit.
          * `/set/{new-status}`: set the status of a term.
        * `/translations`: add a translation for an existing word.
      * `/new`: add a translation for a new word.
    * `/texts/{text-id}` followed by:
      * `/annotation`: change the text annotation
      * `/audio-position`: change `audio` position.
      * `/reading-position`: change the reading position.
* Similar terms mark the word edit form as edited only if something was
actually changed.
* You can now specify a socket for your database through ``$socket`` in
``connect.inc.php``. This feature was brought to you by
[@hangug-eo](https://github.com/hangug-eo) in his PR
[#132](https://github.com/HugoFara/lwt/pull/132).
* You can now change the voice of the TTT feature through
``text_to_speech_settings.php``.
* ``server_data.php`` was reorganized and is more informative with details
about the REST API.

### Changed in 2.9.0-fork

* User Interface Improvements:
  * Prettier UI to navigate between text creation/edition/archive pages.
  * ``flex-spaced`` now sets the property of ``text-align: center;``. This property was inherited before.
  * The long text import page looks a bit nicer.
* User Experience:
  * When reading text, you can click on "Show All" and "Translations" to click the checkbox.
  * When changing a word status, the displayed messaged is now more informative: before database change, success message, now: waiting screen, tentative database change, result message (error or success). Under the hood, we are transitioning from pure PHP to AJAX.
  * It is faster to exit a text page: audio and reading position saves are now asynchronous.
* Annotations "order" are now identical to ``Ti2Order`` (in ``textitems2``). It makes management easier, but users may need to reload the annotations once.
* Updated composer dependencies (up-to-date 2023-11-23).

### Fixed in 2.9.0-fork

* When editing an existing text, surrounding spaces are no longer inserted ([#92](https://github.com/HugoFara/lwt/issues/92)).
* Language code is better detected from translator URL when editing an existing language.
* The field "Short Text Import" on long text import and was not redirecting to
the intended page. Same goes for "New Text" on "Archived Texts" page.
* It was impossible to archive more than one text ([#118](https://github.com/HugoFara/lwt/issues/118)).
* Testing a word was not changing it's color.
* A warning was sent 'Undefined array key "query"' when creating a new word ([#121](https://github.com/HugoFara/lwt/issues/121)).
* A warning when savings settings in ``settings.php`` was sometimes displayed ([#121](https://github.com/HugoFara/lwt/issues/121)).
* Test header was different when testing languages or terms since 2.1.0-fork.
* Wrong link to documentation (``info.php`` instead of ``docs/info.html``). Signaled by Mooncake on Discord.
* Text reading:
  * The reading button is usable at last! Clicking the button a second time stops the reading process ([#108](https://github.com/HugoFara/lwt/issues/118)).
  * Text language code was often not recognized in TTT settings (``text_to_speech_settings.php``).
  * Clicking the "Read" button in TTT Settings was refreshing the page.
* It was impossible to copy a similar term that had apostrophes ([#127](https://github.com/HugoFara/lwt/issues/127)).
* A deprecation warning for ``trim`` was sent in simterms when no similar term was found.
* During a text creation, the ``media_uri`` property of ``Text`` was used instead of ``source``. Thanks to [@hangug-eo](https://github.com/hangug-eo) for his PR [#133](https://github.com/HugoFara/lwt/pull/133)!
* When looking for an audio file on file edit/creation, the displayed path may have been wrong for characters between 0x80 and 0x9F.
* Multi-words were not properly highlighted for languages separating each
character as a word (e. g.: Chinese). Big thanks to [@hangug-eo](https://github.com/hangug-eo) for signaling the issue and solving it in his PR [#140](https://github.com/HugoFara/lwt/pull/140)!
* The database wizard was not correctly saving the password. This is fixed by
[@hangug-eo](https://github.com/hangug-eo) in
[#132](https://github.com/HugoFara/lwt/pull/132).
* The list of translations of a term was sometimes not found on
text annotation edition.

### Deprecated in 2.9.0-fork

* Legacy AJAX API. The following AJAX interactions are now deprecated in favor to the new REST API (at ``api.php``):
  * ``inc/ajax_show_similar_terms.php``, use ``/similar-terms`` on GET, `simterms_lgid` becomes `lg_id` and `simterms_word` `term`.
  * ``inc/ajax_add_term_transl.php``
    * For a new term, use `/terms/new`. `lang` becomes `lg_id`, `text` is `term_text`.
    * To update an existing term, use `/terms/{term-id}/translations`. Same arguments.
  * ``inc/ajax_check_regexp.php`` should no longer be used and will be removed.
  * ``inc/ajax_chg_term_status.php`` should be accessed through ``/terms/{term-id}/status``. Argument ``data`` is now part of the URL, on post only.
  * ``inc/ajax_get_phonetic.php`` should be accessed through ``/phonetic-reading``, same arguments.
  * ``inc/ajax_get_theme.php`` should be accessed through ``/settings/theme-path``. Argument ``filepath`` is now ``path``.
  * ``inc/ajax_save_setting.php`` should be accessed through ``/settings``, `k` becomes `key` and `v` `value`.
  * ``inc/ajax_save_text_position.php`` should be accessed through ``/texts/{text-id}/reading-position``, `tposition` is now `position`.
  * ``inc/ajax_word_counts.php`` should be accessed through ``/texts/statistics``, on GET. Argument `id` is now `texts_id`.
  * ``inc/ajax_update_media_select.php`` should be accessed through ``/media-files``.
  * ``inc/ajax_show_sentences.php`` should be accessed through ``/sentences-with-term``, on GET. Argument ``lang`` is now ``lg_id``, ``word`` is ``word_lc`` and ``woid`` is ``word_id``, ``ctl`` is no longer required.
  * ``inc/ajax_show_imported_terms.php`` should be accessed through ``/terms/imported``, same arguments.
  * ``inc/ajax_save_impr_text.php`` should be accessed through ``/texts/{text-id}/annotation``, ``id`` is now optional.
  * ``inc/ajax_edit_impr_text.php``:
    * On display, using ``word=""`` is now deprecated as the page loads in pure PHP.
    * On term edition, should be accessed through ``/terms/{term-id}/translations``, ``id`` is now ``text_id`` and ``word`` becomes ``text_lc``.
* During a word review (test), a "free" test where the user design there own test SQL is now deprecated and will be removed.

### Security in 2.9.0-fork

* During tests (word review), test data is no longer be send as raw SQL string, except for "free" test.

### Full Changelog for 2.9.0-fork

* [2.8.1...2.9.0](https://github.com/HugoFara/lwt/compare/2.8.1...2.9.0)

## 2.8.1-fork (April 14 2023)

### Changed in 2.8.1-fork

* Updated some documentation screenshots: home screen, language edition and terms upload.

### Fixed in 2.8.1-fork

* Since 2.8.0-fork, PHP installations with ext-dom absent or disabled could not display text.
* Using the import terms feature, it is better detected if local infile is enabled.
* Since 2.2.2-fork, many multi-words were not displayed, as explained in [#70](https://github.com/HugoFara/lwt/issues/70). Issues may remain.
* Auto-translation of all words (bulk translate) could not open dictionaries starting with '*'.
* Since 2.7.0-fork, using a dictionary starting with "ggl.php" was creating a fatal error on opening text.

### Full Changelog for 2.8.1-fork

* [2.8.0...2.8.1](https://github.com/HugoFara/lwt/compare/2.8.0...2.8.1)

## 2.8.0-fork (April 08 2023)

### Added in 2.8.0-fork

* Responsive design: LWT gets easier to visualize on phone!

### Changed in 2.8.0-fork

* The language settings wizard is now *open by default* on new language creation.
* Potential breaking change in the way words are displayed while reading. The target was to make the code more robust.
* ``item_parser`` and ``echo_term`` in ``do_text_text.php`` now both takes an optional ``$expr`` argument.
* Terms import form changed for a simpler presentation. It becomes easier to use to use on your phone.
* Many tables are now displayed larger (when your screen has the available space).
* Updated composer dependencies.

### Fixed in 2.8.0-fork

* Removed warnings: dictionaries url without query do no longer raise warnings.
* Feeds:
  * Click "New Text" on the first page of ``do_feeds.php`` had no effect. Changed to "New Feed".
  * Step 2 of feed wizard was sometimes failing because ``$_SESSION['wizard']['host']`` was a string an not an array.
  * Type error in ``get_links_from_new_feed`` was breaking step 2 of feed wizard.
  * Loading feeds could result in malformed SQL, see [#100](https://github.com/HugoFara/lwt/issues/100). Thanks [@maanh96](https://github.com/maanh96) for the hint!
* Docker: ``media/`` was neither accessible nor saved to a container ([#106](https://github.com/HugoFara/lwt/issues/106)). Thanks to [@parradam](https://github.com/parradam) for signaling and solving the issue!
* On creating a language, the Glosbe dictionary comes with a "?lwt_popup=1" to open in new window by default.
* Japanese pronunciation: works even if the language is not exactly called "Japanese", see [#103](https://github.com/HugoFara/lwt/issues/103).
* Expressions containing word feature repaired! [#90](https://github.com/HugoFara/lwt/issues/90).
* On text-to-speech settings, the region was often not displayed.

### Full Changelog for 2.8.0-fork

* [2.7.0...2.8.0](https://github.com/HugoFara/lwt/compare/2.7.0...2.8.0)

## 2.7.0-fork (March 14 2023)

### Added in 2.7.0-fork

* The translation and romanization of new words can now be automatic.
  * Supported automatic translation is achieved using [LibreTranslate](https://libretranslate.com/).
  * Romanization using [MeCab](https://taku910.github.io/mecab/) (Japanese only) toward katakana only.
* A lot of improvements for language creation/edition:
  * More intuitive fields, better interactions with the user, etc...
  * A "Pop-Up" checkbox helps you choose if the website should be displayed in a pop-up.
* LWT becomes easier to install and maintain for users:
  * An installer for Linux users at ``INSTALL.sh`` helps installing requirements and configuration.
  * Database creation wizard (``database_wizard.php``) to help setting the connection with the database.
  * A server data page at ``server_data.php`` showing all relevant information.
* In ``index.php``:
  * ``get_server_data_table`` replaces ``get_server_data`` as a better designed function.
  * ``index_do_main_page`` now renders the page to avoid global variables.
  * You get warnings if the PHP version is too low, or if a newer version of LWT is released.
* ``pagestart_kernel_nobody`` in ``inc/kernel.php`` that displays the minimal HTML formatting.
* ``inc/classes/`` folder for a better incorporation of OOP.
  * ``inc/classes/Term`` defines the ``Term`` class representing a word or multi-word.
  * ``inc/classes/Language`` defines the ``Language`` class representing a language.
* Post-Install Documentation added:
  * MeCab without and with Docker, Thanks to [@99MengXin](https://github.com/99MengXin) for the link ([#94](https://github.com/HugoFara/lwt/issues/94#issuecomment-1437030748)).
  * LibreTranslate integration.
  * TTS issues, as suggested by [@asdrubalivan](https://github.com/asdrubalivan) in [#85](https://github.com/HugoFara/lwt/issues/85#issuecomment-1369751473).

### Changed in 2.7.0-fork

* Graphic review:
  * Headers reviewed. Most h4 headers became div.bigger, and h3 became h1 level headers.
  * We now use responsive design instead of fixed-size.
  * All themes updated accordingly, so themes were modified.
  * ``do_text_page`` (``do_text.php``) and ``do_test_page`` (``do_test.php``) now enlarge the body.
* ``echo_lwt_logo`` in ``inc/session_utility.php`` echoes a logo, without information on the table set used
because it was useless. This information is now displayed on the welcome page.
* Access to the database prefix edition at ``start.php`` was reviewed and simplified. It is still considered a legacy feature.
* Language creation reviewed:
  * When creating a new language, the language wizard do no longer display in a pop-up but in the main window.
  * Select option boxes replaced by checkboxes (better accessibility).
* Updated [hoverIntent jQuery Plug-in](http://briancherne.github.io/jquery-hoverIntent/)
from 1.8.0 to 1.10.2. This brings some bug fixes.
* "TO DO" changed to a more explicit "Unknown words" in text header.
* Refactored ``bulk_translate_word.php``. It has a better visual aspect and works better.
* Updated composer dependencies.
  * vimeo/psalm updated from ^4.12 to ^5.6.
  * phpunit/phpunit updated from ^9.5 to ^10.0.
* Changed to the text from YouTube feature (``text_from_yt.php``):
  * Refactored.
  * Moved from root folder to ``inc/``.
  * This is still an experimental feature!
* Moved Google API files:
  * Moved ``googleTimeToken.php`` from root folder to ``inc/google_time_token.php``.
  * Moved ``googleTranslateClass.php`` from root folder to ``inc/classes/GoogleTranslate.php``.
* Updates in demo database:
  * Faster to install. ``install_demo_db_sql.gz`` uncompressed to ``install_demo_db.sql``. Functions adapted.
  * Dict links changed: ### replaced by lwt_term, * replaced by lwt_popup=1, some dict url protocol changed to https.

### Fixed in 2.7.0-fork

* Markdown: enforced consistency with official style recommendations. The documentation display got improved.
* The default date for new words was note accepted depending on the SQL configuration, causing issues with Docker installation. See [#78](https://github.com/HugoFara/lwt/issues/78).
* Bulk translate words:
  * Translating with bulk translate was not updating word rendering.
  * All broom icons icon were display in the screen top-right corner, and not
  at the right if text field.
* Main page (``index.php``) was not rendered properly on non-default theme.
* Changing language name was breaking this, this is fixed:
  * Text header was sometimes not available.
  * It was breaking full text-to-speech dispatcher. Issue signaled [with issue #80](https://github.com/HugoFara/lwt/issues/80#issuecomment-1368180304)
* Support for PHP 7.4 restored, wrappers for ``str_starts_with``, ``str_ends_with``, ``str_contains``.
* The encoding of ``docs/info.html`` is finally fixed!
* ``ggl.php`` feature fixed!
* Multi-words:
  * Sometimes multi-words indicator was cut, resulting in strange display ([#89](https://github.com/HugoFara/lwt/issues/89)).
  * For Japanese: multi-words indicator was before each character, this is fixed [#96](https://github.com/HugoFara/lwt/issues/96).
* Wrong link to documentation (``docs/info.php`` instead of ``docs/info.html``) on the help button (main page). Signaled by babaeali on Discord.
* Depending on browser, clicking on deletion button for active texts had no effect.

### Deprecated in 2.7.0-fork

* Dictionary and translator URIs changed:
  * They should **always** be proper URLs.
  * Replacing ``###`` by ``lwt_term`` is strongly recommended!
  * "*" At the beginning of an URI should be replaced by ``lwt_popup=1`` as an argument.
  * ``ggl.php`` should be replaced by the file full URL, for instance "<http://localhost/lwt/ggl.php>".

### Full Changelog for 2.7.0-fork

* [2.6.0...2.7.0](https://github.com/HugoFara/lwt/compare/2.6.0...2.7.0)

## 2.6.0-fork (January 01 2023)

### Added in 2.6.0-fork

* Frames resizing is back! The text reading and test interfaces updated in several ways. Based on several suggestions such as [#60](https://github.com/HugoFara/lwt/issues/60).
  * The desktop interface is now much similar to what it was before 2.2.1-fork.
  * The mobile interface for texts unchanged (2.2.1-fork to 2.5.3-fork).
  * You can resize frames on desktop.
* Many new functions officially introduced in PHP and JS. Some of these function were already present in the code but undocumented.

### Changed in 2.6.0-fork

* UX: Faster testing: you do no longer need to enter "Space" first for speed testing, except for status down and change. Related to [#71](https://github.com/HugoFara/lwt/pull/71).
* UI:
  * Do no longer show "[]" near words when they are no tags. ``getWordTagList`` behavior changed (``inc/session_utility.php``).
  * Tests have a better general aspect thanks to CSS cleanning.
* PHP:
  * ``do_test.php``, ``edit_texts.php``, ``edit_words.php`` and ``set_test_status.php`` now explicitly require a running session. They were silently failing before this release.
  * ``save_setting_redirect.php`` moved to ``inc/save_setting_redirect.php``.
  * Psalm static code analysis of all PHP files.
  * We use "EOP" for end-of-paragraph markers instead of misleading "EOS" (MeCab).
  * Slightly changed how a connection is established with SQL. It makes messages more relevant when SQL is not running.
  * Not Japanese texts now always use the PHP parser. The SQL parser is no longer used.
* JS: Some deprecated functions ``escape`` and ``unescape`` were replaced by modern equivalents ``encodeURIcomponent`` and ``decodeURIcomponent``. This may lead to changes in cookies, notably making them work better.
* DB: the NO_ZERO_DATE mode is no longer required, see [#78](https://github.com/HugoFara/lwt/issues/78).
  * In the ``words`` table, replaced the default timestamp ``0000-00-00 00:00:00`` by ``0000-00-00 00:00:01``.
  * The demo database underwent the same change.
* Updated ``composer.lock``.
* Docker: more default options, documentation updated.

### Deprecated in 2.6.0-fork

* ``do_test_test_css`` in ``do_test_test.php`` is deprecated since it was causing display issues. Its CSS rules were trimmed.

### Removed in 2.6.0-fork

* The ability to use a dictionary with a specific encoding, introduced in 1.0.2, is being removed. It was making things overwhelmingly complex and caused issues, as signaled in [#58](https://github.com/HugoFara/lwt/issues/58). Adapted from PR [#59](https://github.com/HugoFara/lwt/pull/59).

### Fixed in 2.6.0-fork

* Docker:
  * Docker integration repaired ([#37](https://github.com/HugoFara/lwt/issues/37))! Huge thanks to [@hakuro-jp](https://github.com/hakuro-jp) for the initial help and [@darkone23](https://github.com/darkone23) for the final solution. Without you two I would have long given up with Docker.
  * Docker continuous integration is back as well due to a rewrite of the workflow.
* Texts:
  * The *audio* player was no longer working since 2.1.0-fork since the play button was hidden.
  * Save text position (``inc/ajax_save_text_position.php``) was broken for all texts. This is fixed.
  * Right frames should hide automatically but they often don't ([#61](https://github.com/HugoFara/lwt/issues/61)). Merged PR [#62](https://github.com/HugoFara/lwt/pull/62).
  * Japanese parsing is now better, and uses PHP only (the local_infile SQL functionality is no longer used). Related to PR [#43](https://github.com/HugoFara/lwt/pull/43).
  * One-word not Japanese texts do no longer result in a crash ([#80](https://github.com/HugoFara/lwt/issues/80)), whoever uses them.
* Tests:
  * Header was hidden during tests on Chrome-based browsers.
  * Testing specific terms was broken ([#66](https://github.com/HugoFara/lwt/issues/66)) and tests were sometimes not counting score. Solution inspired from PR [#67](https://github.com/HugoFara/lwt/issues/67) from [@jzohrab](https://github.com/jzohrab).
  * Sometimes tests were loop-reloading clicking after setting new status, this is fixed.
* JS:
  * You should no longer see annoying console messages of "cClick" crashing on "obj is null".
* PHP:
  * Type fixes thanks to psalm:
    * ``get_first_value`` documentation updated since it was also returning ``float`` and ``int``.
    * ``get_similar_terms`` in ``simterms.php`` officially returns int.
  * Inconsistent option in ``inc/ajax_save_setting.php``.
    * Since 2.2.2-fork, you had to use a GET request to use it, resulting in authorization errors.
    * POST requests are now again the default way to use it.
    * PHP tries to set the allow_local_infile option during the connection with SQL ([#20](https://github.com/HugoFara/lwt/issues/20), [#40](https://github.com/HugoFara/lwt/issues/40)).
* UI
  * Audio in ``edit_texts.php`` was never shown.
  * When adding text, the user was ask to create a ``media`` folder in ``...``, corrected to ``..``.
* DB:
  * For some users it was impossible to install the default database due to the use of a ZERO date ([#78](https://github.com/HugoFara/lwt/issues/78)).
  * Deleted wrong database instructions ``ADD DROP INDEX TiTextLC`` altering ``temptextitems`` in ``update_database`` of ``database_connect.php``.

### Full Changelog for 2.6.0-fork

* [2.5.3...2.6.0](https://github.com/HugoFara/lwt/compare/2.5.3...2.6.0)

## 2.5.3-fork (November 06 2022)

### Added in 2.5.3-fork

* Links to the Discord community (``README.md`` and ``inc/kernel_utility.php``).

### Changed in 2.5.3-fork

* Renamed ``connect_mamp.php`` to ``connect_mamp.inc.php`` for consistency with documentation.
* "I KNOW ALL" button becomes "Set All to Known" and "IGNORE ALL" becomes "Ignore All".
* Changed the messages when clicking "Set All to Known" or "Ignore All".
* Uncomplete code linting in ``bulk_translate_words.php``. For phone users, it now properly focuses on the main screen after use.
* Only sentences containing more than 70% of known words are shown were testing sentences. Based on an idea from [#53](https://github.com/HugoFara/lwt/issues/53), with contribution of PR [#65](https://github.com/HugoFara/lwt/pull/65).

### Fixed in 2.5.3-fork

* Left-to-right languages where displayed as right-to-left when checking them, as signaled in [pull request #42](https://github.com/HugoFara/lwt/pull/42), thanks [@Heliozoa](https://github.com/Heliozoa)!
* People who didn't fill the URL for Google Translate were seeing deprecation warnings while using using the ``bulk_translate`` functionality (clicking in "TO DO" for editing multiple new words). Bulk translate itself is not fixed, but warnings are no longer displayed. Merged [pull request #44](https://github.com/HugoFara/lwt/pull/44), from [@Heliozoa](https://github.com/Heliozoa).
* Multiple fixes to the "I Known All Words" feature. Great thanks to [@jzohrab](https://github.com/jzohrab)!
  * Setting all words to well-known was resulting in a crash when no words were added as well-known (``all_words_wellknown.php``).
  * Setting all words to well-known was resulting in a crash when multiple words had the same lowercase value. See issue [#46](https://github.com/HugoFara/lwt/issues/46)!
  * Words were not updated in the view unless reparsing the text ([#48](https://github.com/HugoFara/lwt/pull/48)). Merged pull request [#49](https://github.com/HugoFara/lwt/pull/49).
* In the main dropdown menu, the option "Text-to-Speech Settings" was still leading to ``tts.php`` (now ``text_to_speech_settings.php``). Merged pull request [#51](https://github.com/HugoFara/lwt/pull/51), thanks [@jzohrab](https://github.com/jzohrab).
* Database backup/restoration:
  * Fixed database backup, signaled in [#55](https://github.com/HugoFara/lwt/pull/55).
  * Fixed the database restoration, apparently broken since 2.0.3-fork. Pull request [#56](https://github.com/HugoFara/lwt/pull/56) by [@jzohrab](https://github.com/jzohrab).
* Sentences with unknown words were showed in test when it was not supposed to be the case ([#52](https://github.com/HugoFara/lwt/issues/52) and [#64](https://github.com/HugoFara/lwt/issues/64)).

### Full Changelog for 2.5.3-fork

* [2.5.2...2.5.3](https://github.com/HugoFara/lwt/compare/2.5.2...2.5.3)

## 2.5.2-fork (September 27 2022)

### Changed in 2.5.2-fork

* Refactored ``upload_words.php``.

### Fixed in 2.5.2-fork

* Multi-words insertion for languages using no space is now repaired! Thanks to [@gaoxiangluke](https://github.com/gaoxiangluke) for signaling it ([#38](https://github.com/HugoFara/lwt/issues/38)).
* Terms import (``upload_words.php``) can now be used with ``@@GLOBAL.local_infile`` (MySQL) set to 0.
* Deleted a parasite ";" that was displayed after importing terms.

### Full Changelog for 2.5.2-fork

* [2.5.1...2.5.2](https://github.com/HugoFara/lwt/compare/2.5.1...2.5.2)

## 2.5.1-fork (September 16 2022)

### Fixed in 2.5.1-fork

* Having ``@@GLOBAL.local_infile`` (MySQL) set to 0, or any configuration disabling ``LOAD DATA LOCAL INFILE``, was causing a fatal error on adding a new text. This is fixing by a slower method.
  * If ``local_infile`` is enabled, no change should be noticed.
  * This was signaled in issues [#20](https://github.com/HugoFara/lwt/issues/20) and [#40](https://github.com/HugoFara/lwt/issues/40).
* The WordPress binding scripts had multiple issues as reported in [#41](https://github.com/HugoFara/lwt/issues/41), thanks [@Redmattski](https://github.com/Redmattski)!

### Full Changelog for 2.5.1-fork

* [2.5.0...2.5.1](https://github.com/HugoFara/lwt/compare/2.5.0...2.5.1)

## 2.5.0-fork (July 08 2022)

### Added in 2.5.0-fork

* Compatibility with PHP 8+!
* Updated with LWT 2.0.3, solving incompatibilities with PHP 8.1 throwing MySQLI errors.
* Introducing the new class ``Term`` in ``edit_mword.php``. This class is yet to be considered experimental, so expect important changes, but should gradually get used everywhere.
* ``insert_expression_from_mecab`` in ``session_utility.php``. Better name and behavior for ``insertExpressionFromMeCab`` (see deprecations).

### Changed in 2.5.0-fork

* Composer dependencies updated.
* The Docker container now uses PHP 8.1 (instead of 7.4).

### Fixed in 2.5.0-fork

* For some user, the --secure-priv-file option was still required to be on. This was due to a conflict between PHP and MySQLI authorizations.
* When deleting a word, it was previously necessary to reload the page to see a visual change.
* Multi-words insertion fixed.
* "Show Sentences" button during words edition was broken since 2.3.0-fork.

### Full Changelog for 2.5.0-fork

* [2.4.1...2.5.0](https://github.com/HugoFara/lwt/compare/2.4.1...2.5.0)

### Deprecated in 2.5.0-fork

* ``set_word_count`` in ``database_connect.php`` had a misleading name. It was changed to ``init_word_count``.
* ``insertExpressionFromMeCab`` deprecated for ``insert_expression_from_mecab`` in ``session_utility.php``.
* PHP <8 won't be tested anymore. You are highly encouraged to update to PHP 8+!

## 2.0.3 (February 15 2022)

### Fixed in 2.0.3

* An incompatibility with PHP 8.1+ (changed mysqli_report default setting in PHP 8.1+) has been fixed.

## 2.4.1-fork (June 09 2022)

### Changed in 2.4.1-fork

* Some function signature were not logical in ``edit_texts.php``. These signatures remain untouched for backward compatibility, but the internals were changed.
* Texts without tag do no longer display a "[]" string next to the title. Applies to both archived and active texts.

### Fixed in 2.4.1-fork

* A typo was breaking the feeds count in ``edit_languages.php``, creating annoying notices as illustrated at [#35](https://github.com/HugoFara/lwt/issues/35).
* The error "[1290] The MySQL server is running with the --secure-file-priv option" should no longer appear when trying to save Japanese texts. It was referenced [in this comment](https://github.com/HugoFara/lwt/issues/34#issuecomment-1141976723) in [issue #34](https://github.com/HugoFara/lwt/issues/34).
* The "Undefined index: trans in .../bulk_translate_word.php" notice fixed.
* The "Undefined index: WoText in .../delete_word.php" notice fixed.
* Repaired ``long_text_import.php`` for non-Japanese texts, it was broken since 2.4.0. Thanks to [@rc-ops](https://github.com/rc-ops) for this issue [#33](https://github.com/HugoFara/lwt/issues/33).
* Text statistics were not displayed when there was more than one text since 2.2.2-fork.

### Full Changelog for 2.4.1-fork

* [2.4.0...2.4.1](https://github.com/HugoFara/lwt/compare/2.4.0...2.4.1)

## 2.4.0-fork (May 23 2022)

### Added in 2.4.0-fork

* The external dependency for Japanese parsing, MeCab, is now more easily detected on Windows and Linux.
* Better integration of Docker.

### Changed in 2.4.0-fork

* You must not only include ``pgm.js`` to ask the user before exiting but also the new function ``ask_before_exiting``.
* ``splitCheckText`` was split into smaller functions.
* Refactored ``edit_languages.php`` with functional paradigm.

### Fixed in 2.4.0-fork

* Many pages were asking before exiting while it was unnecessary.
* Made bigger buttons and unified the presentation of ``edit_texts.php``,
``edit_archivedtexts.php``, ``long_text_import.php`` and ``do_feeds.php``. It solves
issue [#29](https://github.com/HugoFara/lwt/issues/29).
* Japanese texts were ill parsed on Windows. The issue [#23](https://github.com/HugoFara/lwt/issues/23) is now solved!
[@rc-ops](https://github.com/rc-ops), arigatou!
* The number of texts, expressions and so on was always 0 in ``edit_languages.php``.
* On Windows, annoying notices were often displayed in ``edit_languages.php``.

### Removed in 2.4.0-fork

* The HTML code documentation is no longer included in the dev branch.
* The unnecessary JS files were removed since they were all merged in ``js/``.

### Full Changelog for 2.4.0-fork

* [2.3.0...2.4.0](https://github.com/HugoFara/lwt/compare/2.3.0...2.4.0)

## 2.3.0-fork (April 25 2022)

### Added in 2.3.0-fork

* Commands included in ``Makefile`` where transcripted in ``composer.json``.
* [Docker](https://www.docker.com/) integration! It is done through ``Dockerfile``, ``docker-compose.yml``, ``.dockerignore`` and ``.env`` files.
* You can click on a read icon in order to read a word.
* Text-To-Speech (TTS) Settings!
  * Change the language rate, pitch, or local region for text-to-speech.
* Early support of local videos, discussed in [#9](https://github.com/HugoFara/lwt/issues/9) with [@chaosarium](https://github.com/chaosarium).

### Changed in 2.3.0-fork

* mbstring and mysqli extensions are now clearly asked for by Composer.
* The "database update" part of ``check_update_db`` in ``inc/database_connect.php`` was moved to ``update_database``.
* Almost all JS goes in one file when minified. This has several reasons:
  * Better browser caching: JS code is downloaded once for all.
  * Easier maintaining: it was difficult to manage which php file was needing which JS script.
  * Consistency: scripts were calling functions that should be imported from other files, making the debugging difficult.
* Feed wizard changes
  * The feed wizard got a little broken: the "next" button is always active, even if you did not select text and click "Get".
  * It's style was uniformized with your current theme.
* ``tts.php`` becomes ``text_to_speech_settings.php``. As this file was unused, it is not considered as a breaking change.
* Refactored ``all_words_wellknown.php``, ``edit_texts.php``. Those pages should load a bit faster.
* We do no longer load pages in [Almost Standards Mode](https://developer.mozilla.org/en-US/docs/Web/HTML/Quirks_Mode_and_Standards_Mode). Unless you use some Netscape browser, it should not impact you.

### Deprecated in 2.3.0-fork

* Use ``clean-doc`` install of ``clean`` in ``Makefile`` because it was ambiguous with composer commands.

### Fixed in 2.3.0-fork

* Creating the database was sometimes impossible.
* Database names containing special characters (hyphens, carets, etc...) was not possible.
* The "I KNOW ALL" button calling ``all_words_wellknown.php`` created errors. This file was fixed. Thanks [@nghiaphamtm](https://github.com/nghiaphamtm) (issue [#26](https://github.com/HugoFara/lwt/issues/26)).
* In ``edit_texts.php``, it was not displayed in the barchart when knowing 1 word. Thanks [@chaosarium](https://github.com/chaosarium) for signaling it (issue [#11](https://github.com/HugoFara/lwt/issues/11)).
* Tags were breaking text modification. Issue [#12](https://github.com/HugoFara/lwt/issues/12), thanks [@chaosarium](https://github.com/chaosarium).
* In edit_texts.php, unknown words (with status 0), had no abbreviation display between parentheses. It was showing "Unknown ()".

### Full Changelog for 2.3.0-fork

* [2.2.2...2.3.0](https://github.com/HugoFara/lwt/compare/2.2.2...2.3.0)

## 2.2.2-fork (February 13 2022)

### Added in 2.2.2-fork

* A contribution guide at ``docs/contribute.md``.

### Changed in 2.2.2-fork

* Updated the jQuery deprecated events. It should have no consequence.
* Slightly changed the behavior of the CSS/JS minifiers. The relative paths in the return string were different from the ones in the saved file.
* Regenerated documentation.

### Fixed in 2.2.2-fork

* Some AJAX files could not work properly since 2.0.3-fork.
  * Refreshing the list of audio files in ``edit_texts.php`` works again.
  * Theme in ``info.html`` works now.
  * Some other problems may have been fixed.
* With ``do_test?text=``, the language name displayed instead of "[L2]" was often wrong.
* ``$fixed_tbpref`` was never declared at global scope.
* Fixed an incompatibility in ``database_connect.php``, ``splitCheckText`` with PHP <7.4.
* Impossible to start bulk_translate_new_words from ``do_text.php``.
* The audio player does no longer show at the end of the page (DOM node was not closed).
* Relative paths in themes were often broken. For instance: no images in audio player.
  * An explanation was also added on how to add custom images in your theme.
* Very small errors corrected in some themes.

### Deprecated in 2.2.2-fork

* Deprecated a lot of camelCase functions in ``do_text_text.php`` to their snake_case counterpart. The behavior of the deprecated functions did not change.
  * camelCase to snake_case: ``getTextData`` to ``get_text_data``, ``sentenceParser`` to ``sentence_parser``, ``wordParser`` to ``word_parser`` and ``mainWordLoop`` to ``main_word_loop``
  * Typo fixing: ``getLanguagesSettings`` to ``get_language_settings`` (use singular)
  * Signature changed: ``echoTerm`` to ``echo_term`` (no return value, no ``$hideuntil`` parameter)
  * Name uniformisation: ``prepareStyle`` to ``do_text_text_style`` and  ``do_text_javascript`` to ``do_text_text_javascript``.

### Full Changelog for 2.2.2-fork

* [2.2.1...2.2.2](https://github.com/HugoFara/lwt/compare/2.2.1...2.2.2)

## 2.2.1-fork (February 07 2022)

### Changed in 2.2.1-fork

* Composer in no longer *required* for standard users (but is still required for contributing).
* Updated ``README.md``.
* [league/commonmark](https://packagist.org/packages/league/commonmark) becomes a dev requirement (no longer required for everyone).
* Re-minified ``jquery.tagit.css``, it may have visual consequences.
* Replaced some jQuery functions by their equivalents. It should not have consequences.

### Fixed in 2.2.1-fork

* Calling ``do_text.php?text=`` created a database error, it does no longer.
* Long text were laggy in ``do_text_text.php`` since 2.0.3-fork. Some other issues may have been fixed at the same time.
* Possible insecure PHP string to JS string conversion in ``do_text_header.php``, function ``browser_tts``.

### Deprecated in 2.2.1-fork

* The ``is_mobile`` function now always returns false.
* The ``wordProcessor`` (``do_text_text.php``) function was incorrect.
  * It now always return 0.
  * Please use ``wordParser``, ``sentenceParser`` and some more code instead.

### Removed in 2.2.1-fork

* Removed unnecessary dependencies:
  * [components/jquery](https://packagist.org/packages/components/jquery) version ^3.6 was required, but only 1.12.4 was in use.
  * [flesler/jquery.scrollto](https://packagist.org/packages/flesler/jquery.scrollto) already bundled by git.
  * [mobiledetect/mobiledetectlib](https://packagist.org/packages/mobiledetect/mobiledetectlib) was unused. It is now removed.
  * [happyworm/jplayer](https://packagist.org/packages/happyworm/jplayer) is no longer integrated by composer (no update since 2014), but it still in use.
* The ``components/`` folder was also deleted. It was bundling JS code from composer.
  * It should have been git ignored at least.
  * Its content was unused. The files it was trying to use are duplicated.

### Full Changelog for 2.2.1-fork

* [2.2.0...2.2.1](https://github.com/HugoFara/lwt/compare/2.2.0...2.2.1)

## 2.2.0-fork (February 04 2022)

### Added in 2.2.0-fork

* Sounds while testing terms!
* New minifier for themes: it is now very easy to create new themes.
* JS files added to documentation.
* ``info_export_template.md`` was imported and adapted from official documentation.

### Changed in 2.2.0-fork

* All the do_test*.php part do no longer use frames.
* All the display_impr_text*.php part do no longer use frames.
* It means LWT is now mobile-friendly!
* Regenerated themes.
* "do_text.php?text=..." is the official way to call texts. The "start" argument is still supported.
* New and enhanced design for the welcome page (``index.php``)
* Enhanced semantic for ``docs/info.html``, and for ``docs/*.md`` files.
* The Doxygen-generated content now uses LWT default style.

### Fixed in 2.2.0-fork

* Several database flaws fixed. Now the database should stop rotting with time.
* It is easier to close the right frames in do_text.php.
* When viewing the maximum number of results per page (edit_text.php for instance),
the maximum value was shown as a floating point value.

### Removed in 2.2.0-fork

* Floating Menu from JTricks.com was unused since 2.0.4-fork. It is now deleted.

### Full Changelog for 2.2.0-fork

* [2.1.0...2.2.0](https://github.com/HugoFara/lwt/compare/2.1.0...2.2.0)

## 2.1.0-fork (January 09 2022)

### Added in 2.1.0-fork

* Badges in the README providing up-to-date information on the state of the project.
* When reading, right frames are hidden and will slide into screen when needed.

### Changed in 2.1.0-fork

* PHP >=7.4 is now the official PHP version.
* Refactored the do_test* pages.
* Better CSS minification.
* Code base inconsistencies and security issues fixed
(level 5 and above psalm errors fixed).
* Code is much more strongly typed (~80% of the code base). Level 4 psalm errors partially fixed.
* GitHub continuous integration reviewed.
* Regenerated documentation.

### Fixed in 2.1.0-fork

* ``composer.json`` is now working! Thanks [chaosarium](https://github.com/chaosarium) for signaling
this [issue #4](https://github.com/HugoFara/lwt/issues/4)!
* Since 2.0.3-fork, it was difficult to get annotations. This is no longer the case.
* [tag-it](https://github.com/aehlke/tag-it), [jquery-hoverintent](https://github.com/briancherne/jquery-hoverIntent),
and [jquery-xpath](https://github.com/ilinsky/jquery-xpath) are now copied from raw code, and no longer integrated by composer.

### Removed in 2.1.0-fork

* Effectively dropped support for PHP <=7.

### Full Changelog for 2.1.0-fork

* [v2.0.4-fork...v2.1.0](https://github.com/HugoFara/lwt/compare/v2.0.4-fork...v2.1.0)

## 2.0.4-fork (December 03 2021)

This version brings a better composer compatibility, and starts revamping
mobile compatibility.

### Changed in 2.0.4-fork

* Starting to refactor for 2021 HTML!
* Much less iframes for reading texts.
* Now you can read texts on mobile without the experimental mobile LWT.
* Texts can be read using "focus mode" on most browsers.
* Updated documentation (expanded and refactored).
* Composer is now the recommended way to download lwt.
* PHP_codesniffer is now recommended, and no longer dev-required.
* Refactored many parts of the code, that gets easier to read.

### Removed in 2.0.4-fork

* ``composer.phar`` and ``composer.lock`` are now git ignored.

### Full Changelog for 2.0.4-fork

* [v2.0.3-fork...v2.0.4-fork](https://github.com/HugoFara/lwt/compare/v2.0.3-fork...v2.0.4-fork)

## 2.0.3-fork (November 26 2021)

Serious maintaining is back!

This version should be the stable merge between official v2.0.2 and community maintained 1.6.31-fork.

### Added in 2.0.3-fork

* Show Learning translation setting.
* README.md created
* Code documentation.
* Automatic text-to-speech.
* Integrating Composer to manage dependencies.
* Issue templates for GitHub.
* Markdown integration in PHP.
* Video player for texts.
* Makefile to simplify workflows.

### Changed in 2.0.3-fork

* MeCab is now the default way to learn Japanese.
* JS and CSS are now minified.
* Code linting.
* Important code refactors.

### Full Changelog for 2.0.3-fork

* [v1.6.31-fork...v2.0.3-fork](https://github.com/HugoFara/lwt/compare/v1.6.31-fork...v2.0.3-fork)

## 2.0.2 (September 07 2021)

### Fixed in 2.0.2

* An incompatibility with PHP 8+ (removed function "get\_magic\_quotes\_gpc()" in PHP 8+) has been fixed. Thanks to Lucas L. for the hint.  

## 2.0.1 (October 07 2020)

### Fixed in 2.0.1

* A bug when visiting terms/expressions with key strokes LEFT or RIGHT after a previous status change and with a set status filtering has been fixed.  

## 2.0.0 (October 04 2020)

### Fixed in 2.0.0

* No code changes. Sourceforge links corrected.  
* The old links \[lwt.sf.net\], \[lwt.sourceforge.net\] or \[sourceforge.net/projects/lwt\] are no longer valid!  
* The new links are now [learning-with-texts.sourceforge.io](https://learning-with-texts.sourceforge.io) (documentation and demo database) and [sourceforge.net/projects/learning-with-texts](https://sourceforge.net/projects/learning-with-texts) (project home and downloads).

## 1.6.3 (April 06 2020)

### Added in 1.6.3

* Some missing confirmation dialogues (when deleting a single text, text tag, term, term tag, or language) added.

## 1.6.2 (March 10 2018, this page "info.php" last updated August 12 2019)

### Added in 1.6.2

* Audio playback speed can now be set between 0.5x and 1.5x.  
* Waiting wheel (to indicate saving data to database in the background) added in "Edit Improved Annotated Text".  
* Checking for characters in the Unicode Supplementary Multilingual Planes (> U+FFFF) like emojis or very rare characters improved/added. Such characters are currently not supported.

### Changed in 1.6.2

* jQuery library updated to v1.12.4.  
    "Mobile\_Detect.php" updated to v2.8.30.  
* LWT demo database updated.  
* Documentation updated.

### Fixed in 1.6.2

* Some minor glitches fixed.

### Removed in 1.6.2

* Glosbe API calls via "glosbe\_api.php" in demo database and language settings wizard removed - they often did not work due to API restrictions. The file "glosbe\_api.php" is still supplied as an example of a close integration of a dictionary API into LWT.  

## 1.6.1 (February 01 2016, this page "info.php" last updated January 13 2018)

### Added in 1.6.1

* [Chinese text segmentation "Jieba" link](info.html#links) added in documentation (Important Links - Additional Resources - For learners of Chinese).

### Changed in 1.6.1

* The jQuery and jPlayer libraries have been updated to v1.12.0 and v2.9.2, respectively.
* The jQuery.ScrollTo package has been updated to v2.1.2.

## 1.6.31-fork (October 03 2016)

### Fixed in 1.6.31-fork

* Multibyte character parsing fixed (i.e. Chinese).  

## 1.6.30-fork (July 28 2016)

### Added in 1.6.30-fork

* MeCab support (in development).

### Fixed in 1.6.30-fork

* Google translate API updated.  

## 1.6.29-fork (April 21 2016)

### Changed in 1.6.29-fork

* In abbreviations like 'Mr.' the dot is now part of the term. A reparse of texts is needed to take effect.  
* Wizard Language: Arabic 'RegExp Word Characters' changed.  

### Fixed in 1.6.29-fork

* ggl.php API doesn't work (Token generation fixed).  
* Dictionary doesn't open.  
* Negative/uncorrect WordCount in 'edit\_texts\_php'.  

## 1.6.28-fork (April 07 2016)

### Added in 1.6.28-fork

* DB collation check added.

### Changed in 1.6.28-fork

* Wizard Language Defaults changed.

### Fixed in 1.6.28-fork

* Access denied, LOAD DATA INFILE error (text parsing on a server).  
* 'remove spaces' not working.  

## 1.6.27-fork (February 21 2016)

### Fixed in 1.6.27-fork

* Bugfix: DB backup/import errors.
* RegExp Word Characters are checked for correct syntax when creating/updating language.

### Removed in 1.6.27-fork

* thumbnail/image support removed.  

## 1.6.26-fork (February 11 2016)

### Changed in 1.6.26-fork

* Demo Database updated.  
* mysqli changes from orig. LWT.  
* php-mobile-detect updated.  

### Fixed in 1.6.26-fork

* Some bugfixes: errors when emptying database and installing Demo database, added 'follow redirect' to feed.

## 1.6.25-fork (January 31 2016)

### Added in 1.6.25-fork

* Added German Feed [NachDenkSeiten](http://www.nachdenkseiten.de/?feed=audiopodcast) to DemoDatabase.  
* Added saved expressions to CheckText.

### Changed in 1.6.25-fork

* SplitCheckText rewritten.
* Database changes (table 'temptextitems'): added: TiCount, dropped: TiLgID, TiTxID, TiTextLC and index TiTextLC  
* Database changes : dropped: index WtWoID, index TtTxID, index AgAtID

### Fixed in 1.6.25-fork

* Bugfix: No Word Counts displayed when text has no saved words.

## 1.6.0 (January 28 2016)

### Changed in 1.6.0

* As mysql\_\* database calls are deprecated and are no longer supported by PHP, they have been changed to the corresponding mysqli\_\* calls. If you run a server with PHP version 7.0.0 or higher, you MUST use LWT 1.6.0 or higher. Thanks to Laurens Vercaigne for his work!  
* Debugging updated.
* Status information on start page improved.
* Documentation updated.

## 1.5.21 (January 14 2016)

### Changed in 1.5.21

* [Soft hyphens](https://en.wikipedia.org/wiki/Soft_hyphen) (U+00AD, UTF-8: 0xC2 0xAD) are now automatically removed during text import.  
* "Mobile\_Detect.php" updated to v2.8.19.

## 1.6.24-fork (January 11 2016)

### Added in 1.6.24-fork

* Added possibility to switch between 'unique' and 'total' word count by clicking on 'u'/'t'-button.  

### Changed in 1.6.24-fork

* HTML lang attribute added in testing frame.  
* Speed improvements in 'My Texts' screen.  
* Replaced percentage of 'unknown words' by 'word charts' in 'My Texts' screen.  

### Removed in 1.6.24-fork

* Deleted Setting: Show Word Counts of Texts immediately

## 1.6.23-fork (December 13 2015)

### Added in 1.6.23-fork

* HTML lang attribute added in reading frame.

### Changed in 1.6.23-fork

* Jplayer, Jquery, JqueryUI updated.  
* Google Translate API changes: random google domain access added (domain can be changed in googleTranslateClass.php), updated generateToken.

## 1.6.22-fork (November 11 2015)

### Added in 1.6.22-fork

* Google Translate API(ggl.php): added headers and corrected token.

## 1.6.21-fork (October 16 2015)

### Added in 1.6.21-fork

* Google Translate API(ggl.php): added token to URL.

## 1.6.20-fork (September 26 2015)

### Changed in 1.6.20-fork

* Jquery Changes in the reading frame for hover\_over/highlight words.

### Fixed in 1.6.20-fork

* Google TextToSpeech callback fixed.

## 1.6.19-fork (August 29 2015)

### Added in 1.6.19-fork

* New default settings: 'Tooltips' (new Default: 'JqueryUI') and 'Position of translations' (new Default: 'below').

### Changed in 1.6.19-fork

* Mysql login process changed.  
* JPlayer CSS and Skin changes. Skin are now integrated into 'Themes'.  

## 1.6.18-fork (June 11 2015)

### Added in 1.6.18-fork

* New Setting: Position of translation  
* Translations can now be displayed 'behind', 'in front of', 'above' or 'below' the term in the reading frame.  
* Improved encoding detection in 'newsfeed import'.

### Fixed in 1.6.18-fork

* Words that are created by 'bulk import' are not updated in the reading frame (i.e. when new translations are added afterwards).  
* mysql error 'duplicate entry' in 'newsfeed import'.  

## 1.6.17-fork (May 09 2015)

### Fixed in 1.6.17-fork

* Newsfeed Import doesn't load new links if WordPress is used for multiple users.  

## 1.6.16-fork (May 01 2015)

### Changed in 1.6.16-fork

* Improved Sentence Bondary Detection when parsing texts.  
* User ID is saved in the PHP Session Variable (instead of a Cookie) if wordpress is used to log in.
* Switch to mysqli extension for database connection.  
* Minified CSS and Javascript files; the uncompressed files can be found in the directory 'src'.

## 1.6.15-fork (April 10 2015)

### Changed in 1.6.15-fork

* Some CSS changes.

### Fixed in 1.6.15-fork

* Ggl API retrieval error fixed.

## 1.6.14-fork (March 28 2015)

### Changed in 1.6.14-fork

* Glosbe API now uses javascript (browser based) instead of php(server based) to prevent possible retrieval errors when LWT is installed on a webhoster for multiple users.

## 1.6.13-fork (March 23 2015)

### Added in 1.6.13-fork

* New Setting: Tooltips (JQueryUI will show images in Tooltips in the Read Text Screen)  
* New Feature: You can now add thumbnail images to your terms. If you click on the icon at the left of the translation field in the new\_term/edit\_term frame/window you can select an thumbnail from 'google image search'. In order to display the images in the Read Text Screen you must set 'Tooltips' to 'JQueryUI'. The thumbnail images are not included in the backup at the moment.  
* New Feature: Key binding J for edit term with Google Image Search added

### Fixed in 1.6.13-fork

* 'error when making backup' fixed  

## 1.6.12-fork (March 01 2015)

### Changed in 1.6.12-fork

* Jquery, JqueryUI updated  

### Fixed in 1.6.12-fork

* CSS/jquery fixes when selecting multiple word expressions in text frame  
* Bugfix: new or imported multiple word expressions are not show in the text with 'remove Spaces' is 1 and 'split Each Char' is 0  

## 1.6.11-fork (February 09 2015)

### Fixed in 1.6.11-fork

* Bugfix: 'Show term sentences' and 'Create term sentences' fixed  

## 1.6.10-fork (January 25 2015)

### Changed in 1.6.10-fork

* CSS changes for firefox (version >= 35) in dark themes  

### Fixed in 1.6.10-fork

* Bugfixes in bulk import terms  

## 1.6.9-fork (December 21 2014)

### Fixed in 1.6.9-fork

* Getting article from feed even if there is no link  

## 1.6.8-fork (December 19 2014)

### Changed in 1.6.8-fork

* Google API can now do a requery  
* Dict Lookup from bulk import terms frame is now possible  

## 1.6.7-fork (December 18 2014)

### Fixed in 1.6.7-fork

* Database error in newsfeeds

## 1.6.6-fork (December 16 2014)

### Added in 1.6.6-fork

* Ability to change audio playback speed (doesn't work when using the flash plugin)  
* Combine translation field option when importing words ('Merge translation fields' or 'Update existing translations')  

## 1.6.5-fork (December 01 2014)

### Fixed in 1.6.5-fork

* Error in 'upload\_words.php'  

## 1.6.4-fork (November 29 2014)

### Fixed in 1.6.4-fork

* Wrong dict links for sentence translate  

## 1.6.3-fork (October 12 2014)

### Added in 1.6.3-fork

* Key binding G for edit term with Google Translate added

### Changed in 1.6.3-fork

* Google api rewritten (works on webhoster with cURL-plugin)  

## 1.6.2-fork (October 06 2014)

### Added in 1.6.2-fork

* Key binding T for translating sentence added  
* New Backup Option: official LWT backup added

### Changed in 1.6.2-fork

* Database Changes: indexes changed in table words  

## 1.6.1-fork (September 28 2014)

### Added in 1.6.1-fork

* Translations of terms can now be display in the reading frame  
* Key bindings when hovering over words in the reading frame  
* Bulk translate new words in the reading frame

Changes from official LWT version 1.5.20 imported:  

* Possibility to display similar terms while creating or editing a term. This will give you more language insight, and may ease inputting new terms that are similar. The number of displayed similar terms can be set from 0 (old behavior, default) to 9 on the "Settings" page. Clicking on the green icon in front of a similar term will copy the translation and romanization into the form fields for further editing. Important: If you want to use this new feature, you must change the setting "Similar terms to be displayed while adding/editing a term" to a value greater than 0. It will make more sense to do this if you have already many saved terms (e.g. more than 1,000). If you start with a language and have only a few terms, no or not very similar terms will be normally displayed and this feature will not make much sense.
* "https://" dictionary URIs are now allowed in the language settings. Checking of dictionary URIs in the language settings has been improved.

### Changed in 1.6.1-fork

* The Glosbe dictionary page has been improved with a simple form to change the term and do a requery if you are unhappy with the query results.
* The jQuery and jPlayer libraries have been updated to v1.11.1 and v2.7.0, respectively. The jQuery.ScrollTo package has been updated to v1.4.13. The Floating Menu package has been updated to v1.12.  

* Broken links corrected or deleted. From now on, only the installation with EasyPHP for Windows and MAMP for Mac OS X will be explained in detail and is recommended. Other local web server packages, like XAMPP, etc., are of course still possible, but it's beyond the scope of this document to explain all the details for every webserver package (it will also confuse most LWT users who are not familiar with web server packages and their setup).
* "Mobile\_Detect.php" updated to v2.8.3.
* Documentation updated.

## 1.5.20 (September 22 2014)

### Changed in 1.5.20

* "Mobile\_Detect.php" updated to v2.8.3.

### Fixed in 1.5.20

* Missing volume controls in audio player (only on mobile devices) fixed.  

## 1.5.19 (September 15 2014)

* Missing tag cache updating fixed (in "Add tag in all/marked texts or terms").  
* Tag caches now also work properly if several instances of LWT are installed in parallel directories on the same server.  
* Information about [which web browser to use for LWT](info.html#abstract) in this document updated.  

## 1.5.18 (September 14 2014)

### Added in 1.5.18

* Possibility to display similar terms while creating or editing a term. This will give you more language insight, and may ease inputting new terms that are similar. The number of displayed similar terms can be set from 0 (old behavior, default) to 9 on the "Settings" page. Clicking on the green icon in front of a similar term will copy the translation and romanization into the form fields for further editing. Important: If you want to use this new feature, you must change the setting "Similar terms to be displayed while adding/editing a term" to a value greater than 0. It will make more sense to do this if you have already many saved terms (e.g. more than 1,000). If you start with a language and have only a few terms, no or not very similar terms will be normally displayed and this feature will not make much sense.
* New sort option for texts, terms or tags: "Oldest first".  
* The Catalan language has been added to the Language Settings Wizard.

### Changed in 1.5.18

* "https://" dictionary URIs are now allowed in the language settings. Checking of dictionary URIs in the language settings has been improved. The Glosbe dictionary page has been improved with a simple form to change the term and do a requery if you are unhappy with the query results.  
* The jQuery and jPlayer libraries have been updated to v1.11.1 and v2.7.0, respectively. The jQuery.ScrollTo package has been updated to v1.4.13. The Floating Menu package has been updated to v1.12.  
* Some error messages (term/tag already exists) have been improved.  
* Documentation updated.

### Fixed in 1.5.18

* Some minor bugs fixed: media selection in archived texts, tag import errors, adding existing tag errors, etc.

### Removed in 1.5.18

* The audio player skin selection has been removed; the "Blue Monday Small" skin is the standard skin beginning with this release.  

## 1.6.0-fork (September 12 2014)

### Added in 1.6.0-fork

* Longer (>9) expressions can now be saved  
* TextToSpeech support for words added  
* Experimental google api (use 'ggl.php' instead of '\*<http://translate.google.com>' for google translate)  
* New word select mode in read texts (hold down mouse button)

### Changed in 1.6.0-fork

* Database Changes: table textitems replaced by textitems2, temporary tables added, global table tts added  
* statistics.php, upload\_words.php rewritten  

## 1.5.17-fork (June 08 2014)

### Added in 1.5.17-fork

* New Feature: Selecting terms according to a text tag  
* New Feature: Start a document where you left off (only "Read Text Screen")  
* New Feature: Improved Search/Query for Words/Texts  
* New Feature: Automatically import texts from RSS feeds (for more info see: [Newsfeed Import](info.html#Newsfeed%20Import))  
* New Setting: Button(s) for "words to do" "IGNORE ALL"/"I KNOW ALL"  
* New Setting: Theme  
* New Setting: term/word query with standard/regexp/regexp CS  
* New Sort option "Oldest First"  
* New option "Set Active Term(1-5) Sentences" in My Texts

### Changed in 1.5.17-fork

* JQuery, JQuery UI, JPlayer, jQuery.ScrollTo, Tag-it, Sorttable and Floating Menu updated  
* Database table optimization: first check, only optimize if (Overhead >10% of table and > 100KB) or (Overhead > 1,0MB)  
* Database table optimization: data types changed.  
* Documentation updated.

### Fixed in 1.5.17-fork

* Importing multiple words with the same tag causes an error  
* Can't select media in Archived Texts  
* Confirmation-popup when leaving via selectbutton in Settings/Preferences even if there are no changes (chrome-browser)  
* Bottom page select doesn't work in firefox (edit\_texts.php, edit\_words.php, edit\_archivedtexts.php, edit\_texttags.php, edit\_tags.php)  
* Setting a tag where tag already exists causes an error  
* New tag isn't saved in SESSION VAR (when adding a new text with a new tag / may cause an error when editing that text)  

## 1.5.17 (August 15 2014, this document updated Aug 17 2014 and Aug 24 2014)

### Changed in 1.5.17

* Documentation updated. Broken links corrected or deleted. From now on, only the installation with EasyPHP for Windows and MAMP for Mac OS X will be explained in detail and is recommended. Other local web server packages, like XAMPP, etc., are of course still possible, but it's beyond the scope of this document to explain all the details for every webserver package (it will also confuse most LWT users who are not familiar with web server packages and their setup).  
* Documentation updated on August 17 2014: Installation screencasts added.  
* Documentation updated on August 24 2014: Linux (Ubuntu, LinuxMint) installation hints and screencast added.

### Fixed in 1.5.17

* Minor bug in Utilities fixed.  

## 1.5.16 (February 19 2014)

### Changed in 1.5.16

* Documentation updated.

### Fixed in 1.5.16

* Paging (via page select, and only at the bottom of a page) did not work correctly in all cases, has been corrected.

## 1.5.15 (December 17 2013)

### Changed in 1.5.15

* Documentation updated.

### Fixed in 1.5.15

* Corrected wrong language code (French) within the language wizard definitions.

## 1.5.14 (August 05 2013, this document updated Oct 30 2013)

### Changed in 1.5.14

* Documentation updated on October 30 2013.

### Fixed in 1.5.14

* Wrong text display in Print Screen corrected. Special handling of word breaks (if "Remove spaces" = Yes) removed.  

## 1.5.13 (July 22 2013)

### Changed in 1.5.13

* License texts updated according to text on [unlicense.org](http://unlicense.org/).  
* Documentation updated.

### Fixed in 1.5.13

* Removed an erroneous extra space in "wp\_logincheck.inc.php". Thanks to a post in the help forum for pointing this out!  
* Minor bug fixes.  

## 1.5.12 (July 16 2013)

### Added in 1.5.12

* New Sort option for Terms/Expressions: "Word Count in Active Texts" (Descending). Only when you choose this sort option, the word count will be calculated, displayed, and used for sorting the table. This may slow down the term table display. If you prefer faster term table display, choose the other sort options.

### Changed in 1.5.12

* Much better Tablet/iPad® user experience in screens with several frames. There is a new setting "Frame Set Display Mode" where you can select how frame sets are displayed on different devices (default: "Auto"). If you prefer the old mode also on mobile devices, set this to "Force Non-Mobile".  
* Tagging and JQuery UI updated.  
* Better error message when Glosbe API call fails.  
* Unsaved changes alerts extended to tag changes.

### Fixed in 1.5.12

* Tags cache updating corrected: when LWT table set has been changed or after restore/emptying tables.  

## 1.5.11 (July 12 2013)

### Added in 1.5.11

* If an improved annotated text exists, highlight the selected term translation in red in the text window popup and the text display frame (when using the keyboard).  
* New language settings wizard.

### Changed in 1.5.11

* Better check on duplicate language names.  
* Text window popup title is now a link (text color: yellow) to make editing an existent term a little easier.  
* Documentation updated.

### Fixed in 1.5.11

* Some minor bug fixes.  

## 1.5.10 (July 07 2013)

### Added in 1.5.10

* New alerts in some forms when there are unsaved changes during unload event.

### Changed in 1.5.10

* Improved database error checking and reporting, better error messages when fatal errors occur, both with traceback information.  
* Some improvements in Glosbe-LWT integration.
* Documentation updated.

### Fixed in 1.5.10

* Default values corrected in new language form.
* SQL query optimizations in 1.5.8/1.5.9 caused problems, old versions restored.  

## 1.5.9 (July 03 2013)

### Added in 1.5.9

* Long Text Import: Importing a long text via file upload or from a text box, with splitting options.  
* Possibility to save the source URI with an active or archived text.  

### Changed in 1.5.9

* Documentation and some screenshots updated.

### Fixed in 1.5.9

* Missing code in tag management stylesheet restored.  

## 1.5.8 (June 27 2013)

### Changed in 1.5.8

* Optimization of SQL queries for text and print display (reducing query time by up to thirty percent).  
* Checking database status and database upgrade program code rewritten.  
* Some minor improvements and bugfixes. Documentation updated.  

## 1.5.7 (June 25 2013)

### Added in 1.5.7

* A new 3rd "Flexible" term export is introduced, controlled by an "Export Template" in the language settings. [Read more ...](info.html#extmpl)

### Changed in 1.5.7

* Texts are now automatically reparsed, however only after changing language settings that influence the sentence and textitems cache.
* Documentation updated.  

### Fixed in 1.5.7

* Some minor corrections in some SQL CREATE/INSERT statements. MySQL session string is now set to an empty string to avoid too strict SQL checking. Thanks to a poster in the help forum for pointing this out!

## 1.5.6 (June 22 2013)

### Fixed in 1.5.6

* Two SQL statements (Anki/TSV export of marked terms) corrected. Thanks to a poster in the help forum for pointing this out!  

## 1.5.5 (June 21 2013)

### Added in 1.5.5

* Integration of the Glosbe API into LWT via a "special" dictionary link. Read more [in the Glosbe documentation](info.html#glosbe).
* LWT-WordPress integration, read more [in the WordPress integration guide](info.html#wp) (only for users who want to use WordPress authentication together with the LWT multiple user/table set feature introduced in version 1.5.3).

### Changed in 1.5.5

* Some minor improvements: Window width of dictionary popups changed from 600 to 800 Pixel. No "\_lwtgeneral" operations if table prefix is fixed.
* Documentation updated.  

## 1.5.4 (June 19 2013)

### Added in 1.5.4

* If more than one table set exists, and $tbpref was NOT set to a fixed value in "connect.inc.php", you can now select a table set via "start.php", or by clicking on the LWT icon or title in the LWT menu screen "index.php".  
* By hovering over the LWT icon in the top left corner of every screen, you can now display the current table set in a yellow tooltip.  
* A new test/review type "Table" has been added in the testing area. Words, translations, romanizations, sentences and status are presented in a table. You may hide and/or sort columns. After testing yourself, you can reveal the hidden information by clicking into the table cell, and change your status.

### Changed in 1.5.4

* Player appearance improved. Some settings, that were not saved until now, are now automatically saved. Documentation and some screenshots updated.  

## 1.5.3 (June 14 2013)

### Added in 1.5.3

* New Feature: It is now possible to create and to use not only ONE set of LWT tables within one database. You are now able to create and use unlimited LWT table sets within one database (as space and MySQL limitations permit). This feature is especially useful for users who want to set up a multi user environment with a set of tables for each user. You can also create one table set for every language you study - this allows you to create different term/text tags for each language. If you don't need this feature, you just use LWT like in earlier versions with the "default table set". Read more [in the multi-user environment guide](info.html#mue) and [database documentation](info.html#database).  

### Changed in 1.5.3

* Complete code review. Some minor improvements and bugfixes. Documentation & Anki 1+2 template decks updated.  

## 1.5.2 (June 09 2013)

### Added in 1.5.2

* Easy navigation to the previous and the next text (according to current text filters and sort order) is now possible.

### Changed in 1.5.2

* "Backup" does not store anymore the tables 'sentences' and 'textitems'. These tables are now automatically recreated (by reparsing the texts) within "Restore". This makes backup faster and the backup file much smaller, while "Restore" will take a bit longer.
* Documentation and all screenshots updated.  
* Installation procedures (EasyPHP) updated.  

## 1.5.1 (June 07 2013)

### Added in 1.5.1

* Display screen of improved annotated texts (= [hyperliteral translations](http://learnanylanguage.wikia.com/wiki/Hyperliteral_translations) as [interlinear text](http://en.wikipedia.org/wiki/Interlinear_gloss)) improved: Clicking the "T" or "A" lightbulb icons hides/shows the complete text or all annotations. You may also click on a single term or a single annotation to show or to hide it. This enables you to test yourself or to concentrate on one text only. Romanizations, if available, appear now while hovering over a term.

### Changed in 1.5.1

* Documentation and screenshots updated.  

## 1.5.0 (April 22 2013)

### Added in 1.5.0

* New Feature: Create and edit an improved annotated text version (as [interlinear text](http://en.wikipedia.org/wiki/Interlinear_gloss)) for online or offline learning. Read more [in the interlinear text documentation](info.html#il).  
* In-Place-editing of translations and romanizations now possible within the terms table.
* You may now empty (= delete the contents of) the LWT database in the "Backup/Restore/Empty Database" screen.

### Changed in 1.5.0

* Some minor improvements. Documentation, screenshots and demo database updated.  

## 1.4.10 (February 22 2013)

### Added in 1.4.10

* New option in "Print" screen: annotation can now also be placed above the term (via [Ruby characters](http://en.wikipedia.org/wiki/Ruby_character)). This is especially helpful for Chinese and Japanese, when annotating the text with the romanization (Pinyin, Hiragana, Katakana). Your browser must support ruby markup. Firefox needs the [HTML Ruby Add-On](https://addons.mozilla.org/de/firefox/addon/html-ruby/) to display ruby markup properly.

### Changed in 1.4.10

* Help document updated.  

## 1.4.9 (August 29 2012)

### Fixed in 1.4.9

* Anki and TSV export bug fixed (in some cases the term tags were not exported).  

## 1.4.8 (May 11 2012, some external links updated June 19 2012)

### Added in 1.4.8

* Timing of transactions now possible as an additional debugging option in settings.inc.php ($dspltime = 1; normally switched off = 0).

### Changed in 1.4.8

* "Important Links" section within this document updated and expanded.  

### Fixed in 1.4.8

* Correction of a small (typo) bug in js/jq\_pgm.js that caused an SQL error when pressing the "E" key (Thank you, anthonylauder!). See also [this thread](http://sourceforge.net/projects/lwt/forums/forum/1813497/topic/5265425).  
* Correction in do\_text\_header.php: fix negative audio positions to zero. See also [this thread](http://sourceforge.net/projects/lwt/forums/forum/1813497/topic/5220016).

## 1.4.7 (April 6 2012)

### Fixed in 1.4.7

* Correction of some minor mistakes and glitches in the code.  

## 1.4.6 (March 14 2012)

### Fixed in 1.4.6

* Documentation and example database corrected. Google Translate links now open in a popup window, not in a frame of the frameset, as Google now disallows this. Please make sure to deactivate popup window blockers.  

## 1.4.5 (October 01 2011, documentation updated Oct 13 2011, external links updated Dec 8 2011)

### Added in 1.4.5

* Double-Click on a term and "A" key (while going through non-blue terms via keyboard) sets audio position approximately to text position. Hovering over sentence marker (green or red dot) in the terms table shows tooltip with sentence. Some updates and corrections in the documentation.  

## 1.4.4 (September 23 2011)

### Changed in 1.4.4

* Changed the handling of backslash removal in posted data on servers with magic\_quotes\_gpc = Off. Documentation updated.  

## 1.4.3 (September 21 2011)

### Changed in 1.4.3

* Changed some unintentionally written short PHP open tags "<?" to "<?php". Thanks to a poster in the help forum for pointing this out.  

## 1.4.2 (September 19 2011)

### Removed in 1.4.2

* Removed PGUP/PGDN key bindings (mark first/last non-blue term, use HOME/END keys). Some minor changes in the documentation.  

## 1.4.1 (September 15 2011)

### Changed in 1.4.1

* Anki Export changed. The full sentence doesn't have brackets anymore.  
* Data in Anki template updated.  

## 1.4.0 (September 09 2011)

### Added in 1.4.0

* Mobile Version (experimental, via mobile.php): Selection of Language, Text, and Sentence, Playing the audio (if exists), Reading the text either sentence-by-sentence or term-by-term (saved words shown with translation, romanization, and status (via color). This mobile interface does not yet allow data manipulations.  
* Texts, archived texts and terms can now also be filtered by "untagged".  
* Added a "Repeat Audio / Single Play" toggle button for media player.  

## 1.3.1 (September 05 2011)

### Added in 1.3.1

* New multi actions for marked/all terms: Set Terms to Lowercase, Capitalize Terms, Delete Sentences of Terms.

### Changed in 1.3.1

* Screenshots updated.

## 1.3.0 (September 03 2011)

### Added in 1.3.0

* Tagging of texts and archived texts introduced. With this feature, it will be easier to categorize and organize your texts. After having tagged your texts, you are able to filter texts according to one or two tags.  
* Rudimentary right-to-left (rtl) script support: new db field in languages to set a language to right-to-left script, all relevant parts with respect to rtl support changed. A simple Hebrew example added to demonstrate rtl support.

### Changed in 1.3.0

* Documentation, screenshots, Anki example file updated.  

## 1.2.2 (August 26 2011)

### Added in 1.2.2

* Added column "Percentage Unknown Words" in Texts table.
* During reading a text, you can now create terms that do not occur in the text. Click on the yellow icon in the top left frame, and type in the term, translation, etc. You may now also edit the text directly.
* iPod touch®/iPhone®/iPad® icons and splash screen added, and HTML header for touch devices modified. (Thanks, Derek!)

### Changed in 1.2.2

* Documentation and screenshots updated.  

### Fixed in 1.2.2

* Removed a bug that prevented Strg-C/Cmd-C (and other key strokes) in text/test frames.  
* Removed a bug in statistics calculation.  
* Removed a bug in text selection dropdown control.
* Removed language column in Texts/Terms tables if language filter is set.

## 1.2.1 (August 25 2011)

### Added in 1.2.1

* New Rewind and Fast Forward button for audio player. User can set the time to rewind or fast forward from 1 to 10 seconds.  
* Added a section in the help document about the setup of LWT for iPads®, etc.

### Changed in 1.2.1

* Documentation and screenshots updated.  

## 1.2.0 (August 24 2011)

* Tagging of terms introduced. Tags are little pieces of information (20 characters max., no spaces, no commas, case sensitive!) attached to terms to help you catagorize and organize your terms. You can import (CSV, TSV) and export (Anki, TSV) terms together with tag information. Two new tables save tag information, and they are created automatically during first usage after update.  
* Display of example sentences is now delayed.  
* Backup creates the database SQL file now as a gzipped file. Restore can process old unzipped or new gzipped files. Gzipped files are much smaller in size (10-20 % of original size).  
* Documentation and screenshots updated.  

## 1.1.1 (August 17 2011)

### Added in 1.1.1

* New option in "Print" screen to select whether annotation should be placed in front (new option) or after (default) the term.  
* New option in settings screen "Visit only saved terms with status(es) ..." to specify which terms are visited when using RIGHT/SPACE/LEFT/etc. keys in the text frame (default: ALL non-blue terms = statuses 1..5, Ign, WKn).

### Changed in 1.1.1

* The Status filter lists have been expanded.
* Documentation and screenshots updated.

### Fixed in 1.1.1

* Fixed problems with non-ASCII characters in media file names (hopefully).

## 1.1.0 (August 16 2011)

### Added in 1.1.0

* New status display during tests: "Elapsed Time / Total = Not yet tested + Wrong + Correct", plus a small bar graph.  
* Tests can now be done also via key strokes (but you must first click in the test frame): SPACE: show solution, UP/DOWN: Status +1/-1, ESC: don't change status, NUMBER KEYS 1-5: set status to 1-5, I: set "Ignored", W: set "Well known", E: edit term.  
* In the "Read Text" frame, the next unknown (blue) word in the text can now be shown for term creation just by pressing the RETURN key. The term will be marked by a red border. You type in the translation, etc., and press RETURN to save the word. Now you can press RETURN again to show the next unknown (blue) word, enter a translation, save it, and so on... There is sometimes the problem that some external dictionaries catch the focus although the cursor should be in the translation field in the edit frame. Especially Chrome behaves badly, and I cannot change this. Please try different dictionaries and/or browsers.  
* You can also review/manage saved (non-blue) terms with key strokes in the "Read Text" frame (RIGHT or SPACE: next term, LEFT: previous term, PAGE-UP or HOME: first term, PAGE-DOWN or END: last term, NUMBER KEYS 1-5: set current term status to 1-5, I: set current term status to "Ignored", W: set current term status to "Well known", E: edit current term, ESC: reset). The current term has a black border and the frame scrolls automatically. The term is displayed in the top right frame.  
* New refresh button refreshes media files combo box (without page reload) on the text input/edit screen.  
* Information added how to install LWT at a (free) webhoster.

### Changed in 1.1.0

* DB design altered: Table "words" changed: 3 new columns to make random word selection (in tests) and score calculation/query faster.  
* Translation, romanization and sentence are now optional. An empty translation or an asterisk in the translation field are equivalent. Terms without translation or in status "Well Known" or "Ignore" will never be tested. Import of terms without translation is now possible.  
* Textarea input boxes have now all a maximum text/bytes length check.  
* Testing algorithm revised, simplified and optimized.  
* Terms due today and tomorrow are marked in score column (red/yellow) in terms table.  
* Documentation and screenshots updated, new floating menu.

### Fixed in 1.1.0

* EasyPHP installation corrected.  

## 1.0.4 (August 11 2011)

### Added in 1.0.4

* Checking maximum text length in text input/edit/check screens.  

### Changed in 1.0.4

* Code cleanup and optimization, better code documentation.
* Media directory is not anymore included, user has to create it if needed. The demo media are now all online.  
* Demo database installation is now done within LWT (optional). If the database is empty, a hint on the main screen is displayed either to install the demo db or to start with the definition of a language. Backup/Restore now with more hints and an option to install the demo database.  
* Documentation and screenshots updated.  

## 1.0.3 (August 09 2011)

### Added in 1.0.3

* New button to open a new text immediately after saving.  
* Dictionaries can now be opened not only within the frame set (default) but also in a popup window (please deactivate popup window blocking in your browser!). To open a dictionary in a separate popup window, put an asterisk \* in front of the Uniform Resource Identifier (Example: \*<http://mywebdict.com?q=###).> Please use this method if a web dictionary does not open properly within the frame set.  
* The application now always checks if the current text or language still exist (could have sometimes resulted in empty query results after deleting).  
* New settings "Texts per Page", "Show Word Counts of Texts immediately", "Archived Texts per Page" and "Terms per Page". You can now define how many texts or terms are on one page. As the calculation of text word counts can slow down the loading of a long text table, you can switch off this calculation (and do the calculation later).  
    "New/Edit Term" now allow dictionary lookup (always in popup window). If you want to enter a term manually, you must first select the language on the "My Terms" page.

### Changed in 1.0.3

* Documentation and screenshots updated.  

## 1.0.2 (August 05 2011)

### Added in 1.0.2

* Language definition: If the searchword in the Uniform Resource Identifiers (URIs) needs to be converted into a different encoding (standard is UTF-8), you can now use *###encoding###* as a placeholder. Example: *<http://mywebdict.com?q=###ISO-8859-15###>*. A list of encodings can be found [in the PHP mbstring documentation](http://php.net/manual/en/mbstring.supported-encodings.php) (omit the asterisk if one is at the end).

### Changed in 1.0.2

* Documentation and screenshots updated. Thai example added in demo database.  

## 1.0.1 (August 04 2011)

### Added in 1.0.1

* Empty database will be created automatically if database does not exist. Tables will be automatically recreated if some or all tables are missing. System handles (future) database changes automatically.  

### Changed in 1.0.1

* Testing: Last term tested will not show up immediately. Automatically selected sentences in tests now must not contain unsaved (blue) words. This does not apply if a sentence saved with a term contains unknown words - such sentences may come up within a test.
* Documentation and screenshots updated.  

## 1.0.0 (August 01 2011)

* First stable release. For some time, there won't be any new releases. I hope you'll understand that. Please post all problems, questions, and (hopefully not too many) bugs [on the SourceForge forum](http://sourceforge.net/projects/lwt/forums/forum/1813497), and ideas and suggestions for new features [on UserVoice](http://lwt.uservoice.com). Thanks!  

## 0.9.8 (July 31 2011)

### Added in 0.9.8

* New Text Display Mode (see new checkbox on the "Read text" screen).  
  * \[Show All\] = ON (the only mode in previous releases): ALL terms are shown, and all multi-word terms are shown as superscripts before the first word. The superscript indicates the number of words in the multi-word term.  
  * \[Show All\] = OFF (new): Multi-word terms now hide single words and shorter or overlapping multi-word terms. This makes it easier to concentrate on multi-word terms while displaying them without superscripts, but creation and deletion of multi-word terms can be a bit slow in long texts.

### Changed in 0.9.8

* Documentation and screenshots updated.  

## 0.9.7 (July 28 2011)

### Changed in 0.9.7

* Internal improvements, status names, abbreviations, and tooltips (Thanks, Arthaey!). Printout of texts with optional inline annotation (translation and/or romanization) of terms that are of specified status(es). Documentation and screenshots corrected.  

## 0.9.6 (July 26 2011)

### Changed in 0.9.6

* More visual improvements (layout, tables, etc.), many improvements and bugfixes. Dictionary Uniform Resource Identifiers (URIs) use now ### as a placeholder for the searchword. If ### is missing, the searchword will be appended (like in older versions). More multi-actions for terms. Translation and sentence fields do NOT accept newlines anymore, now the form is submitted. Status entry changed from dropdown to radio buttons. Backup/Restore improved. CSS and XHTML corrected and validated. Korean example added. An Anki example deck is now provided: "LWT.anki" in directory "anki". Documentation and screenshots improved.  

## 0.9.5 (July 23 2011)

### Changed in 0.9.5

* Visual improvements (icons), a few bug fixes. Database Restore changed for bigger files. Two new tests to test L2 -> L1 or L1 -> L2 without sentence (just the term). The term filter operates not only on the term field but also on the romanization and translation field.  

## 0.9.4 (July 22 2011)

### Added in 0.9.4

* New buttons to switch easier between active texts and archived texts.  
* New settings "Testing: Number of sentences displayed from text, if available" and "Terms: Number of sentences generated from text, if available". Default is "Just ONE". The options "TWO (+ previous)" and "THREE (+ previous, + next)" now allow you to do MCD (Massive-Context Cloze Deletion) testing, as proposed by Khatzumoto @ AJATT.  

### Changed in 0.9.4

* "Import of terms" now only needs a translation if the status is set to 1, 2, 3, 4 or 5. Furthermore it not only imports terms as TAB (ASCII 9) separated values (TSV) or "#" separated values, but also as comma separated values (CSV, strings in quotes ("...", if needed), a quote within a string as double quotes (""); this is the format that LingQ uses for exporting terms).
* Some minor bug fixes. Documentation updated.  

## 0.9.3 (July 21 2011)

### Fixed in 0.9.3

* After a lot of multi-platform/multi-server testing: several bug fixes and improvements. One severe bug that crashed importing and checking of texts on XAMPP/Win removed (Thanks, Kendall!). Within text, archive, and term queries, selected sort order will be retained (saved in database). All other query settings are now retained per session. Documentation updated.  

## 0.9.2 (July 19 2011)

### Fixed in 0.9.2

* New "Settings" screen. Documentation updated and improved.  

## 0.9.1 (July 18 2011)

### Changed in 0.9.1

* Testing totally revamped (and extended). Many, many improvements and bugfixes. Documentation and screenshots updated. EasyPHP for Windows installation explained.

### Fixed in 0.9.1

* Many, many improvements and bugfixes.

## 0.9.0 (July 14 2011)

### Added in 0.9.0

* TSV export of terms added.
* New buttons to jump from test to text and vice versa.
* Questions and answers added

### Changed in 0.9.0

* Frame screen "Edit term" is now more compact to save space.
* Anki export improved.
* The multi actions drop-down-list is now disabled when no checkboxes are checked.
* Testing completely revised and improved.
* It is now possible to create expressions with up to 9 words (previous releases: up to 6 words).
  * Important: Re-parsing is necessary to use this feature in existing texts.
  * New texts have this feature will automatically.
* Documentation and screenshots updated.  

## 0.8.2 (July 13 2011)

### Changed in 0.8.2

* Minor improvements.
* Term score formula revised.
* Clicking on a blue (unknown) word opens "New Term" and first dictionary automatically.
* Documentation (topic: Term scores) added.

### Fixed in 0.8.2

* Minor bug fixes.

## 0.8.1 (July 12 2011)

### Changed in 0.8.1

* Mac installation and upgrade (see documentation) completely changed.

## 0.8.0 (July 11 2011)

### Added in 0.8.0

* Possibility to change term (but only uppercase/lowercase changes allowed).
* Import terms with overwrite now possible.
* Number of saved words and "To Do" words are now displayed in "My Texts".
* Backup and Restore of the LWT database.

### Changed in 0.8.0

* "Learn/Edit Word/Expression" opens first dictionary automatically.
* Documentation updated and improved.  

## 0.7.0 (July 09 2011)

### Changed in 0.7.0

* Minor improvements, more documentation.
* Japanese example added.  

## 0.6.0 (July 08 2011)

### Changed in 0.6.0

* Improvements, more documentation.
* Settings now stored in DB (not in cookies anymore).  

### Fixed in 0.6.0

* Bugfixes.

## 0.5.0 (June 30 2011)

### Added in 0.5.0

* Text archiving.

### Changed in 0.5.0

* Improvements.

### Fixed in 0.5.0

* Bugfixes.

## 0.4.0 (June 29 2011)

### Added in 0.4.0

* Basic documentation completed
* Two new tests.

### Changed in 0.4.0

* Improvements.

### Fixed in 0.4.0

* Bugfixes.

## 0.3.0 (June 27 2011)

### Added in 0.3.0

* More documentation.

### Changed in 0.3.0

* Improvements.

### Fixed in 0.3.0

* Bugfixes.

## 0.2.0 (June 25 2011)

### Added in 0.2.0

* More documentation.

### Changed in 0.2.0

* Improvements.

### Fixed in 0.2.0

* Bugfixes.

## 0.1.0 (June 24 2011)

* Initial release.
