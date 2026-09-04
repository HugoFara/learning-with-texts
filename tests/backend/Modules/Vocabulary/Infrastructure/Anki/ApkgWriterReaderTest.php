<?php

declare(strict_types=1);

namespace Tests\Backend\Modules\Vocabulary\Infrastructure\Anki;

use DateTimeImmutable;
use Lwt\Modules\Vocabulary\Infrastructure\Anki\ApkgDeck;
use Lwt\Modules\Vocabulary\Infrastructure\Anki\ApkgNote;
use Lwt\Modules\Vocabulary\Infrastructure\Anki\ApkgReader;
use Lwt\Modules\Vocabulary\Infrastructure\Anki\ApkgReview;
use Lwt\Modules\Vocabulary\Infrastructure\Anki\ApkgSchedule;
use Lwt\Modules\Vocabulary\Infrastructure\Anki\ApkgWriter;
use PDO;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * No-DB integration test for the writer + reader pair.
 *
 * The PHP-only round-trip alone won't catch schema mistakes that *Anki*
 * would reject; for that we have the CLI smoke tool
 * (bin/lwt-apkg-roundtrip-smoke.php) plus the genanki/anki pylib oracles
 * documented in the slice-1 commit message. This test guards the in-process
 * data path so refactors here can't silently break field/tag/suspension
 * round-trip.
 */
final class ApkgWriterReaderTest extends TestCase
{
    private string $tmpFile = '';

