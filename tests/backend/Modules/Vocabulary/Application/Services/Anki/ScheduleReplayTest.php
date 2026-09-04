<?php

declare(strict_types=1);

namespace Tests\Backend\Modules\Vocabulary\Application\Services\Anki;

use DateTimeImmutable;
use Lwt\Modules\Review\Application\UseCases\RecordScheduledReview;
use Lwt\Modules\Review\Domain\Scheduling\Fsrs6Scheduler;
use Lwt\Modules\Review\Domain\Scheduling\MemoryState;
use Lwt\Modules\Review\Domain\Scheduling\Rating;
use Lwt\Modules\Review\Domain\Scheduling\ReviewLogEntry;
use Lwt\Modules\Review\Domain\Scheduling\SchedulingResult;
use Lwt\Modules\Review\Domain\Scheduling\SchedulingState;
use Lwt\Modules\Review\Domain\TermScheduleRepositoryInterface;
use Lwt\Modules\Vocabulary\Application\Services\Anki\ScheduleReplay;
use Lwt\Modules\Vocabulary\Domain\Term;
use Lwt\Modules\Vocabulary\Domain\TermRepositoryInterface;
use Lwt\Modules\Vocabulary\Domain\ValueObject\TermStatus;
use Lwt\Modules\Vocabulary\Infrastructure\Anki\ApkgNote;
use Lwt\Modules\Vocabulary\Infrastructure\Anki\ApkgReview;
use Lwt\Modules\Vocabulary\Infrastructure\Anki\ApkgSchedule;
use Lwt\Tests\Modules\Vocabulary\Application\Services\Anki\InMemoryTermScheduleRepository;
use PHPUnit\Framework\TestCase;

/**
 * Replaying reviews done in Anki back into LWT's schedule (#264).
 *
 * No database: the schedule repository is an in-memory fake, so the real
 * Fsrs6Scheduler and RecordScheduledReview run for real over it and the test
 * asserts on what was actually persisted.
 */
final class ScheduleReplayTest extends TestCase
{
    private InMemoryTermScheduleRepository $schedules;

    protected function setUp(): void
    {
        $this->schedules = new InMemoryTermScheduleRepository();
    }

    public function testReviewsDoneInAnkiAreApplied(): void
    {
        $replay = $this->replayFor([7 => TermStatus::LEARNING_3]);

        $result = $replay->apply([$this->note(7, [
            $this->review('2026-02-01 10:00:00', Rating::Good),
            $this->review('2026-02-06 10:00:00', Rating::Easy),
        ])]);

        self::assertSame(['terms' => 1, 'reviews' => 2, 'dueDatesMoved' => 0], $result);
        self::assertCount(2, $this->schedules->saved);
        self::assertSame(Rating::Good, $this->schedules->saved[0]['rating']);
        self::assertSame(Rating::Easy, $this->schedules->saved[1]['rating']);
    }

    public function testTheReplayCompoundsRatherThanRestarting(): void
    {
        // Each review has to see the state the one before it wrote, or a run of
        // reviews would collapse to whatever the last one alone would give.
        $replay = $this->replayFor([7 => TermStatus::LEARNING_3]);

        $replay->apply([$this->note(7, [
            $this->review('2026-02-01 10:00:00', Rating::Good),
            $this->review('2026-02-06 10:00:00', Rating::Good),
            $this->review('2026-02-20 10:00:00', Rating::Good),
        ])]);

        $states = array_map(
            static fn(array $save) => $save['result']->state,
            $this->schedules->saved
        );
        self::assertCount(3, $states);
        self::assertGreaterThan($states[0]->stability, $states[1]->stability);
        self::assertGreaterThan($states[1]->stability, $states[2]->stability);
        self::assertSame(1, $states[0]->reps);
        self::assertSame(3, $states[2]->reps);
    }

    public function testEachReviewIsRecordedAtTheMomentItHappened(): void
    {
        // The replayed timestamp is what makes the next import able to tell
        // these reviews from later ones, so it has to be Anki's, not now.
        $replay = $this->replayFor([7 => TermStatus::LEARNING_3]);

        $replay->apply([$this->note(7, [$this->review('2026-02-01 10:00:00', Rating::Good)])]);

        self::assertSame(
            '2026-02-01 10:00:00',
            $this->schedules->saved[0]['result']->state->lastReview?->format('Y-m-d H:i:s')
        );
    }

    public function testReviewsAlreadyKnownHereAreNotAppliedAgain(): void
    {
        // An exported file carries LWT's own history back out with it. Without
        // this rule a re-import would replay every review a second time and
        // collapse the term's interval.
        $this->schedules->states[7] = $this->stateLastReviewedOn('2026-02-06 10:00:00');
        $replay = $this->replayFor([7 => TermStatus::LEARNING_3]);

        $result = $replay->apply([$this->note(7, [
            $this->review('2026-02-01 10:00:00', Rating::Good),
            $this->review('2026-02-06 10:00:00', Rating::Easy),
        ])]);

        self::assertSame(['terms' => 0, 'reviews' => 0, 'dueDatesMoved' => 0], $result);
        self::assertSame([], $this->schedules->saved);
    }

