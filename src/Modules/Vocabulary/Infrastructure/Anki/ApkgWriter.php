<?php

declare(strict_types=1);

namespace Lwt\Modules\Vocabulary\Infrastructure\Anki;

use DateTimeImmutable;
use PDO;
use RuntimeException;
use ZipArchive;

/**
 * Builds a .apkg file (zip containing a schema-11 SQLite collection) from
 * LWT term data.
 *
 * Status mapping for `cards.queue`:
 *   - LWT 1..5:  queue=0 (new), due=position — unless the term has a schedule
 *   - LWT 98:    queue=-1 (suspended)  — "ignored"
 *   - LWT 99:    queue=-1 (suspended)  — "well-known"
 *
 * A term LWT has scheduled (issue #238) is written as a review card instead:
 * `type`/`queue` 2, `due` in days from the collection's creation day, `ivl`,
 * `reps` and `lapses` from its state, and the FSRS memory state in `data` as
 * the `{"s":stability,"d":difficulty,"dr":retention}` JSON Anki reads. Its
 * review history becomes `revlog` rows. A suspended term keeps queue -1 but
 * still carries its state, so unsuspending it in Anki resumes the schedule
 * rather than restarting it.
 *
 * Two deliberate simplifications, both recorded in
 * docs-src/developer/term-status-fsrs.md:
 *   - Relearning (LWT state 3) is exported as a review card. Anki puts
 *     relearning cards in the learning queue, where `due` is a unix timestamp
 *     rather than a day number; a transient state is not worth a second due
 *     encoding, and the card is due at the same moment either way.
 *   - `factor` is Anki's default 2500 rather than a measured ease. LWT has
 *     never computed an SM-2 ease factor, and 0 would make Anki's own SM-2
 *     collapse the interval if the user has FSRS switched off.
 */
final class ApkgWriter
{
    /** Anki's special "ignored" deck id; we use one deck per language instead. */
    private const COLLECTION_CREATION_TIMESTAMP = 1577836800; // 2020-01-01 UTC

    /** Anki's card type/queue for a card in review. */
    private const CARD_TYPE_REVIEW = 2;

    /** Anki's default ease factor, in permille. */
    private const DEFAULT_EASE_FACTOR = 2500;

    /** revlog.type for a review-stage answer. */
    private const REVLOG_TYPE_REVIEW = 1;

    /**
     * @param non-empty-list<ApkgNote> $notes
     */
    public function write(string $outputPath, ApkgDeck $deck, array $notes): void
    {
        AnkiSchema::assertSqliteAvailable();

        $colDb = tempnam(sys_get_temp_dir(), 'lwt_apkg_');
        if ($colDb === false) {
            throw new RuntimeException('Could not allocate temp file for collection');
        }
        // PDO needs to create a fresh sqlite, so unlink the empty file first.
        unlink($colDb);

        try {
            $this->buildCollection($colDb, $deck, $notes);
            $this->packageZip($outputPath, $colDb);
        } finally {
            if (is_file($colDb)) {
                unlink($colDb);
            }
        }
    }

