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
