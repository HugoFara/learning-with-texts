<?php

declare(strict_types=1);

namespace Lwt\Modules\Vocabulary\Infrastructure\Anki;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use ZipArchive;

/**
 * Reads an .apkg file and yields ApkgNote objects.
 *
 * Resolves field positions by name from the note type definition rather than
 * hard-coded indexes, so .apkg files modified in Anki (where the user might
 * reorder fields) still parse correctly. Unknown notes (no LWT id field, or a
 * guid not in our prefix) are returned without an `lwtTermId`-assertable id --
 * the caller decides what to do with them.
 *
 * Scheduling comes back too (#264): a note whose card has left the new queue
 * carries an {@see ApkgSchedule} holding the card's due date, interval, reps,
 * lapses and its `revlog` history. That is the same object {@see ApkgWriter}
 * consumes, so what the writer puts into a file is what the reader takes out.
 *
 * One asymmetry is deliberate. `stability` and `difficulty` are 0.0 when the
 * collection states no FSRS memory state -- an SM-2 collection, or one whose
 * owner has FSRS switched off. FSRS clamps stability to >= 0.001 and difficulty
 * to [1, 10], so zero is unreachable as a real value and cannot be mistaken for
 * one. {@see \Lwt\Modules\Vocabulary\Application\Services\Anki\ApkgImportService}
 * never reads those fields anyway: it replays the review history through LWT's
 * own scheduler rather than trusting a number computed elsewhere.
 *
 * **Two collection layouts.** An .apkg holds its collection under one of three
 * names, and the name says both how it is compressed and which schema it uses
 * (upstream `rslib/src/import_export/package/meta.rs`): `collection.anki2` and
 * `collection.anki21` are plain SQLite at schema 11, `collection.anki21b` is a
 * zstd stream wrapping SQLite at schema 18. Schema 15 moved note types out of
 * the `col.models` JSON blob into real `notetypes`/`fields` tables, so the
 * field-name lookup has to follow `col.ver` rather than assume either. Both are
 * read here; what changes between them is only where those two things live.
 *
 * **What an .apkg cannot say.** It carries no record of deletions. Anki's
 * exporter builds a *new minimal collection* and inserts only the notes the
 * search gathered (`rslib/src/import_export/package/apkg/export.rs`), so the
 * `graves` table that records deleted note ids inside a live collection is
 * never written to the file. A term the user deleted in Anki is therefore
 * indistinguishable here from one that was simply not exported, and LWT does
 * not guess between them: imports never delete terms.
 */
final class ApkgReader
{
    /**
     * The zstd-compressed, schema-18 collection current Anki writes by default.
     */
    private const MODERN_COLLECTION = 'collection.anki21b';

    /**
     * Plain-SQLite collection names, newest layout first.
     */
    private const LEGACY_COLLECTIONS = ['collection.anki21', 'collection.anki2'];

    /**
     * First schema version holding note types in tables instead of col.models.
     */
    private const SCHEMA_WITH_NOTETYPE_TABLES = 15;

    /**
     * `revlog.type` values that are not a grade the learner gave.
     *
     * 4 is Manual and 5 is Rescheduled (upstream `rslib/src/revlog/mod.rs`);
     * both come from "Set due date" or "Forget" rather than from answering a
     * card, and both carry `ease` 0.
     */
    private const REVLOG_MANUAL_KINDS = [4, 5];

    /**
     * @return list<ApkgNote>
     */
    public function read(string $apkgPath): array
    {
        AnkiSchema::assertSqliteAvailable();

        if (!is_file($apkgPath)) {
            throw new RuntimeException("APKG file not found: {$apkgPath}");
        }

        $zip = new ZipArchive();
        if ($zip->open($apkgPath) !== true) {
            throw new RuntimeException("Could not open APKG: {$apkgPath}");
        }

        try {
            $contents = $this->extractCollection($zip);
        } finally {
            $zip->close();
        }

        $tmpDb = tempnam(sys_get_temp_dir(), 'lwt_apkg_read_');
        if ($tmpDb === false) {
            throw new RuntimeException('Could not allocate temp file');
        }
        file_put_contents($tmpDb, $contents);

        try {
            return $this->readCollection($tmpDb);
        } finally {
            unlink($tmpDb);
        }
    }