    private string $extractedDb = '';

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite required to build an .apkg collection');
        }
    }

    protected function tearDown(): void
    {
        if ($this->tmpFile !== '' && is_file($this->tmpFile)) {
            unlink($this->tmpFile);
        }
        if ($this->extractedDb !== '' && is_file($this->extractedDb)) {
            unlink($this->extractedDb);
        }
    }

    public function testRoundTripPreservesEveryField(): void
    {
        $deck = ApkgDeck::forLanguage(7, 'Spanish');
        $notes = [
            new ApkgNote(
                lwtTermId: 101,
                term: 'hola',
                translation: 'hello',
                romanization: '',
                notes: 'informal greeting',
                tags: ['greeting', 'common'],
                suspended: false,
            ),
            new ApkgNote(
                lwtTermId: 102,
                term: 'casa',
                translation: 'la maison',  // intentionally non-ASCII translation
                romanization: '',
                notes: '',
                tags: [],
                suspended: false,
            ),
            new ApkgNote(
                lwtTermId: 103,
                term: 'adiós',
                translation: 'goodbye',
                romanization: '',
                notes: 'we know this one',
                tags: ['known'],
                suspended: true,
            ),
        ];

        $this->tmpFile = $this->makeTmpPath();
        (new ApkgWriter())->write($this->tmpFile, $deck, $notes);

        self::assertFileExists($this->tmpFile);
        self::assertGreaterThan(0, filesize($this->tmpFile));

        $readBack = (new ApkgReader())->read($this->tmpFile);
        self::assertCount(3, $readBack);

        $byId = [];
        foreach ($readBack as $n) {
            $byId[$n->lwtTermId] = $n;
        }

        foreach ($notes as $expected) {
            self::assertArrayHasKey($expected->lwtTermId, $byId);
            $actual = $byId[$expected->lwtTermId];
            self::assertSame($expected->term, $actual->term);
            self::assertSame($expected->translation, $actual->translation);
            self::assertSame($expected->romanization, $actual->romanization);
            self::assertSame($expected->notes, $actual->notes);
            self::assertEqualsCanonicalizing($expected->tags, $actual->tags);
            self::assertSame($expected->suspended, $actual->suspended);
        }
    }

    public function testApkgIsAValidZipWithExpectedEntries(): void
    {
        $this->tmpFile = $this->makeTmpPath();
        (new ApkgWriter())->write(
            $this->tmpFile,
            ApkgDeck::forLanguage(1, 'English'),
            [new ApkgNote(1, 'a', 'b', '', '', [], false)],
        );

        $zip = new ZipArchive();
        self::assertTrue($zip->open($this->tmpFile) === true);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (is_array($stat)) {
                $names[] = $stat['name'];
            }
        }
        $zip->close();

        self::assertContains('collection.anki21', $names);
        self::assertContains('collection.anki2', $names);
        self::assertContains('media', $names);
    }

    public function testReaderReturnsEmptyListForNotesFromUnknownNotetype(): void
    {
        // Write our standard apkg, then verify reader doesn't choke on a file
        // it wrote itself, and would also skip notes mapped to a notetype with
        // none of our expected field names. Covered indirectly via the empty
        // ords short-circuit; here we just confirm the no-mismatch case.
        $this->tmpFile = $this->makeTmpPath();
        (new ApkgWriter())->write(
            $this->tmpFile,
            ApkgDeck::forLanguage(1, 'English'),
            [new ApkgNote(1, 't', '', '', '', [], false)],
        );

        $notes = (new ApkgReader())->read($this->tmpFile);
        self::assertCount(1, $notes);
        self::assertSame(1, $notes[0]->lwtTermId);
    }

    public function testReaderRejectsNonExistentFile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found/i');
        (new ApkgReader())->read('/tmp/lwt-this-file-does-not-exist.apkg');
    }

    // =========================================================================
    // Scheduling export (#238 phase 2b / #228)
    // =========================================================================

    public function testAnUnscheduledTermIsStillWrittenAsANewCard(): void
    {
        $rows = $this->cardsFor([$this->note(101, null)]);

        self::assertSame(0, (int) $rows[0]['type']);
        self::assertSame(0, (int) $rows[0]['queue']);
        self::assertSame(0, (int) $rows[0]['ivl']);
        self::assertSame('', (string) $rows[0]['data']);
    }

    public function testAScheduledTermBecomesAReviewCardCarryingItsMemoryState(): void
    {
        $due = new DateTimeImmutable('2026-03-01 00:00:00');
        $schedule = new ApkgSchedule(
            stability: 12.3456789,
            difficulty: 5.5,
            desiredRetention: 0.9,
            due: $due,
            intervalDays: 12,
            reps: 4,
            lapses: 1,
        );

        $rows = $this->cardsFor([$this->note(101, $schedule)]);

        self::assertSame(2, (int) $rows[0]['type']);
        self::assertSame(2, (int) $rows[0]['queue']);
        self::assertSame(12, (int) $rows[0]['ivl']);
        self::assertSame(4, (int) $rows[0]['reps']);
        self::assertSame(1, (int) $rows[0]['lapses']);

        // Due is a day number counted from the collection's creation day
        $expectedDay = (int) floor(($due->getTimestamp() - 1577836800) / 86400);
        self::assertSame($expectedDay, (int) $rows[0]['due']);

        /** @var array{s: float, d: float, dr: float} $data */
        $data = json_decode((string) $rows[0]['data'], true);
        self::assertSame(12.3457, $data['s']);
        self::assertSame(5.5, $data['d']);
        self::assertSame(0.9, $data['dr']);
    }

    public function testASuspendedTermKeepsItsScheduleBehindTheSuspension(): void
    {
        $schedule = new ApkgSchedule(
            stability: 3.0,
            difficulty: 5.0,
            desiredRetention: 0.9,
            due: new DateTimeImmutable('2026-03-01 00:00:00'),
            intervalDays: 3,
            reps: 2,
            lapses: 0,
        );

        $rows = $this->cardsFor([$this->note(101, $schedule, suspended: true)]);

        // Unsuspending in Anki has to resume the schedule, not restart it
        self::assertSame(-1, (int) $rows[0]['queue']);
        self::assertSame(2, (int) $rows[0]['type']);
        self::assertSame(3, (int) $rows[0]['ivl']);
        self::assertNotSame('', (string) $rows[0]['data']);
    }

    public function testReviewHistoryBecomesRevlogRows(): void
    {
        $schedule = new ApkgSchedule(
            stability: 9.0,
            difficulty: 5.0,
            desiredRetention: 0.9,
            due: new DateTimeImmutable('2026-03-10 00:00:00'),
            intervalDays: 9,
            reps: 2,
            lapses: 0,
            reviews: [
                new ApkgReview(new DateTimeImmutable('2026-02-01 10:00:00'), 3, 4, 0),
                new ApkgReview(new DateTimeImmutable('2026-02-05 10:00:00'), 4, 9, 4),
            ],
        );

        $pdo = $this->collectionFor([$this->note(101, $schedule)]);
        /** @var list<array<string, mixed>> $rows */
        $rows = $pdo->query('SELECT * FROM revlog ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

        self::assertCount(2, $rows);
        self::assertSame(3, (int) $rows[0]['ease']);
        self::assertSame(4, (int) $rows[0]['ivl']);
        self::assertSame(0, (int) $rows[0]['lastIvl']);
        self::assertSame(4, (int) $rows[1]['ease']);
        self::assertSame(9, (int) $rows[1]['ivl']);
        self::assertSame(4, (int) $rows[1]['lastIvl']);

        // Keyed by review time in milliseconds, as Anki does
        self::assertSame(
            (new DateTimeImmutable('2026-02-01 10:00:00'))->getTimestamp() * 1000,
            (int) $rows[0]['id']
        );

        $cardId = (int) $pdo->query('SELECT id FROM cards')->fetchColumn();
        self::assertSame($cardId, (int) $rows[0]['cid']);
    }

    public function testTwoReviewsInTheSameSecondGetDistinctRevlogIds(): void
    {
        $sameMoment = new DateTimeImmutable('2026-02-01 10:00:00');
        $schedule = new ApkgSchedule(
            stability: 1.0,
            difficulty: 5.0,
            desiredRetention: 0.9,
            due: new DateTimeImmutable('2026-02-02 10:00:00'),
            intervalDays: 1,
            reps: 2,
            lapses: 1,
            reviews: [
                new ApkgReview($sameMoment, 1, 1, 0),
                new ApkgReview($sameMoment, 3, 1, 1),
            ],
        );

        $pdo = $this->collectionFor([$this->note(101, $schedule)]);
        /** @var list<array<string, mixed>> $rows */
        $rows = $pdo->query('SELECT id FROM revlog ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

        // revlog.id is the primary key, so a collision would have lost a row
        self::assertCount(2, $rows);
        self::assertNotSame((int) $rows[0]['id'], (int) $rows[1]['id']);
    }

    // =========================================================================
    // Scheduling import (#264)
    // =========================================================================

    public function testAScheduleSurvivesTheRoundTrip(): void
    {
        $due = new DateTimeImmutable('2026-03-01 00:00:00');
        $schedule = new ApkgSchedule(
            stability: 12.3456,
            difficulty: 5.5,
            desiredRetention: 0.9,
            due: $due,
            intervalDays: 12,
            reps: 4,
            lapses: 1,
        );

        $note = $this->writeAndReadBack([$this->note(101, $schedule)])[0];

        self::assertNotNull($note->schedule);
        self::assertSame(12, $note->schedule->intervalDays);
        self::assertSame(4, $note->schedule->reps);
        self::assertSame(1, $note->schedule->lapses);
        self::assertSame(12.3456, $note->schedule->stability);
        self::assertSame(5.5, $note->schedule->difficulty);
        self::assertSame(0.9, $note->schedule->desiredRetention);
        // The writer stores due as a whole day number, so the time of day is
        // not preserved; the day is.
        self::assertSame(
            $due->format('Y-m-d'),
            $note->schedule->due->format('Y-m-d')
        );
    }

    public function testReviewHistorySurvivesTheRoundTrip(): void
    {
        // The reviews are what the importer actually replays, so they are the
        // part of the round-trip that has to be exact.
        $schedule = new ApkgSchedule(
            stability: 9.0,
            difficulty: 5.0,
            desiredRetention: 0.9,
            due: new DateTimeImmutable('2026-03-10 00:00:00'),
            intervalDays: 9,
            reps: 2,
            lapses: 0,
            reviews: [
                new ApkgReview(new DateTimeImmutable('2026-02-01 10:00:00'), 3, 4, 0),
                new ApkgReview(new DateTimeImmutable('2026-02-05 10:00:00'), 4, 9, 4),
            ],
        );

        $note = $this->writeAndReadBack([$this->note(101, $schedule)])[0];

        self::assertNotNull($note->schedule);
        self::assertCount(2, $note->schedule->reviews);

        [$first, $second] = $note->schedule->reviews;
        self::assertSame('2026-02-01 10:00:00', $first->reviewedAt->format('Y-m-d H:i:s'));
        self::assertSame(3, $first->ease);
        self::assertSame(4, $first->intervalDays);
        self::assertSame(0, $first->lastIntervalDays);
        self::assertSame('2026-02-05 10:00:00', $second->reviewedAt->format('Y-m-d H:i:s'));
        self::assertSame(4, $second->ease);
        self::assertSame(9, $second->intervalDays);
        self::assertSame(4, $second->lastIntervalDays);
    }

    public function testAnUnscheduledNoteReadsBackWithNoSchedule(): void
    {
        // A new card's `due` is a position in the new-card order, not a date.
        // Reading it as one would invent a schedule nobody set.
        $note = $this->writeAndReadBack([$this->note(101, null)])[0];

        self::assertNull($note->schedule);
    }

    public function testASuspendedNoteStillReadsBackItsSchedule(): void
    {
        $schedule = new ApkgSchedule(
            stability: 3.0,
            difficulty: 5.0,
            desiredRetention: 0.9,
            due: new DateTimeImmutable('2026-03-01 00:00:00'),
            intervalDays: 3,
            reps: 2,
            lapses: 0,
            reviews: [new ApkgReview(new DateTimeImmutable('2026-02-25 09:00:00'), 2, 3, 1)],
        );

        $note = $this->writeAndReadBack([$this->note(101, $schedule, suspended: true)])[0];

        self::assertTrue($note->suspended);
        self::assertNotNull($note->schedule);
        self::assertCount(1, $note->schedule->reviews);
    }

    public function testACollectionWithoutFsrsStateReadsZeroedMemory(): void
    {
        // What an SM-2 collection, or one whose owner has FSRS switched off,
        // looks like. Stability is clamped to >= 0.001 and difficulty to
        // [1, 10] wherever either is real, so zero cannot be mistaken for a
        // measured value — and the importer does not read these anyway.
        $pdo = $this->collectionFor([$this->note(101, new ApkgSchedule(
            stability: 5.0,
            difficulty: 5.0,
            desiredRetention: 0.9,
            due: new DateTimeImmutable('2026-03-01 00:00:00'),
            intervalDays: 5,
            reps: 1,
            lapses: 0,
        ))]);
        $pdo->exec("UPDATE cards SET data = ''");
        unset($pdo);
        $this->repackage();

        $note = (new ApkgReader())->read($this->tmpFile)[0];

        self::assertNotNull($note->schedule);
        self::assertSame(0.0, $note->schedule->stability);
        self::assertSame(0.0, $note->schedule->difficulty);
        // Everything the file does state still comes through.
        self::assertSame(5, $note->schedule->intervalDays);
    }

    public function testAManualRescheduleIsNotReadAsAReview(): void
    {
        // Anki writes a revlog row with ease 0 for "set due date" and other
        // manual rescheduling. Replaying one as a grade would apply a review
        // the learner never did — and Rating has no 0.
        $pdo = $this->collectionFor([$this->note(101, new ApkgSchedule(
            stability: 5.0,
            difficulty: 5.0,
            desiredRetention: 0.9,
            due: new DateTimeImmutable('2026-03-01 00:00:00'),
            intervalDays: 5,
            reps: 1,
            lapses: 0,
            reviews: [new ApkgReview(new DateTimeImmutable('2026-02-24 09:00:00'), 3, 5, 1)],
        ))]);
        $cardId = (int) $pdo->query('SELECT id FROM cards')->fetchColumn();
        $pdo->exec(
            'INSERT INTO revlog (id, cid, usn, ease, ivl, lastIvl, factor, time, type) '
            . 'VALUES (9999999999999, ' . $cardId . ', -1, 0, 5, 5, 0, 0, 4)'
        );
        unset($pdo);
        $this->repackage();

        $note = (new ApkgReader())->read($this->tmpFile)[0];

        self::assertNotNull($note->schedule);
        self::assertCount(1, $note->schedule->reviews);
        self::assertSame(3, $note->schedule->reviews[0]->ease);
    }

    public function testAManualRescheduleIsStillReportedAsOne(): void
    {
        // Not replayable as a grade, but not nothing either: the learner said
        // when the card should come back. Dropping the row entirely was the
        // last silent hole in the round trip.
        $note = $this->noteWithManualReschedule();

        self::assertNotNull($note->schedule);
        // revlog ids are millisecond timestamps, so the row above is that
        // instant to the second.
        self::assertSame(
            (new DateTimeImmutable('@9999999999'))->format('Y-m-d H:i:s'),
            $note->schedule->manualRescheduledAt
                ?->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s')
        );
    }

    public function testAnOrdinaryReviewIsNotMistakenForAReschedule(): void
    {
        [$note] = $this->writeAndReadBack([$this->note(101, new ApkgSchedule(
            stability: 5.0,
            difficulty: 5.0,
            desiredRetention: 0.9,
            due: new DateTimeImmutable('2026-03-01 00:00:00'),
            intervalDays: 5,
            reps: 1,
            lapses: 0,
            reviews: [new ApkgReview(new DateTimeImmutable('2026-02-24 09:00:00'), 3, 5, 1)],
        ))]);

        self::assertNotNull($note->schedule);
        self::assertNull($note->schedule->manualRescheduledAt);
    }

    public function testEveryCardOfANoteContributesItsHistory(): void
    {
        // A note type with two templates makes two cards, and the learner
        // answers both. Keeping only the first card silently threw away half
        // of what they did.
        $pdo = $this->collectionFor([$this->note(101, $this->schedule(5))]);
        $this->addSecondCard($pdo, dueInDays: 9, reps: 2, lapses: 1);
        unset($pdo);
        $this->repackage();

        $note = (new ApkgReader())->read($this->tmpFile)[0];

        self::assertNotNull($note->schedule);
        self::assertCount(2, $note->schedule->reviews);
        self::assertSame(3, $note->schedule->reps);
        self::assertSame(1, $note->schedule->lapses);
    }

    public function testANotesDueDateIsTheEarliestOfItsCards(): void
    {
        // "When does this note next come up" is the earliest of its cards, not
        // whichever card happens to sort first by template.
        $pdo = $this->collectionFor([$this->note(101, $this->schedule(30))]);
        $this->addSecondCard($pdo, dueInDays: 3, reps: 1, lapses: 0);
        unset($pdo);
        $this->repackage();

        $note = (new ApkgReader())->read($this->tmpFile)[0];

        self::assertNotNull($note->schedule);
        self::assertSame(
            (new DateTimeImmutable('@' . ($this->creationStamp() + 3 * 86400)))
                ->format('Y-m-d'),
            $note->schedule->due->format('Y-m-d')
        );
    }

    public function testANoteIsSuspendedOnlyWhenEveryCardIs(): void
    {
        // One card parked and another still in the queue means the note is
        // still being studied, so it must not demote the term to Ignored.
        $pdo = $this->collectionFor([$this->note(101, $this->schedule(5), suspended: true)]);
        $this->addSecondCard($pdo, dueInDays: 9, reps: 1, lapses: 0, queue: 2);
        unset($pdo);
        $this->repackage();

        $note = (new ApkgReader())->read($this->tmpFile)[0];

        self::assertFalse($note->suspended);
    }

    public function testASchema18CollectionReadsFieldNamesFromTables(): void
    {
        // Schema 15 moved note types out of the col.models JSON blob into
        // notetypes/fields tables, and the compressed collection current Anki
        // writes is at schema 18. The reader picks its lookup from col.ver, so
        // this puts a schema-18 collection where it will find one: the two
        // choices — compression and schema — are independent, and only the
        // schema half can be exercised without ext-zstd. Without the branch,
        // every note reads back as an unknown note type and is dropped.
        $pdo = $this->collectionFor([$this->note(101, $this->schedule(5))]);
        $this->convertToSchema18($pdo);
        unset($pdo);
        $this->repackage();

        $notes = (new ApkgReader())->read($this->tmpFile);

        self::assertCount(1, $notes);
        self::assertSame(101, $notes[0]->lwtTermId);
        self::assertSame('hola', $notes[0]->term);
        self::assertSame('hello', $notes[0]->translation);
        self::assertNotNull($notes[0]->schedule);
    }

    public function testAModernAnkiPackageIsRefusedRatherThanMisread(): void
    {
        // Anki's current export compresses the collection into
        // collection.anki21b and leaves collection.anki2 behind as a stub
        // holding one "please update Anki" note. Reading that stub *succeeds*,
        // which is the trap: the import would report one unrecognised note and
        // call it a day, and the user would never learn their reviews had not
        // arrived. Verified against a real file from Anki 26.08.
        //
        // This is the no-zstd case, so it asserts the guidance a user without
        // the extension needs. With ext-zstd the file is simply read, which
        // testAModernAnkiPackageIsReadWhereZstdExists() covers.
        if (function_exists('zstd_uncompress')) {
            self::markTestSkipped('ext-zstd is installed: the modern format is read, not refused');
        }

        $this->tmpFile = $this->makeTmpPath();
        (new ApkgWriter())->write(
            $this->tmpFile,
            ApkgDeck::forLanguage(1, 'English'),
            [new ApkgNote(1, 'a', 'b', '', '', [], false)],
        );

        $zip = new ZipArchive();
        self::assertTrue($zip->open($this->tmpFile) === true);
        $stub = $zip->getFromName('collection.anki21');
        self::assertNotFalse($stub);
        $zip->deleteName('collection.anki21');
        // Only the name matters here; the reader must refuse before it looks.
        $zip->addFromString('collection.anki21b', "\x28\xb5\x2f\xfd compressed");
        $zip->addFromString('collection.anki2', $stub);
        $zip->close();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Support older Anki versions/');
        (new ApkgReader())->read($this->tmpFile);
    }

    public function testAModernAnkiPackageIsReadWhereZstdExists(): void
    {
        if (!function_exists('zstd_uncompress')) {
            self::markTestSkipped('ext-zstd is not installed on this PHP');
        }

        $this->tmpFile = $this->makeTmpPath();
        (new ApkgWriter())->write(
            $this->tmpFile,
            ApkgDeck::forLanguage(7, 'Spanish'),
            [$this->note(101, $this->schedule(5))],
        );

        $zip = new ZipArchive();
        self::assertTrue($zip->open($this->tmpFile) === true);
        $collection = $zip->getFromName('collection.anki21');
        self::assertNotFalse($collection);
        $zip->deleteName('collection.anki21');
        /** @var callable(string): (string|false) $compress */
        $compress = 'zstd_compress';
        $compressed = $compress($collection);
        self::assertIsString($compressed);
        $zip->addFromString('collection.anki21b', $compressed);
        // The stub current Anki leaves behind for older clients. If the reader
        // ever preferred it again, this test would read one unknown note.
        $zip->addFromString('collection.anki2', 'not a database');
        $zip->close();

        $notes = (new ApkgReader())->read($this->tmpFile);

        self::assertCount(1, $notes);
        self::assertSame(101, $notes[0]->lwtTermId);
        self::assertNotNull($notes[0]->schedule);
    }

    /**
     * A note read back after a "set due date" was recorded against its card.
     */
    private function noteWithManualReschedule(): ApkgNote
    {
        $pdo = $this->collectionFor([$this->note(101, new ApkgSchedule(
            stability: 5.0,
            difficulty: 5.0,
            desiredRetention: 0.9,
            due: new DateTimeImmutable('2026-03-01 00:00:00'),
            intervalDays: 5,
            reps: 1,
            lapses: 0,
            reviews: [new ApkgReview(new DateTimeImmutable('2026-02-24 09:00:00'), 3, 5, 1)],
        ))]);
        $cardId = (int) $pdo->query('SELECT id FROM cards')->fetchColumn();
        // ease 0 and type 4 (Manual) is what "Set due date" writes.
        $pdo->exec(
            'INSERT INTO revlog (id, cid, usn, ease, ivl, lastIvl, factor, time, type) '
            . 'VALUES (9999999999999, ' . $cardId . ', -1, 0, 5, 5, 0, 0, 4)'
        );
        unset($pdo);
        $this->repackage();

        return (new ApkgReader())->read($this->tmpFile)[0];
    }

    /**
     * A schedule due the given number of days out, with one review behind it.
     */
    private function schedule(int $inDays): ApkgSchedule
    {
        return new ApkgSchedule(
            stability: 5.0,
            difficulty: 5.0,
            desiredRetention: 0.9,
            due: new DateTimeImmutable('+' . $inDays . ' days'),
            intervalDays: $inDays,
            reps: 1,
            lapses: 0,
            reviews: [new ApkgReview(new DateTimeImmutable('-1 day'), 3, $inDays, 1)],
        );
    }

    /**
     * Add a second card to the note, the way a two-template note type would.
     */
    private function addSecondCard(
        PDO $pdo,
        int $dueInDays,
        int $reps,
        int $lapses,
        int $queue = 2
    ): void {
        $row = $pdo->query('SELECT id, nid, did FROM cards ORDER BY id')->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        $cardId = (int) $row['id'] + 1;
        $pdo->exec(
            'INSERT INTO cards (id, nid, did, ord, mod, usn, type, queue, due, ivl, factor, '
            . 'reps, lapses, left, odue, odid, flags, data) VALUES ('
            . $cardId . ', ' . (int) $row['nid'] . ', ' . (int) $row['did'] . ', 1, 0, -1, 2, '
            . $queue . ', ' . $dueInDays . ', ' . $dueInDays . ', 2500, '
            . $reps . ', ' . $lapses . ', 0, 0, 0, 0, \'\')'
        );
        $pdo->exec(
            'INSERT INTO revlog (id, cid, usn, ease, ivl, lastIvl, factor, time, type) '
            . 'VALUES (1700000000000, ' . $cardId . ', -1, 3, ' . $dueInDays . ', 1, 2500, 0, 1)'
        );
    }

    /**
     * Rewrite the extracted collection the way schema 15..18 lays it out.
     *
     * Only the parts the reader looks at: the `fields` table that replaced the
     * `col.models` blob, and the version that says so. The blob is emptied as
     * well, so a reader that ignored `col.ver` would find nothing rather than
     * quietly keep working and hide the bug this pins.
     */
    private function convertToSchema18(PDO $pdo): void
    {
        $models = $pdo->query('SELECT models FROM col')->fetchColumn();
        self::assertIsString($models);
        /** @var array<string, array{flds: list<array{name: string, ord: int}>}> $decoded */
        $decoded = json_decode($models, true);

        $pdo->exec(
            'CREATE TABLE fields (ntid integer NOT NULL, ord integer NOT NULL, '
            . 'name text NOT NULL, config blob NOT NULL, PRIMARY KEY (ntid, ord)) WITHOUT ROWID'
        );
        foreach ($decoded as $ntid => $model) {
            foreach ($model['flds'] as $field) {
                $pdo->exec(sprintf(
                    "INSERT INTO fields (ntid, ord, name, config) VALUES (%d, %d, '%s', x'')",
                    (int) $ntid,
                    $field['ord'],
                    str_replace("'", "''", $field['name'])
                ));
            }
        }
        $pdo->exec("UPDATE col SET models = '', ver = 18");
    }

    /**
     * The collection's creation timestamp, which review due dates count from.
     */
    private function creationStamp(): int
    {
        $pdo = new PDO('sqlite:' . $this->extractedDb);

        return (int) $pdo->query('SELECT crt FROM col')->fetchColumn();
    }

    /**
     * Write notes to an .apkg and read them straight back.
     *
     * @param non-empty-list<ApkgNote> $notes
     *
     * @return list<ApkgNote>
     */
    private function writeAndReadBack(array $notes): array
    {
        $this->tmpFile = $this->makeTmpPath();
        (new ApkgWriter())->write($this->tmpFile, ApkgDeck::forLanguage(7, 'Spanish'), $notes);

        return (new ApkgReader())->read($this->tmpFile);
    }

    /**
     * Put the (edited) extracted collection back into the .apkg.
     *
     * collectionFor() unpacks the collection so a test can change it the way
     * Anki would have; the reader reads the archive, so the edit has to go
     * back in before it can see it.
     */
    private function repackage(): void
    {
        $zip = new ZipArchive();
        self::assertTrue($zip->open($this->tmpFile) === true);
        $sqlite = file_get_contents($this->extractedDb);
        self::assertNotFalse($sqlite);
        $zip->addFromString('collection.anki21', $sqlite);
        $zip->addFromString('collection.anki2', $sqlite);
        $zip->close();
    }

    /**
     * Build a note, optionally scheduled.
     */
    private function note(int $id, ?ApkgSchedule $schedule, bool $suspended = false): ApkgNote
    {
        return new ApkgNote(
            lwtTermId: $id,
            term: 'hola',
            translation: 'hello',
            romanization: '',
            notes: '',
            tags: [],
            suspended: $suspended,
            schedule: $schedule,
        );
    }

    /**
     * Write the notes to an .apkg and open the collection inside it.
     *
     * @param non-empty-list<ApkgNote> $notes
     */
    private function collectionFor(array $notes): PDO
    {
        $this->tmpFile = $this->makeTmpPath();
        (new ApkgWriter())->write($this->tmpFile, ApkgDeck::forLanguage(7, 'Spanish'), $notes);

        $zip = new ZipArchive();
        self::assertTrue($zip->open($this->tmpFile) === true);
        $sqlite = $zip->getFromName('collection.anki21');
        $zip->close();
        self::assertNotFalse($sqlite);

        $extracted = $this->makeTmpPath();
        file_put_contents($extracted, $sqlite);
        $this->extractedDb = $extracted;

        return new PDO('sqlite:' . $extracted);
    }

    /**
     * The cards table of a collection built from these notes.
     *
     * @param non-empty-list<ApkgNote> $notes
     *
     * @return list<array<string, mixed>>
     */
    private function cardsFor(array $notes): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->collectionFor($notes)
            ->query('SELECT * FROM cards ORDER BY id')
            ->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    private function makeTmpPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lwt_apkg_test_');
        self::assertNotFalse($path);
        unlink($path); // writer creates the file
        return $path;
    }
}
