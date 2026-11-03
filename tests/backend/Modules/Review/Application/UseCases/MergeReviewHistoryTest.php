<?php

declare(strict_types=1);

namespace Lwt\Tests\Modules\Review\Application\UseCases;

use DateTimeImmutable;
use Lwt\Modules\Review\Application\UseCases\MergeReviewHistory;
use Lwt\Modules\Review\Application\UseCases\RecordScheduledReview;
use Lwt\Modules\Review\Domain\Scheduling\Fsrs6Scheduler;
use Lwt\Modules\Review\Domain\Scheduling\HistoricReview;
use Lwt\Modules\Review\Domain\Scheduling\LegacyStatusSeed;
use Lwt\Modules\Review\Domain\Scheduling\Rating;
use Lwt\Modules\Review\Domain\Scheduling\ReviewLogEntry;
use Lwt\Modules\Review\Domain\Scheduling\SchedulingState;
use Lwt\Tests\Modules\Vocabulary\Application\Services\Anki\InMemoryTermScheduleRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Merging reviews done elsewhere into a term's history (#264).
 *
 * The scheduler is the real one, the storage an in-memory double, so the
 * assertions are about the history and state that would be persisted.
 */
#[CoversClass(MergeReviewHistory::class)]
final class MergeReviewHistoryTest extends TestCase
{
    private InMemoryTermScheduleRepository $repository;
    private MergeReviewHistory $merge;

    protected function setUp(): void
    {
        $this->repository = new InMemoryTermScheduleRepository();
        $this->merge = new MergeReviewHistory(new Fsrs6Scheduler(), $this->repository);
    }

    public function testNothingNewChangesNothing(): void
    {
        $this->reviewedHere(['2026-02-01 10:00:00' => Rating::Good]);

        $result = $this->merge->execute(7, [$this->review('2026-02-01 10:00:00', Rating::Good)]);

        self::assertSame(['applied' => 0, 'duplicates' => 1, 'rebuilt' => false], $result);
        self::assertCount(1, $this->repository->history[7]);
    }

    public function testRepeatsWithinOneBatchCountOnce(): void
    {
        $result = $this->merge->execute(7, [
            $this->review('2026-02-01 10:00:00', Rating::Good),
            $this->review('2026-02-01 10:00:00', Rating::Good),
        ]);

        self::assertSame(['applied' => 1, 'duplicates' => 1, 'rebuilt' => false], $result);
        self::assertCount(1, $this->repository->history[7]);
    }

    public function testMillisecondsDoNotHideAReviewWeExported(): void
    {
        // review_log stores seconds; Anki's revlog id is milliseconds.
        $this->reviewedHere(['2026-02-01 10:00:00' => Rating::Good]);

        $result = $this->merge->execute(7, [$this->review('2026-02-01 10:00:00.734', Rating::Good)]);

        self::assertSame(0, $result['applied']);
    }

    public function testTwoGradesInTheSameSecondAreBothKept(): void
    {
        $this->reviewedHere(['2026-02-01 10:00:00' => Rating::Good]);

        $result = $this->merge->execute(7, [$this->review('2026-02-01 10:00:00', Rating::Again)]);

        self::assertSame(1, $result['applied']);
        self::assertCount(2, $this->repository->history[7]);
    }

    public function testNewerReviewsAreAppendedFromTheStoredState(): void
    {
        // A due date set by hand moves the stored state with no log row; an
        // append must start from it, not from a state rebuilt from the log.
        $this->reviewedHere(['2026-02-01 10:00:00' => Rating::Good]);
        $this->repository->reschedule(7, new DateTimeImmutable('2026-02-04 00:00:00'));
        $stored = $this->repository->states[7];

        $result = $this->merge->execute(7, [$this->review('2026-02-03 10:00:00', Rating::Good)]);

        self::assertFalse($result['rebuilt']);
        $expected = (new Fsrs6Scheduler())->review(
            $stored,
            Rating::Good,
            new DateTimeImmutable('2026-02-03 10:00:00')
        );
        self::assertEqualsWithDelta($expected->state->stability, $this->repository->states[7]->stability, 1e-9);
    }