    /**
     * The collection database bytes, whichever layout the package uses.
     *
     * The modern name is tried first on purpose. A package written by current
     * Anki holds *both*: the real collection in `collection.anki21b`, and a
     * one-note stub in `collection.anki2` reading "Please update to the latest
     * Anki version, then import the .colpkg/.apkg file again." Preferring the
     * legacy name would read the stub, and the import would report one
     * unrecognised note inside a success message -- which is exactly what it
     * did before this looked for the modern name at all.
     */
    private function extractCollection(ZipArchive $zip): string
    {
        if ($zip->locateName(self::MODERN_COLLECTION) !== false) {
            return $this->decompress($this->entry($zip, self::MODERN_COLLECTION));
        }

        foreach (self::LEGACY_COLLECTIONS as $candidate) {
            if ($zip->locateName($candidate) !== false) {
                return $this->entry($zip, $candidate);
            }
        }

        throw new RuntimeException(
            'No collection database found in this .apkg (looked for '
            . self::MODERN_COLLECTION . ', ' . implode(', ', self::LEGACY_COLLECTIONS) . ').'
        );
    }

    private function entry(ZipArchive $zip, string $name): string
    {
        $contents = $zip->getFromName($name);
        if ($contents === false) {
            throw new RuntimeException("Failed to extract {$name} from APKG");
        }

        return $contents;
    }

    /**
     * Undo the zstd framing around a modern collection.
     *
     * PHP has no bundled zstd, so this needs ext-zstd. Without it the honest
     * answer is to say so and name the export setting that avoids the format
     * altogether -- the wording is Anki's own, from its export dialog. Shelling
     * out to the `zstd` binary would be the obvious alternative and is not
     * taken: this runs on an uploaded file, and no upload path should reach a
     * shell.
     */
    private function decompress(string $compressed): string
    {
        if (!function_exists('zstd_uncompress')) {
            throw new RuntimeException(
                'This .apkg uses Anki\'s newer compressed collection format, which needs the '
                . 'PHP zstd extension -- not installed on this server. Either install it '
                . '(extension=zstd), or export from Anki again with "Support older Anki '
                . 'versions (slower/larger files)" switched on. Either way keep "Include '
                . 'scheduling information" switched on, so your reviews come back with it.'
            );
        }

        /** @var callable(string): (string|false) $uncompress */
        $uncompress = 'zstd_uncompress';
        $plain = $uncompress($compressed);
        if (!is_string($plain) || $plain === '') {
            throw new RuntimeException(
                'The compressed collection inside this .apkg could not be decompressed. '
                . 'The file may be truncated or corrupt; try exporting it from Anki again.'
            );
        }

        return $plain;
    }

    /**
     * @return list<ApkgNote>
     */
    private function readCollection(string $dbPath): array
    {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $colStmt = $pdo->query('SELECT crt, ver, models FROM col');
        if ($colStmt === false) {
            throw new RuntimeException('Could not read col');
        }
        $colRow = $colStmt->fetch();
        if (!is_array($colRow)) {
            throw new RuntimeException('Missing col row');
        }
        // A review card's `due` counts days from the collection's own creation
        // day, so the file has to say which day that is. Assuming the constant
        // ApkgWriter creates collections with would misdate every card in a
        // file that has been through Anki, which reissues `crt` on import.
        $creationTimestamp = isset($colRow['crt']) ? (int) $colRow['crt'] : 0;
        $schemaVersion = isset($colRow['ver']) ? (int) $colRow['ver'] : AnkiSchema::SCHEMA_VERSION;

        $fieldOrdsByMid = $schemaVersion >= self::SCHEMA_WITH_NOTETYPE_TABLES
            ? $this->fieldOrdsFromTables($pdo)
            : $this->fieldOrdsFromModels($colRow['models'] ?? null);

        $cardsByNote = $this->loadCards($pdo);
        $reviewsByCard = $this->loadReviews($pdo);
        $manualByCard = $this->loadManualReschedules($pdo);

        $noteStmt = $pdo->query('SELECT id, guid, mid, tags, flds FROM notes');
        if ($noteStmt === false) {
            throw new RuntimeException('Could not read notes');
        }

        /** @var list<ApkgNote> $out */
        $out = [];
        foreach ($noteStmt as $row) {
            if (!is_array($row)) {
                continue;
            }
            $mid = isset($row['mid']) ? (int) $row['mid'] : 0;
            $ords = $fieldOrdsByMid[$mid] ?? [];
            if ($ords === []) {
                continue;
            }
            $fields = explode(AnkiSchema::FIELD_SEPARATOR, isset($row['flds']) ? (string) $row['flds'] : '');

            $get = static function (string $name) use ($fields, $ords): string {
                $ord = $ords[$name] ?? null;
                return $ord !== null && isset($fields[$ord]) ? $fields[$ord] : '';
            };

            $guid = isset($row['guid']) ? (string) $row['guid'] : '';
            $lwtIdFromGuid = ApkgNote::lwtIdFromGuid($guid);
            $lwtIdField = $get(AnkiSchema::FIELD_LWT_ID);
            $lwtId = ctype_digit($lwtIdField) ? (int) $lwtIdField : ($lwtIdFromGuid ?? 0);

            $noteId = isset($row['id']) ? (int) $row['id'] : 0;
            $cards = $cardsByNote[$noteId] ?? [];

            $out[] = new ApkgNote(
                lwtTermId: $lwtId,
                term: $get(AnkiSchema::FIELD_TERM),
                translation: $get(AnkiSchema::FIELD_TRANSLATION),
                romanization: $get(AnkiSchema::FIELD_ROMANIZATION),
                notes: $get(AnkiSchema::FIELD_NOTES),
                tags: $this->decodeTags(isset($row['tags']) ? (string) $row['tags'] : ''),
                suspended: $this->allSuspended($cards),
                schedule: $this->buildSchedule($cards, $reviewsByCard, $manualByCard, $creationTimestamp),
            );
        }
        return $out;
    }