    public function testOnlyTheReviewsNewerThanOursAreApplied(): void
    {
        $this->schedules->states[7] = $this->stateLastReviewedOn('2026-02-06 10:00:00');
        $replay = $this->replayFor([7 => TermStatus::LEARNING_3]);

        $result = $replay->apply([$this->note(7, [
            $this->review('2026-02-01 10:00:00', Rating::Good),
            $this->review('2026-02-06 10:00:00', Rating::Easy),
            $this->review('2026-02-14 10:00:00', Rating::Hard),
        ])]);

        self::assertSame(['terms' => 1, 'reviews' => 1, 'dueDatesMoved' => 0], $result);
        self::assertSame(Rating::Hard, $this->schedules->saved[0]['rating']);
    }

    public function testReviewsOutOfOrderInTheFileAreAppliedInTimeOrder(): void
    {
        $replay = $this->replayFor([7 => TermStatus::LEARNING_3]);

        $replay->apply([$this->note(7, [
            $this->review('2026-02-20 10:00:00', Rating::Hard),
            $this->review('2026-02-01 10:00:00', Rating::Good),
        ])]);

        self::assertSame(Rating::Good, $this->schedules->saved[0]['rating']);
        self::assertSame(Rating::Hard, $this->schedules->saved[1]['rating']);
    }

    public function testIgnoredAndWellKnownTermsAreLeftAlone(): void
    {
        // Both are manual flags that were never in the review queue. A term
        // whose card came back suspended has just been demoted to 98 by the
        // import; scheduling it would put it straight back in the queue.
        $replay = $this->replayFor([
            1 => TermStatus::IGNORED,
            2 => TermStatus::WELL_KNOWN,
        ]);

        $result = $replay->apply([
            $this->note(1, [$this->review('2026-02-01 10:00:00', Rating::Good)]),
            $this->note(2, [$this->review('2026-02-01 10:00:00', Rating::Good)]),
        ]);

        self::assertSame(['terms' => 0, 'reviews' => 0, 'dueDatesMoved' => 0], $result);
    }

    public function testATermThatNoLongerExistsIsSkipped(): void
    {
        $replay = $this->replayFor([]);

        $result = $replay->apply([$this->note(7, [$this->review('2026-02-01 10:00:00', Rating::Good)])]);

        self::assertSame(['terms' => 0, 'reviews' => 0, 'dueDatesMoved' => 0], $result);
    }

    public function testNotesWithoutHistoryChangeNothing(): void
    {
        $replay = $this->replayFor([7 => TermStatus::LEARNING_3]);

        $unscheduled = new ApkgNote(7, 'hola', 'hello', '', '', [], false);
        $result = $replay->apply([$unscheduled, $this->note(8, [])]);

        self::assertSame(['terms' => 0, 'reviews' => 0, 'dueDatesMoved' => 0], $result);
        self::assertSame([], $this->schedules->findMany([7, 8]));
    }

    public function testANoteWithNoLwtIdIsIgnored(): void
    {
        $replay = $this->replayFor([7 => TermStatus::LEARNING_3]);

        $result = $replay->apply([$this->note(0, [$this->review('2026-02-01 10:00:00', Rating::Good)])]);

        self::assertSame(['terms' => 0, 'reviews' => 0, 'dueDatesMoved' => 0], $result);
    }

    public function testADueDateSetByHandInAnkiIsHonoured(): void
    {
        // "Set due date" writes a revlog row with no grade on it. There is
        // nothing to replay through the scheduler, but the learner still said
        // when the term should come back, and before this it was thrown away.
        $this->schedules->states[7] = $this->stateLastReviewedOn('2026-02-06 10:00:00');
        $replay = $this->replayFor([7 => TermStatus::LEARNING_3]);

        $result = $replay->apply([
            $this->note(7, [], '2026-02-10 09:00:00', '2026-08-01 00:00:00'),
        ]);

        self::assertSame(['terms' => 1, 'reviews' => 0, 'dueDatesMoved' => 1], $result);
        self::assertSame(
            '2026-08-01 00:00:00',
            $this->schedules->states[7]->due->format('Y-m-d H:i:s')
        );
        // No grade was given, so no review may be invented to explain the date.
        self::assertSame([], $this->schedules->saved);
    }

    public function testAManualRescheduleLeavesTheMemoryStateAlone(): void
    {
        // Postponing a term is not evidence about how well it is known, so
        // stability and difficulty must survive it untouched -- the next real
        // review carries on from where the term already was.
        $this->schedules->states[7] = $this->stateLastReviewedOn('2026-02-06 10:00:00');
        $replay = $this->replayFor([7 => TermStatus::LEARNING_3]);

        $replay->apply([$this->note(7, [], '2026-02-10 09:00:00', '2026-08-01 00:00:00')]);

        self::assertSame(9.0, $this->schedules->states[7]->stability);
        self::assertSame(5.0, $this->schedules->states[7]->difficulty);
        self::assertSame(
            '2026-02-06 10:00:00',
            $this->schedules->states[7]->lastReview?->format('Y-m-d H:i:s')
        );
    }

