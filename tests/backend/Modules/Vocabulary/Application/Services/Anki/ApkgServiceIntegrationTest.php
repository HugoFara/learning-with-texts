<?php

declare(strict_types=1);

namespace Tests\Backend\Modules\Vocabulary\Application\Services\Anki;

use DateTimeImmutable;
use Lwt\Modules\Review\Application\UseCases\RecordScheduledReview;
use Lwt\Modules\Review\Domain\Scheduling\Rating;
use Lwt\Modules\Review\Infrastructure\MySqlTermScheduleRepository;
use Lwt\Modules\Tags\Application\Services\TermTagService;
use Lwt\Modules\Vocabulary\Application\Services\Anki\ApkgExportService;
use Lwt\Modules\Vocabulary\Application\Services\Anki\ApkgImportService;
use Lwt\Modules\Vocabulary\Domain\ValueObject\TermStatus;
use Lwt\Modules\Vocabulary\Infrastructure\MySqlTermRepository;
use Lwt\Shared\Infrastructure\Bootstrap\EnvLoader;
use Lwt\Shared\Infrastructure\Database\Configuration;
use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Globals;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end integration test that exercises the full Anki interop chain
 * against a real database:
 *
 *   seed terms -> ApkgExportService -> .apkg on disk
 *               -> mutate terms in DB
 *               -> ApkgImportService(original .apkg) -> verify reverted
 *
 * Skips when LWT_TEST_DB_AVAILABLE is false (e.g., CI without MySQL).
 */
final class ApkgServiceIntegrationTest extends TestCase
{
    private static bool $dbConnected = false;
    private static int $languageId = 0;
    private static string $tmpApkgPath = '';

    /** @var list<int> term ids created during tests, cleared per test */
    private array $createdTermIds = [];