    /**
     * @param non-empty-list<ApkgNote> $notes
     */
    private function buildCollection(string $dbPath, ApkgDeck $deck, array $notes): void
    {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode=DELETE');
        $pdo->beginTransaction();

        foreach (AnkiSchema::createStatements() as $stmt) {
            $pdo->exec($stmt);
        }

        $nowSeconds = time();
        $nowMillis = (int) (microtime(true) * 1000);

        $decks = [
            '1' => AnkiSchema::defaultDeck(),
            (string) $deck->id => AnkiSchema::buildDeck($deck->id, $deck->name),
        ];
        $models = [
            (string) AnkiSchema::NOTETYPE_ID => AnkiSchema::buildNotetype($deck->id, $nowSeconds),
        ];

        $pdo->exec('DELETE FROM col');
        $insertCol = $pdo->prepare(
            'INSERT INTO col (id, crt, mod, scm, ver, dty, usn, ls, conf, models, decks, dconf, tags) '
            . 'VALUES (1, :crt, :mod, :scm, :ver, 0, 0, 0, :conf, :models, :decks, :dconf, :tags)'
        );
        $insertCol->execute([
            ':crt' => self::COLLECTION_CREATION_TIMESTAMP,
            ':mod' => $nowMillis,
            ':scm' => $nowMillis,
            ':ver' => AnkiSchema::SCHEMA_VERSION,
            ':conf' => $this->jsonEncode(AnkiSchema::defaultConf()),
            ':models' => $this->jsonEncode($models),
            ':decks' => $this->jsonEncode($decks),
            ':dconf' => $this->jsonEncode(AnkiSchema::defaultDeckConfig()),
            ':tags' => $this->jsonEncode((object) []),
        ]);

        $insertNote = $pdo->prepare(
            'INSERT INTO notes (id, guid, mid, mod, usn, tags, flds, sfld, csum, flags, data) '
            . 'VALUES (:id, :guid, :mid, :mod, -1, :tags, :flds, :sfld, :csum, 0, \'\')'
        );
        $insertCard = $pdo->prepare(
            'INSERT INTO cards (id, nid, did, ord, mod, usn, type, queue, due, ivl, factor, '
            . 'reps, lapses, left, odue, odid, flags, data) '
            . 'VALUES (:id, :nid, :did, 0, :mod, -1, :type, :queue, :due, :ivl, :factor, '
            . ':reps, :lapses, 0, 0, 0, 0, :data)'
        );
        $insertRevlog = $pdo->prepare(
            'INSERT INTO revlog (id, cid, usn, ease, ivl, lastIvl, factor, time, type) '
            . 'VALUES (:id, :cid, -1, :ease, :ivl, :lastIvl, :factor, 0, :type)'
        );

        $position = 1;
        $nextId = $nowMillis;
        /** @var array<int, true> $usedRevlogIds */
        $usedRevlogIds = [];
        foreach ($notes as $note) {
            $noteId = $nextId++;
            $cardId = $nextId++;

            $fields = [
                $note->term,
                $note->translation,
                $note->romanization,
                $note->notes,
                $note->lwtIdField(),
            ];
            $flds = implode(AnkiSchema::FIELD_SEPARATOR, $fields);

            $insertNote->execute([
                ':id' => $noteId,
                ':guid' => $note->guid(),
                ':mid' => AnkiSchema::NOTETYPE_ID,
                ':mod' => $nowSeconds,
                ':tags' => $this->encodeTags($note->tags),
                ':flds' => $flds,
                ':sfld' => $note->term,
                ':csum' => AnkiSchema::fieldChecksum($note->term),
            ]);

            $schedule = $note->schedule;
            if ($schedule === null) {
                $insertCard->execute([
                    ':id' => $cardId,
                    ':nid' => $noteId,
                    ':did' => $deck->id,
                    ':mod' => $nowSeconds,
                    ':type' => 0,
                    ':queue' => $note->suspended ? -1 : 0,
                    ':due' => $position++,
                    ':ivl' => 0,
                    ':factor' => 0,
                    ':reps' => 0,
                    ':lapses' => 0,
                    ':data' => '',
                ]);
                continue;
            }

            $insertCard->execute([
                ':id' => $cardId,
                ':nid' => $noteId,
                ':did' => $deck->id,
                ':mod' => $nowSeconds,
                ':type' => self::CARD_TYPE_REVIEW,
                // Suspension is the user's own decision about the term and
                // outranks the schedule; the state below survives either way.
                ':queue' => $note->suspended ? -1 : self::CARD_TYPE_REVIEW,
                ':due' => $this->dueDayNumber($schedule->due),
                ':ivl' => max(1, $schedule->intervalDays),
                ':factor' => self::DEFAULT_EASE_FACTOR,
                ':reps' => $schedule->reps,
                ':lapses' => $schedule->lapses,
                ':data' => $this->memoryStateJson($schedule),
            ]);

            foreach ($schedule->reviews as $review) {
                $insertRevlog->execute([
                    ':id' => $this->uniqueRevlogId($review->reviewedAt, $usedRevlogIds),
                    ':cid' => $cardId,
                    ':ease' => $review->ease,
                    ':ivl' => $review->intervalDays,
                    ':lastIvl' => $review->lastIntervalDays,
                    ':factor' => self::DEFAULT_EASE_FACTOR,
                    ':type' => self::REVLOG_TYPE_REVIEW,
                ]);
            }
        }

        $pdo->commit();
        unset($pdo);
    }

