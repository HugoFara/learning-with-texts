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
 * guid not in our prefix) are returned without an `lwtTermId`-assertable id —
 * the caller decides what to do with them.
 *
 * Scheduling comes back too (#264): a note whose card has left the new queue
 * carries an {@see ApkgSchedule} holding the card's due date, interval, reps,
 * lapses and its `revlog` history. That is the same object {@see ApkgWriter}
 * consumes, so what the writer puts into a file is what the reader takes out.
 *
 * One asymmetry is deliberate. `stability` and `difficulty` are 0.0 when the
 * collection states no FSRS memory state — an SM-2 collection, or one whose
 * owner has FSRS switched off. FSRS clamps stability to >= 0.001 and difficulty
 * to [1, 10], so zero is unreachable as a real value and cannot be mistaken for
 * one. {@see \Lwt\Modules\Vocabulary\Application\Services\Anki\ApkgImportService}
 * never reads those fields anyway: it replays the review history through LWT's
 * own scheduler rather than trusting a number computed elsewhere.
 */
final class ApkgReader
{
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

        $collectionName = null;
        foreach (['collection.anki21', 'collection.anki2'] as $candidate) {
            if ($zip->locateName($candidate) !== false) {
                $collectionName = $candidate;
                break;
            }
        }
        if ($collectionName === null) {
            $zip->close();
            throw new RuntimeException('No collection.anki21 or collection.anki2 found in APKG');
        }

        $contents = $zip->getFromName($collectionName);
        $zip->close();
        if ($contents === false) {
            throw new RuntimeException("Failed to extract {$collectionName} from APKG");
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
     * @return list<ApkgNote>
     */
    private function readCollection(string $dbPath): array
    {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $colStmt = $pdo->query('SELECT crt, models FROM col');
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
        $modelsJson = $colRow['models'] ?? null;
        if (!is_string($modelsJson)) {
            throw new RuntimeException('Missing col.models');
        }
        /** @var mixed $modelsDecoded */
        $modelsDecoded = json_decode($modelsJson, true);
        if (!is_array($modelsDecoded)) {
            throw new RuntimeException('Could not decode col.models');
        }

        $fieldOrdsByMid = $this->buildFieldOrdMap($modelsDecoded);
        $cardsByNote = $this->loadCards($pdo);
        $reviewsByCard = $this->loadReviews($pdo);

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
            $card = $cardsByNote[$noteId] ?? null;

            $out[] = new ApkgNote(
                lwtTermId: $lwtId,
                term: $get(AnkiSchema::FIELD_TERM),
                translation: $get(AnkiSchema::FIELD_TRANSLATION),
                romanization: $get(AnkiSchema::FIELD_ROMANIZATION),
                notes: $get(AnkiSchema::FIELD_NOTES),
                tags: $this->decodeTags(isset($row['tags']) ? (string) $row['tags'] : ''),
                suspended: $card !== null && $card['queue'] === -1,
                schedule: $card === null
                    ? null
                    : $this->buildSchedule(
                        $card,
                        $reviewsByCard[$card['id']] ?? [],
                        $creationTimestamp
                    ),
            );
        }
        return $out;
    }

    /**
     * @param array<array-key, mixed> $models
     * @return array<int, array<string, int>>
     */
    private function buildFieldOrdMap(array $models): array
    {
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
     * One card per note, with everything the schedule is built from.
     *
     * A note type can have several templates and so several cards; LWT's has
     * one, but a collection edited in Anki need not. Ordering by `ord` and
     * keeping the first makes the choice deterministic rather than leaving it
     * to SQLite's row order.
     *
     * @return array<int, array{
     *     id: int, queue: int, type: int, due: int,
     *     ivl: int, reps: int, lapses: int, data: string
     * }> Keyed by note id
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
            if (isset($out[$noteId])) {
                continue;
            }
            $out[$noteId] = [
                'id' => isset($row['id']) ? (int) $row['id'] : 0,
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
     * learner and must not be replayed as if it had.
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
     * The scheduling state a card states, or null when it has none.
     *
     * A card still in the new queue has never been answered: its `due` is a
     * position in the new-card order, not a date, so there is no schedule to
     * report. Everything else carries one, suspended cards included — a
     * suspended card keeps the state it had, which is what lets unsuspending it
     * resume rather than restart.
     *
     * @param array{
     *     id: int, queue: int, type: int, due: int,
     *     ivl: int, reps: int, lapses: int, data: string
     * }               $card    The note's card
     * @param list<ApkgReview> $reviews Its review history, oldest first
     * @param int              $crt     The collection's creation timestamp
     */
    private function buildSchedule(array $card, array $reviews, int $crt): ?ApkgSchedule
    {
        $due = $this->cardDue($card['type'], $card['due'], $crt);
        if ($due === null) {
            return null;
        }

        $memory = $this->memoryState($card['data']);

        return new ApkgSchedule(
            stability: $memory['s'],
            difficulty: $memory['d'],
            desiredRetention: $memory['dr'],
            due: $due,
            intervalDays: $this->intervalDays($card['ivl']),
            reps: max(0, $card['reps']),
            lapses: max(0, $card['lapses']),
            reviews: $reviews,
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