    /**
     * Field ordinals per note type, from the schema-11 `col.models` JSON blob.
     *
     * @param mixed $modelsJson The `col.models` column
     *
     * @return array<int, array<string, int>>
     */
    private function fieldOrdsFromModels(mixed $modelsJson): array
    {
        if (!is_string($modelsJson)) {
            throw new RuntimeException('Missing col.models');
        }
        /** @var mixed $models */
        $models = json_decode($modelsJson, true);
        if (!is_array($models)) {
            throw new RuntimeException('Could not decode col.models');
        }

        $out = [];
        foreach ($models as $mid => $model) {
            if (!is_array($model) || !isset($model['flds']) || !is_array($model['flds'])) {
                continue;
            }
            $ords = [];
            /** @var mixed $fld */
            foreach ($model['flds'] as $fld) {
                if (!is_array($fld) || !isset($fld['name'], $fld['ord'])) {
                    continue;
                }
                $ords[(string) $fld['name']] = (int) $fld['ord'];
            }
            $out[(int) $mid] = $ords;
        }
        return $out;
    }

    /**
     * Field ordinals per note type, from the schema-15+ `fields` table.
     *
     * Only the name and ordinal are wanted, and both are plain columns. The
     * rest of a field's definition sits in a protobuf `config` blob, which is
     * why nothing here touches it.
     *
     * @return array<int, array<string, int>>
     */
    private function fieldOrdsFromTables(PDO $pdo): array
    {
        $stmt = $pdo->query('SELECT ntid, ord, name FROM fields');
        if ($stmt === false) {
            throw new RuntimeException('Could not read fields');
        }

        $out = [];
        foreach ($stmt as $row) {
            if (!is_array($row) || !isset($row['ntid'], $row['ord'], $row['name'])) {
                continue;
            }
            $out[(int) $row['ntid']][(string) $row['name']] = (int) $row['ord'];
        }

        return $out;
    }

    /**
     * Every card of every note, ordered by template.
     *
     * A note type can have several templates and so several cards; LWT's has
     * one, but a collection edited in Anki need not. All of them are returned
     * because all of them carry history: keeping only the first silently
     * discarded whatever the learner did on a note's second card.
     *
     * @return array<int, list<array{
     *     id: int, ord: int, queue: int, type: int, due: int,
     *     ivl: int, reps: int, lapses: int, data: string
     * }>> Keyed by note id
     */
    private function loadCards(PDO $pdo): array
    {
        $stmt = $pdo->query(
            'SELECT id, nid, ord, queue, type, due, ivl, reps, lapses, data '
            . 'FROM cards ORDER BY nid, ord'
        );
        if ($stmt === false) {
            throw new RuntimeException('Could not read cards');
        }

        $out = [];
        foreach ($stmt as $row) {
            if (!is_array($row)) {
                continue;
            }
            $noteId = isset($row['nid']) ? (int) $row['nid'] : 0;
            $out[$noteId][] = [
                'id' => isset($row['id']) ? (int) $row['id'] : 0,
                'ord' => isset($row['ord']) ? (int) $row['ord'] : 0,
                'queue' => isset($row['queue']) ? (int) $row['queue'] : 0,
                'type' => isset($row['type']) ? (int) $row['type'] : 0,
                'due' => isset($row['due']) ? (int) $row['due'] : 0,
                'ivl' => isset($row['ivl']) ? (int) $row['ivl'] : 0,
                'reps' => isset($row['reps']) ? (int) $row['reps'] : 0,
                'lapses' => isset($row['lapses']) ? (int) $row['lapses'] : 0,
                'data' => isset($row['data']) ? (string) $row['data'] : '',
            ];
        }

        return $out;
    }