    public static function setUpBeforeClass(): void
    {
        $config = EnvLoader::getDatabaseConfig();
        $testDbName = 'test_' . $config['dbname'];

        if (!Globals::getDbConnection()) {
            try {
                $connection = Configuration::connect(
                    $config['server'],
                    $config['userid'],
                    $config['passwd'],
                    $testDbName,
                    $config['socket'] ?? ''
                );
                Globals::setDbConnection($connection);
                self::$dbConnected = true;
            } catch (\Throwable) {
                self::$dbConnected = false;
            }
        } else {
            self::$dbConnected = true;
        }

        if (!self::$dbConnected) {
            return;
        }

        Connection::query(
            "INSERT INTO languages (
                LgName, LgDict1URI, LgDict2URI, LgGoogleTranslateURI,
                LgTextSize, LgRegexpSplitSentences, LgRegexpWordCharacters,
                LgRemoveSpaces, LgSplitEachChar, LgRightToLeft, LgShowRomanization
            ) VALUES (
                'ApkgIntegrationTest_Lang', 'https://dict.test/apkg', '', '',
                100, '.!?', 'a-zA-Z',
                0, 0, 0, 1
            )"
        );
        self::$languageId = (int) mysqli_insert_id(Globals::getDbConnection());
    }

    public static function tearDownAfterClass(): void
    {
        if (!self::$dbConnected) {
            return;
        }

        Connection::query(
            "DELETE FROM word_tag_map WHERE WtWoID IN ("
            . "SELECT WoID FROM words WHERE WoLgID = " . self::$languageId
            . ")"
        );
        Connection::query("DELETE FROM tags WHERE TgText LIKE 'apkgi_%'");
        Connection::query("DELETE FROM words WHERE WoLgID = " . self::$languageId);
        if (self::$languageId > 0) {
            Connection::query("DELETE FROM languages WHERE LgID = " . self::$languageId);
        }

        if (self::$tmpApkgPath !== '' && is_file(self::$tmpApkgPath)) {
            unlink(self::$tmpApkgPath);
        }
    }

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite required to build an .apkg collection');
        }
        if (!defined('LWT_TEST_DB_AVAILABLE') || !LWT_TEST_DB_AVAILABLE) {
            $this->markTestSkipped('Database connection required');
        }
        if (!self::$dbConnected) {
            $this->markTestSkipped('Test database setup failed');
        }
    }

    protected function tearDown(): void
    {
        if (!self::$dbConnected) {
            return;
        }
        if ($this->createdTermIds !== []) {
            $ids = implode(',', array_map('intval', $this->createdTermIds));
            Connection::query("DELETE FROM word_tag_map WHERE WtWoID IN ($ids)");
            Connection::query("DELETE FROM words WHERE WoID IN ($ids)");
            $this->createdTermIds = [];
        }
    }

    public function testExportThenReimportRevertsLocalMutations(): void
    {
        $hello = $this->seedTerm('apkgi_hello', TermStatus::LEARNING_3, 'a greeting', 'həˈloʊ');
        $world = $this->seedTerm('apkgi_world', TermStatus::NEW, 'la planète', '');
        $known = $this->seedTerm('apkgi_known', TermStatus::WELL_KNOWN, 'familiar', '');

        TermTagService::saveWordTags($hello, ['apkgi_greeting', 'apkgi_common']);
        TermTagService::saveWordTags($world, ['apkgi_noun']);

        // === Export ===
        self::$tmpApkgPath = tempnam(sys_get_temp_dir(), 'lwt_apkg_int_');
        self::assertNotFalse(self::$tmpApkgPath);
        unlink(self::$tmpApkgPath); // writer creates it

        $export = ApkgExportService::default()
            ->exportLanguage(self::$languageId, self::$tmpApkgPath);

        self::assertSame(3, $export->noteCount);
        self::assertSame(1, $export->suspendedCount, 'WELL_KNOWN should be suspended');
        self::assertFileExists(self::$tmpApkgPath);

        // === Mutate the DB to look like the user fiddled with terms locally ===
        $repo = new MySqlTermRepository();
        $helloTerm = $repo->find($hello);
        self::assertNotNull($helloTerm);
        $helloTerm->updateTranslation('STALE LOCAL VALUE');
        $helloTerm->updateNotes('local notes');
        $repo->save($helloTerm);
        TermTagService::saveWordTags($hello, ['apkgi_stale']);

        // === Re-import the original .apkg: should restore hello, no-op world & known ===
        $import = ApkgImportService::default()->importApkg(self::$tmpApkgPath);

        self::assertSame(3, $import->totalNotes);
        self::assertGreaterThanOrEqual(1, $import->updated, 'hello should be re-updated');
        self::assertSame(0, $import->skippedUnknown);
        self::assertSame(0, $import->skippedMissing);

        // hello restored
        $reloaded = $repo->find($hello);
        self::assertNotNull($reloaded);
        self::assertSame('a greeting', $reloaded->translation());
        self::assertSame('', $reloaded->notes(), 'notes restored to original empty value');

        // tag restoration
        $tags = TermTagService::getWordTagsArray($hello);
        sort($tags);
        self::assertSame(
            ['apkgi_common', 'apkgi_greeting'],
            array_values($tags)
        );
    }

    public function testExportTermsRespectsSubsetSelection(): void
    {
        $a = $this->seedTerm('apkgi_subset_a', TermStatus::LEARNING_3, 'a', '');
        $b = $this->seedTerm('apkgi_subset_b', TermStatus::NEW, 'b', '');
        $c = $this->seedTerm('apkgi_subset_c', TermStatus::NEW, 'c', '');

        $path = tempnam(sys_get_temp_dir(), 'lwt_apkg_subset_');
        self::assertNotFalse($path);
        unlink($path);

        try {
            // Subset of two: a and c only
            $result = ApkgExportService::default()
                ->exportTerms(self::$languageId, [$a, $c], $path);
            self::assertSame(2, $result->noteCount);

            // Re-read with the standalone reader and confirm only a + c arrived
            $notes = (new \Lwt\Modules\Vocabulary\Infrastructure\Anki\ApkgReader())->read($path);
            $ids = array_map(static fn($n) => $n->lwtTermId, $notes);
            sort($ids);
            self::assertSame([$a, $c], $ids);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testImportSuspendsLearningTermBackedByApkg(): void
    {
        $term = $this->seedTerm('apkgi_suspend', TermStatus::LEARNING_3, 'tr', '');

        $path = tempnam(sys_get_temp_dir(), 'lwt_apkg_int_');
        self::assertNotFalse($path);
        unlink($path);

        try {
            ApkgExportService::default()->exportLanguage(self::$languageId, $path);

            // Hand-craft an Anki-side suspension by swapping the queue value
            // in the SQLite payload: read .apkg, mutate cards.queue=-1 for
            // our note, write back. Cheaper than spinning up Anki.
            $this->forceSuspendInApkg($path, $term);

            $repo = new MySqlTermRepository();
            $before = $repo->find($term);
            self::assertNotNull($before);
            self::assertSame(TermStatus::LEARNING_3, $before->status()->toInt());

            $result = ApkgImportService::default()->importApkg($path);
            self::assertGreaterThanOrEqual(1, $result->statusSetToIgnored);

            $after = $repo->find($term);
            self::assertNotNull($after);
            self::assertSame(TermStatus::IGNORED, $after->status()->toInt());
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testAReviewDoneInAnkiComesBackAndIsNotAppliedTwice(): void
    {
        $term = $this->seedTerm('apkgi_sched', TermStatus::LEARNING_3, 'scheduled', '');

        // A review done here first, so the file carries LWT's own history back
        // out with it — which is exactly what the import must not replay.
        $ours = new DateTimeImmutable('2026-01-10 10:00:00');
        self::assertTrue(
            (new RecordScheduledReview())->execute($term, Rating::Good, $ours)
        );

        $path = tempnam(sys_get_temp_dir(), 'lwt_apkg_sched_');
        self::assertNotFalse($path);
        unlink($path);

        try {
            ApkgExportService::default()->exportLanguage(self::$languageId, $path);

            // ... then a review the user did in Anki, a week later.
            $theirs = new DateTimeImmutable('2026-01-20 09:30:00');
            $this->addReviewToApkg($path, $term, $theirs, Rating::Easy);

            $schedules = new MySqlTermScheduleRepository();
            $before = $schedules->find($term);
            self::assertNotNull($before);

            $import = ApkgImportService::default()->importApkg($path);

            self::assertSame(1, $import->termsRescheduled);
            self::assertSame(1, $import->reviewsApplied, 'only the Anki review is new');

            $after = $schedules->find($term);
            self::assertNotNull($after);
            self::assertSame(
                $theirs->format('Y-m-d H:i:s'),
                $after->lastReview?->format('Y-m-d H:i:s'),
                'the term now stands where Anki left it'
            );
            self::assertSame($before->reps + 1, $after->reps);
            self::assertGreaterThan(
                $before->stability,
                $after->stability,
                'an Easy answer has to raise stability'
            );

            // Both reviews are on the record, each exactly once.
            $logged = Connection::preparedFetchAll(
                'SELECT RlGrade, RlReviewedAt FROM review_log WHERE RlWoID = ? ORDER BY RlReviewedAt',
                [$term]
            );
            self::assertCount(2, $logged);
            self::assertSame(Rating::Good->value, (int) $logged[0]['RlGrade']);
            self::assertSame(Rating::Easy->value, (int) $logged[1]['RlGrade']);

            // Importing the same file again must change nothing. Without the
            // "newer than our last review" rule this is where a term's whole
            // history would be applied a second time.
            $again = ApkgImportService::default()->importApkg($path);

            self::assertSame(0, $again->termsRescheduled);
            self::assertSame(0, $again->reviewsApplied);
            self::assertCount(
                2,
                Connection::preparedFetchAll(
                    'SELECT RlID FROM review_log WHERE RlWoID = ?',
                    [$term]
                )
            );
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * Add a revlog row to a card in an .apkg, as answering it in Anki would.
     *
     * The counterpart of forceSuspendInApkg: it lets the test stand in for
     * Anki without needing Anki.
     */
    private function addReviewToApkg(
        string $apkgPath,
        int $lwtId,
        DateTimeImmutable $reviewedAt,
        Rating $rating
    ): void {
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($apkgPath) === true);
        $contents = $zip->getFromName('collection.anki21');
        self::assertNotFalse($contents);

        $tmpDb = tempnam(sys_get_temp_dir(), 'lwt_apkg_review_');
        self::assertNotFalse($tmpDb);
        file_put_contents($tmpDb, $contents);

        $pdo = new \PDO('sqlite:' . $tmpDb);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $cardStmt = $pdo->prepare(
            'SELECT id FROM cards WHERE nid IN (SELECT id FROM notes WHERE guid = ?)'
        );
        $cardStmt->execute(['lwt-' . $lwtId]);
        $cardId = $cardStmt->fetchColumn();
        self::assertNotFalse($cardId, 'the exported file must contain a card for this term');

        $pdo->prepare(
            'INSERT INTO revlog (id, cid, usn, ease, ivl, lastIvl, factor, time, type) '
            . 'VALUES (?, ?, -1, ?, 15, 5, 2500, 0, 1)'
        )->execute([$reviewedAt->getTimestamp() * 1000, (int) $cardId, $rating->value]);
        unset($pdo);

        $newContents = file_get_contents($tmpDb);
        self::assertNotFalse($newContents);
        $zip->addFromString('collection.anki21', $newContents);
        $zip->addFromString('collection.anki2', $newContents);
        $zip->close();
        unlink($tmpDb);
    }

    private function seedTerm(string $text, int $status, string $translation, string $romanization): int
    {
        $conn = Globals::getDbConnection();
        $tEsc = mysqli_real_escape_string($conn, $text);
        $tlcEsc = mysqli_real_escape_string($conn, mb_strtolower($text, 'UTF-8'));
        $trEsc = mysqli_real_escape_string($conn, $translation);
        $roEsc = mysqli_real_escape_string($conn, $romanization);

        Connection::query(
            "INSERT INTO words (
                WoLgID, WoText, WoTextLC, WoStatus, WoTranslation, WoSentence,
                WoRomanization, WoNotes, WoWordCount, WoCreated, WoStatusChanged
            ) VALUES (
                " . self::$languageId . ", '$tEsc', '$tlcEsc', $status,
                '$trEsc', '', '$roEsc', '', 1, NOW(), NOW()
            )"
        );
        $id = (int) mysqli_insert_id($conn);
        $this->createdTermIds[] = $id;
        return $id;
    }

    /**
     * Edit a .apkg in place, flipping cards.queue=-1 for the card whose note's
     * `LwtId` field equals the supplied id. Lets us simulate "user suspended
     * this card in Anki" without needing Anki itself.
     */
    private function forceSuspendInApkg(string $apkgPath, int $lwtId): void
    {
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($apkgPath) === true);
        $contents = $zip->getFromName('collection.anki21');
        self::assertNotFalse($contents);

        $tmpDb = tempnam(sys_get_temp_dir(), 'lwt_apkg_mutate_');
        self::assertNotFalse($tmpDb);
        file_put_contents($tmpDb, $contents);

        $pdo = new \PDO('sqlite:' . $tmpDb);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $stmt = $pdo->prepare(
            "UPDATE cards SET queue = -1 "
            . "WHERE nid IN (SELECT id FROM notes WHERE guid = ?)"
        );
        $stmt->execute(['lwt-' . $lwtId]);
        unset($pdo);

        $newContents = file_get_contents($tmpDb);
        self::assertNotFalse($newContents);
        $zip->addFromString('collection.anki21', $newContents);
        $zip->addFromString('collection.anki2', $newContents);
        $zip->close();
        unlink($tmpDb);
    }
}