    public function testAnAnswerAfterTheRescheduleWins(): void
    {
        // A card pushed out and then actually answered has been answered. The
        // answer decides when it comes back, so the older manual date must not
        // overwrite what the replay just computed.
        $replay = $this->replayFor([7 => TermStatus::LEARNING_3]);

        $result = $replay->apply([
            $this->note(
                7,
                [$this->review('2026-02-20 10:00:00', Rating::Good)],
                '2026-02-10 09:00:00',
                '2026-08-01 00:00:00'
            ),
        ]);

        self::assertSame(['terms' => 1, 'reviews' => 1, 'dueDatesMoved' => 0], $result);
        self::assertSame([], $this->schedules->rescheduled);
    }

    public function testARescheduleWeHaveAlreadySeenIsNotAppliedAgain(): void
    {
        // Same rule as reviews: an exported file carries our own state back
        // out, so only what happened after the state we hold counts.
        $this->schedules->states[7] = $this->stateLastReviewedOn('2026-02-06 10:00:00');
        $replay = $this->replayFor([7 => TermStatus::LEARNING_3]);

        $result = $replay->apply([
            $this->note(7, [], '2026-02-01 09:00:00', '2026-08-01 00:00:00'),
        ]);

        self::assertSame(['terms' => 0, 'reviews' => 0, 'dueDatesMoved' => 0], $result);
        self::assertSame([], $this->schedules->rescheduled);
    }

    public function testARescheduleOnAnIgnoredTermIsLeftAlone(): void
    {
        $replay = $this->replayFor([1 => TermStatus::IGNORED]);

        $result = $replay->apply([$this->note(1, [], '2026-02-10 09:00:00')]);

        self::assertSame(['terms' => 0, 'reviews' => 0, 'dueDatesMoved' => 0], $result);
        self::assertSame([], $this->schedules->rescheduled);
    }

    /**
     * A replay wired to a fake term repository holding these statuses.
     *
     * @param array<int, int> $statusByTermId
     */
    private function replayFor(array $statusByTermId): ScheduleReplay
    {
        $terms = $this->createMock(TermRepositoryInterface::class);
        $terms->method('find')->willReturnCallback(
            function (int $id) use ($statusByTermId): ?Term {
                $status = $statusByTermId[$id] ?? null;

                return $status === null ? null : $this->term($id, $status);
            }
        );

        return new ScheduleReplay(
            $terms,
            $this->schedules,
            new RecordScheduledReview(new Fsrs6Scheduler(), $this->schedules),
        );
    }

    /**
     * A term that exists only to carry a status.
     */
    private function term(int $id, int $status): Term
    {
        $now = new DateTimeImmutable();

        return Term::reconstitute(
            id: $id,
            languageId: 1,
            text: 'hola',
            textLowercase: 'hola',
            lemma: null,
            lemmaLc: null,
            status: $status,
            translation: 'hello',
            sentence: '',
            notes: '',
            romanization: '',
            wordCount: 1,
            createdAt: $now,
            statusChangedAt: $now,
        );
    }

    /**
     * A note carrying nothing but the scheduling state under test.
     *
     * @param list<ApkgReview> $reviews
     * @param string|null      $manualAt When the due date was last set by hand
     * @param string           $due      The due date the file states
     */
    private function note(
        int $termId,
        array $reviews,
        ?string $manualAt = null,
        string $due = '2026-03-01 00:00:00'
    ): ApkgNote {
        return new ApkgNote(
            lwtTermId: $termId,
            term: 'hola',
            translation: 'hello',
            romanization: '',
            notes: '',
            tags: [],
            suspended: false,
            schedule: new ApkgSchedule(
                stability: 0.0,
                difficulty: 0.0,
                desiredRetention: 0.0,
                due: new DateTimeImmutable($due),
                intervalDays: 1,
                reps: count($reviews),
                lapses: 0,
                reviews: $reviews,
                manualRescheduledAt: $manualAt === null ? null : new DateTimeImmutable($manualAt),
            ),
        );
    }

    private function review(string $at, Rating $rating): ApkgReview
    {
        return new ApkgReview(
            reviewedAt: new DateTimeImmutable($at),
            ease: $rating->value,
            intervalDays: 1,
            lastIntervalDays: 0,
        );
    }

    private function stateLastReviewedOn(string $at): MemoryState
    {
        $lastReview = new DateTimeImmutable($at);

        return new MemoryState(
            stability: 9.0,
            difficulty: 5.0,
            due: $lastReview->modify('+9 days'),
            lastReview: $lastReview,
            reps: 2,
            lapses: 0,
            state: SchedulingState::Review,
        );
    }
}