    /**
     * Review history by card, oldest first.
     *
     * `ease` outside 1..4 is not a grade: Anki writes `revlog` rows with ease 0
     * for a manual reschedule or a "set due date", which never happened to the
     * learner and must not be replayed as if it had. Those rows are read
     * separately by {@see loadManualReschedules()}.
     *
     * @return array<int, list<ApkgReview>> Keyed by card id
     */
    private function loadReviews(PDO $pdo): array
    {
        $stmt = $pdo->query('SELECT id, cid, ease, ivl, lastIvl FROM revlog ORDER BY cid, id');
        if ($stmt === false) {
            throw new RuntimeException('Could not read revlog');
        }

        $out = [];
        foreach ($stmt as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ease = isset($row['ease']) ? (int) $row['ease'] : 0;
            if ($ease < 1 || $ease > 4) {
                continue;
            }
            $cardId = isset($row['cid']) ? (int) $row['cid'] : 0;
            $out[$cardId][] = new ApkgReview(
                reviewedAt: $this->fromMilliseconds(isset($row['id']) ? (int) $row['id'] : 0),
                ease: $ease,
                // Anki stores an interval in days when positive and in negative
                // seconds when the card is still in a sub-day learning step.
                intervalDays: $this->intervalDays(isset($row['ivl']) ? (int) $row['ivl'] : 0),
                lastIntervalDays: $this->intervalDays(isset($row['lastIvl']) ? (int) $row['lastIvl'] : 0),
            );
        }

        return $out;
    }

    /**
     * When each card was last rescheduled by hand, if ever.
     *
     * "Set due date" and "Forget" are decisions about *when* a card should come
     * back, not answers to it, so they cannot be replayed as grades -- there is
     * no grade to replay. They are still the learner speaking, though, and
     * dropping them was the last silent hole in the round trip: a card pushed
     * six months out in Anki came back due tomorrow.
     *
     * @return array<int, DateTimeImmutable> Keyed by card id, newest kept
     */
    private function loadManualReschedules(PDO $pdo): array
    {
        $stmt = $pdo->query('SELECT id, cid, ease, type FROM revlog ORDER BY cid, id');
        if ($stmt === false) {
            throw new RuntimeException('Could not read revlog');
        }

        $out = [];
        foreach ($stmt as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ease = isset($row['ease']) ? (int) $row['ease'] : 0;
            $kind = isset($row['type']) ? (int) $row['type'] : -1;
            if ($ease !== 0 || !in_array($kind, self::REVLOG_MANUAL_KINDS, true)) {
                continue;
            }
            $cardId = isset($row['cid']) ? (int) $row['cid'] : 0;
            // Ordered by id, so the last row seen for a card is its newest.
            $out[$cardId] = $this->fromMilliseconds(isset($row['id']) ? (int) $row['id'] : 0);
        }

        return $out;
    }

    /**
     * Whether the note is suspended, which needs every card to be.
     *
     * A note with one card suspended and another still in the queue is still
     * being studied, so it must not demote the LWT term to Ignored. A note
     * with no cards at all is not suspended either -- there is nothing to
     * suspend.
     *
     * @param list<array{
     *     id: int, ord: int, queue: int, type: int, due: int,
     *     ivl: int, reps: int, lapses: int, data: string
     * }> $cards
     */
    private function allSuspended(array $cards): bool
    {
        if ($cards === []) {
            return false;
        }

        foreach ($cards as $card) {
            if ($card['queue'] !== -1) {
                return false;
            }
        }

        return true;
    }