    public function testAnOlderReviewRebuildsTheLaterHistory(): void
    {
        $this->reviewedHere(['2026-02-01 10:00:00' => Rating::Good, '2026-02-10 10:00:00' => Rating::Good]);
        $before = $this->repository->history[7][1]->stability;

        $result = $this->merge->execute(7, [$this->review('2026-02-05 10:00:00', Rating::Again)]);

        self::assertSame(['applied' => 1, 'duplicates' => 0, 'rebuilt' => true], $result);
        self::assertSame(
            [Rating::Good, Rating::Again, Rating::Good],
            array_map(static fn(ReviewLogEntry $e) => $e->grade, $this->repository->history[7])
        );
        // A lapse before it, so LWT's own second review now starts lower.
        self::assertLessThan($before, $this->repository->history[7][2]->stability);
        self::assertSame(SchedulingState::Relearning, $this->repository->history[7][2]->stateBefore);
    }

    public function testAReviewBeforeAllHistoryOfANewTermStartsFromNothing(): void
    {
        // First recorded review was from New: the fold starts from no state.
        $this->reviewedHere(['2026-02-10 10:00:00' => Rating::Good]);

        $this->merge->execute(7, [$this->review('2026-02-01 10:00:00', Rating::Easy)]);

        $history = $this->repository->history[7];
        self::assertSame(SchedulingState::New, $history[0]->stateBefore);
        self::assertSame(Rating::Easy, $history[0]->grade);
        $reference = (new Fsrs6Scheduler())->review(null, Rating::Easy, new DateTimeImmutable('2026-02-01 10:00:00'));
        self::assertEqualsWithDelta($reference->state->stability, $history[0]->stability, 1e-9);
    }

    public function testAReviewBeforeAllHistoryOfASeededTermStartsFromAFreshSeed(): void
    {
        $seed = LegacyStatusSeed::forStatus(3, new DateTimeImmutable('2026-01-01 00:00:00'));
        self::assertNotNull($seed);
        $this->repository->states[7] = $seed;
        $this->reviewedHere(['2026-02-10 10:00:00' => Rating::Good]);
        $this->repository->seeds[7] = $seed;

        $this->merge->execute(7, [$this->review('2026-02-01 10:00:00', Rating::Good)]);

        $first = $this->repository->history[7][0];
        self::assertSame(SchedulingState::Review, $first->stateBefore);
        $reference = (new Fsrs6Scheduler())->review($seed, Rating::Good, new DateTimeImmutable('2026-02-01 10:00:00'));
        self::assertEqualsWithDelta($reference->state->stability, $first->stability, 1e-9);
    }

    public function testATermTheRepositoryRefusesIsReportedAsUnchanged(): void
    {
        $repository = new class extends InMemoryTermScheduleRepository {
            public function replaceHistoryFrom(int $wordId, DateTimeImmutable $from, array $reviews): bool
            {
                return false;
            }
        };
        $merge = new MergeReviewHistory(new Fsrs6Scheduler(), $repository);

        $result = $merge->execute(7, [$this->review('2026-02-01 10:00:00', Rating::Good)]);

        self::assertSame(0, $result['applied']);
    }

    /**
     * @param array<string, Rating> $reviews Grade by review time
     */
    private function reviewedHere(array $reviews): void
    {
        $record = new RecordScheduledReview(new Fsrs6Scheduler(), $this->repository);
        foreach ($reviews as $at => $rating) {
            $record->execute(7, $rating, new DateTimeImmutable($at));
        }
    }

    private function review(string $at, Rating $rating): HistoricReview
    {
        return new HistoricReview(new DateTimeImmutable($at), $rating);
    }
}