    /**
     * A review card's `due`: whole days from the collection's creation day.
     *
     * Anki counts from the collection's creation *day* with a rollover hour;
     * we create collections at midnight UTC and ignore rollover, so a card can
     * land a day either side of where Anki would put it. Harmless for an
     * export — Anki reschedules on the next answer regardless.
     */
    private function dueDayNumber(DateTimeImmutable $due): int
    {
        $days = (int) floor(
            ($due->getTimestamp() - self::COLLECTION_CREATION_TIMESTAMP) / 86400
        );

        return max(0, $days);
    }

    /**
     * The FSRS memory state Anki keeps in `cards.data`.
     *
     * Anki's own card data is a JSON object of optional keys; stability,
     * difficulty and desired retention are the three that carry a schedule.
     */
    private function memoryStateJson(ApkgSchedule $schedule): string
    {
        return $this->jsonEncode([
            's' => round($schedule->stability, 4),
            'd' => round($schedule->difficulty, 4),
            'dr' => round($schedule->desiredRetention, 4),
        ]);
    }

    /**
     * A revlog primary key derived from when the review happened.
     *
     * Anki keys revlog rows by their millisecond timestamp. LWT records review
     * times to the second, so a term answered twice within one second would
     * collide; step forward until the id is free.
     *
     * @param array<int, true> $used Ids already issued, by reference
     */
    private function uniqueRevlogId(DateTimeImmutable $reviewedAt, array &$used): int
    {
        $id = $reviewedAt->getTimestamp() * 1000;
        while (isset($used[$id])) {
            $id++;
        }
        $used[$id] = true;

        return $id;
    }

    private function packageZip(string $outputPath, string $collectionDbPath): void
    {
        if (file_exists($outputPath)) {
            unlink($outputPath);
        }
        $zip = new ZipArchive();
        if ($zip->open($outputPath, ZipArchive::CREATE) !== true) {
            throw new RuntimeException("Could not create zip at {$outputPath}");
        }
        // Modern clients read collection.anki21; older ones see the same file
        // under the legacy name. Writing both keeps us compatible with both.
        $contents = file_get_contents($collectionDbPath);
        if ($contents === false) {
            throw new RuntimeException('Failed to read intermediate collection');
        }
        $zip->addFromString('collection.anki21', $contents);
        $zip->addFromString('collection.anki2', $contents);
        $zip->addFromString('media', '{}');
        $zip->close();
    }

    /**
     * Anki tag format: " tag1 tag2 " (leading + trailing space, single-space
     * separated, empty string when no tags).
     *
     * @param list<string> $tags
     */
    private function encodeTags(array $tags): string
    {
        $cleaned = [];
        foreach ($tags as $tag) {
            $tag = trim(str_replace([' ', "\t", "\n"], '_', $tag));
            if ($tag !== '') {
                $cleaned[] = $tag;
            }
        }
        if ($cleaned === []) {
            return '';
        }
        return ' ' . implode(' ', $cleaned) . ' ';
    }

    /**
     * @param array<array-key, mixed>|object $data
     */
    private function jsonEncode($data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Failed to encode JSON for Anki collection');
        }
        return $json;
    }
}