    /**
     * The scheduling state a note's cards state, or null when they have none.
     *
     * A card still in the new queue has never been answered: its `due` is a
     * position in the new-card order, not a date, so it contributes no
     * schedule. Everything else does, suspended cards included -- a suspended
     * card keeps the state it had, which is what lets unsuspending it resume
     * rather than restart. If no card has left the new queue there is nothing
     * to report and this returns null.
     *
     * Across several cards the note's due date is the *earliest* of them --
     * when the note next comes up -- while reps, lapses and the review history
     * are the totals over all of them, since all of them are the same note
     * being learnt. Memory state comes from whichever card set the due date,
     * because stability and difficulty belong to a card and averaging two of
     * them would describe neither.
     *
     * @param list<array{
     *     id: int, ord: int, queue: int, type: int, due: int,
     *     ivl: int, reps: int, lapses: int, data: string
     * }>                                $cards        The note's cards
     * @param array<int, list<ApkgReview>>   $reviewsByCard Grades, keyed by card id
     * @param array<int, DateTimeImmutable>  $manualByCard  Manual reschedules by card id
     * @param int                            $crt           Collection creation timestamp
     */
    private function buildSchedule(
        array $cards,
        array $reviewsByCard,
        array $manualByCard,
        int $crt
    ): ?ApkgSchedule {
        /** @var list<ApkgReview> $reviews */
        $reviews = [];
        $reps = 0;
        $lapses = 0;
        $due = null;
        $primary = null;
        $manual = null;

        foreach ($cards as $card) {
            $cardDue = $this->cardDue($card['type'], $card['due'], $crt);
            if ($cardDue === null) {
                continue;
            }

            foreach ($reviewsByCard[$card['id']] ?? [] as $review) {
                $reviews[] = $review;
            }
            $cardManual = $manualByCard[$card['id']] ?? null;
            if ($cardManual !== null && ($manual === null || $cardManual > $manual)) {
                $manual = $cardManual;
            }

            $reps += max(0, $card['reps']);
            $lapses += max(0, $card['lapses']);

            if ($due === null || $cardDue < $due) {
                $due = $cardDue;
                $primary = $card;
            }
        }

        if ($due === null || $primary === null) {
            return null;
        }

        usort($reviews, static fn(ApkgReview $a, ApkgReview $b) => $a->reviewedAt <=> $b->reviewedAt);
        $memory = $this->memoryState($primary['data']);

        return new ApkgSchedule(
            stability: $memory['s'],
            difficulty: $memory['d'],
            desiredRetention: $memory['dr'],
            due: $due,
            intervalDays: $this->intervalDays($primary['ivl']),
            reps: $reps,
            lapses: $lapses,
            reviews: $reviews,
            manualRescheduledAt: $manual,
        );
    }

    /**
     * When a card next falls due, or null for one that has never been answered.
     *
     * `due` is encoded three different ways depending on the card's type: a
     * queue position for a new card, whole days from the collection's creation
     * for a review card, and a unix timestamp for one mid-way through a
     * learning or relearning step.
     */
    private function cardDue(int $type, int $due, int $crt): ?DateTimeImmutable
    {
        if ($type === 0) {
            return null;
        }

        $timestamp = $type === 2 ? $crt + $due * 86400 : $due;

        return $this->fromSeconds($timestamp);
    }

    /**
     * The FSRS memory state a card states, zeroed when it states none.
     *
     * @return array{s: float, d: float, dr: float}
     */
    private function memoryState(string $data): array
    {
        $empty = ['s' => 0.0, 'd' => 0.0, 'dr' => 0.0];
        if (trim($data) === '') {
            return $empty;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($data, true);
        if (!is_array($decoded)) {
            return $empty;
        }

        $number = static function (mixed $value): float {
            return is_int($value) || is_float($value) ? (float) $value : 0.0;
        };

        return [
            's' => $number($decoded['s'] ?? null),
            'd' => $number($decoded['d'] ?? null),
            'dr' => $number($decoded['dr'] ?? null),
        ];
    }

    /**
     * An Anki interval in days.
     *
     * Anki encodes a sub-day learning interval as a negative number of seconds.
     * Those round to 0 days, which is what they are.
     */
    private function intervalDays(int $interval): int
    {
        return $interval >= 0 ? $interval : (int) floor(-$interval / 86400);
    }

    private function fromSeconds(int $timestamp): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new DateTimeZone(date_default_timezone_get()));
    }

    private function fromMilliseconds(int $millis): DateTimeImmutable
    {
        return $this->fromSeconds((int) floor($millis / 1000));
    }

    /**
     * @return list<string>
     */
    private function decodeTags(string $raw): array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return [];
        }
        $parts = preg_split('/\s+/', $trimmed);
        return $parts === false ? [] : $parts;
    }
}
