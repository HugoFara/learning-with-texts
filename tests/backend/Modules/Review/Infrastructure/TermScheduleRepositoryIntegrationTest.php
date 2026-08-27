<?php

declare(strict_types=1);

namespace Lwt\Tests\Modules\Review\Infrastructure;

use DateTimeImmutable;
use Lwt\Modules\Review\Application\UseCases\RecordScheduledReview;
use Lwt\Modules\Review\Domain\Scheduling\Fsrs6Scheduler;
use Lwt\Modules\Review\Domain\Scheduling\Rating;
use Lwt\Modules\Review\Infrastructure\MySqlTermScheduleRepository;
use Lwt\Shared\Infrastructure\Bootstrap\EnvLoader;
use Lwt\Shared\Infrastructure\Database\Configuration;
use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Globals;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Exercises FSRS persistence against a real database: lazy seeding from
 * WoStatus, the state + review_log write pair, and the due count.
 *
 * Skips when LWT_TEST_DB_AVAILABLE is false (e.g. CI without MySQL).
 */
#[Group('integration')]
final class TermScheduleRepositoryIntegrationTest extends TestCase
{
    private static bool $dbConnected = false;
    private static int $languageId = 0;

    /** @var list<int> */
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
                'FsrsScheduleTest_Lang', 'https://dict.test/fsrs', '', '',
                100, '.!?', 'a-zA-Z',
                0, 0, 0, 1
            )"
        );
        self::$languageId = (int) mysqli_insert_id(Globals::getDbConnection());
    }

    public static function tearDownAfterClass(): void
    {
        if (!self::$dbConnected || self::$languageId === 0) {
            return;
        }

        // review_log / term_schedule cascade from words, but delete explicitly
        // so the test is self-cleaning even if the FKs are absent.
        Connection::query(
            'DELETE FROM review_log WHERE RlWoID IN ('
            . 'SELECT WoID FROM words WHERE WoLgID = ' . self::$languageId . ')'
        );
        Connection::query(
            'DELETE FROM term_schedule WHERE TsWoID IN ('
            . 'SELECT WoID FROM words WHERE WoLgID = ' . self::$languageId . ')'
        );
        Connection::query('DELETE FROM words WHERE WoLgID = ' . self::$languageId);
        Connection::query('DELETE FROM languages WHERE LgID = ' . self::$languageId);
    }

    protected function setUp(): void
    {
        if (!defined('LWT_TEST_DB_AVAILABLE') || !LWT_TEST_DB_AVAILABLE) {
            $this->markTestSkipped('Database connection required');
        }
        if (!self::$dbConnected) {
            $this->markTestSkipped('Test database setup failed');
        }

        $this->createdTermIds = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->createdTermIds as $id) {
            Connection::query('DELETE FROM review_log WHERE RlWoID = ' . $id);
            Connection::query('DELETE FROM term_schedule WHERE TsWoID = ' . $id);
            Connection::query('DELETE FROM words WHERE WoID = ' . $id);
        }
    }

    private function createTerm(string $text, int $status, string $statusChanged): int
    {
        Connection::preparedExecute(
            'INSERT INTO words (WoLgID, WoText, WoTextLC, WoStatus, WoTranslation,
                                WoStatusChanged)
             VALUES (?, ?, ?, ?, ?, ?)',
            [self::$languageId, $text, strtolower($text), $status, 'translation', $statusChanged]
        );

        $id = (int) mysqli_insert_id(Globals::getDbConnection());
        $this->createdTermIds[] = $id;

        return $id;
    }

    public function testUnreviewedTermHasNoStoredStateButSeedsFromStatus(): void
    {
        $repo = new MySqlTermScheduleRepository();
        $wordId = $this->createTerm('seedme', 4, '2026-01-01 12:00:00');

        $this->assertNull($repo->find($wordId), 'nothing should be stored yet');

        $seeded = $repo->findOrSeed($wordId);
        $this->assertNotNull($seeded);
        $this->assertEqualsWithDelta(27.0, $seeded->stability, 1e-9, 'status 4 seeds the legacy 27-day interval');
        $this->assertSame('2026-01-01', $seeded->lastReview?->format('Y-m-d'));
    }

    public function testIgnoredTermSeedsNothing(): void
    {
        $repo = new MySqlTermScheduleRepository();
        $wordId = $this->createTerm('ignoreme', 98, '2026-01-01 12:00:00');

        $this->assertNull($repo->findOrSeed($wordId));
    }

    public function testSaveReviewPersistsStateAndAppendsLog(): void
    {
        $repo = new MySqlTermScheduleRepository();
        $scheduler = new Fsrs6Scheduler();
        $wordId = $this->createTerm('persistme', 3, '2026-01-01 12:00:00');

        $reviewedAt = new DateTimeImmutable('2026-02-01 09:00:00');
        $result = $scheduler->review($repo->findOrSeed($wordId), Rating::Good, $reviewedAt);

        $repo->saveReview($wordId, $result, Rating::Good, 2);

        $stored = $repo->find($wordId);
        $this->assertNotNull($stored, 'state must round-trip');
        $this->assertEqualsWithDelta($result->state->stability, $stored->stability, 1e-6);
        $this->assertEqualsWithDelta($result->state->difficulty, $stored->difficulty, 1e-6);
        $this->assertSame(1, $stored->reps);
        $this->assertSame(0, $stored->lapses);

        $logCount = (int) Connection::preparedFetchValue(
            'SELECT COUNT(*) AS value FROM review_log WHERE RlWoID = ?',
            [$wordId]
        );
        $this->assertSame(1, $logCount, 'exactly one log row per review');

        $log = Connection::preparedFetchOne(
            'SELECT RlGrade, RlScheduledDays, RlElapsedDays FROM review_log WHERE RlWoID = ?',
            [$wordId]
        );
        $this->assertNotNull($log);
        $this->assertSame(Rating::Good->value, (int) $log['RlGrade']);
        $this->assertSame($result->intervalDays, (int) $log['RlScheduledDays']);
        // 2026-01-01 12:00 -> 2026-02-01 09:00 is 30 days and 21 hours, and
        // FSRS works in whole elapsed days, so this floors to 30.
        $this->assertSame(30, (int) $log['RlElapsedDays']);
    }

    public function testRepeatedReviewsUpsertStateAndAccumulateLog(): void
    {
        $repo = new MySqlTermScheduleRepository();
        $useCase = new RecordScheduledReview(new Fsrs6Scheduler(), $repo);
        $wordId = $this->createTerm('repeatme', 2, '2026-01-01 12:00:00');

        $this->assertTrue($useCase->execute($wordId, Rating::Good, new DateTimeImmutable('2026-02-01 09:00:00')));
        $this->assertTrue($useCase->execute($wordId, Rating::Again, new DateTimeImmutable('2026-02-10 09:00:00')));
        $this->assertTrue($useCase->execute($wordId, Rating::Good, new DateTimeImmutable('2026-02-12 09:00:00')));

        $stateRows = (int) Connection::preparedFetchValue(
            'SELECT COUNT(*) AS value FROM term_schedule WHERE TsWoID = ?',
            [$wordId]
        );
        $this->assertSame(1, $stateRows, 'state is upserted, never duplicated');

        $logRows = (int) Connection::preparedFetchValue(
            'SELECT COUNT(*) AS value FROM review_log WHERE RlWoID = ?',
            [$wordId]
        );
        $this->assertSame(3, $logRows, 'log is append-only');

        $stored = $repo->find($wordId);
        $this->assertNotNull($stored);
        $this->assertSame(3, $stored->reps);
        $this->assertSame(1, $stored->lapses, 'the single Again counted as one lapse');
    }

    public function testCountDueOnlyCountsTermsPastTheirDueDate(): void
    {
        $repo = new MySqlTermScheduleRepository();
        $useCase = new RecordScheduledReview(new Fsrs6Scheduler(), $repo);

        $overdue = $this->createTerm('overdueterm', 3, '2026-01-01 12:00:00');
        $fresh = $this->createTerm('freshterm', 3, '2026-01-01 12:00:00');

        // Reviewed long ago with a short interval -> due by now.
        $useCase->execute($overdue, Rating::Again, new DateTimeImmutable('2026-01-02 09:00:00'));
        // Reviewed right now with a long interval -> not due.
        $useCase->execute($fresh, Rating::Easy, new DateTimeImmutable());

        $due = $repo->countDue(self::$languageId);

        $this->assertGreaterThanOrEqual(1, $due, 'the overdue term must be counted');

        $freshState = $repo->find($fresh);
        $this->assertNotNull($freshState);
        $this->assertFalse(
            $freshState->isDue(new DateTimeImmutable()),
            'a term just rated Easy must not be due'
        );
    }

    public function testStateForAMissingTermIsNull(): void
    {
        $repo = new MySqlTermScheduleRepository();

        $this->assertNull($repo->find(999999999));
        $this->assertNull($repo->findOrSeed(999999999));
    }
}
