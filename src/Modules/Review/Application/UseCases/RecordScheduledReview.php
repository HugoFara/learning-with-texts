<?php

declare(strict_types=1);

namespace Lwt\Modules\Review\Application\UseCases;

use DateTimeImmutable;
use Lwt\Modules\Review\Domain\Scheduling\Fsrs6Scheduler;
use Lwt\Modules\Review\Domain\Scheduling\Rating;
use Lwt\Modules\Review\Domain\Scheduling\SchedulerInterface;
use Lwt\Modules\Review\Domain\Scheduling\SchedulingResult;
use Lwt\Modules\Review\Domain\TermScheduleRepositoryInterface;
use Lwt\Modules\Review\Infrastructure\MySqlTermScheduleRepository;
use Throwable;

/**
 * Record one graded review against a term's FSRS state.
 *
 * Phase 2a runs this *alongside* the legacy status/score update rather than
 * instead of it, so FSRS data accumulates on real reviews while the behaviour
 * users see is unchanged. Nothing reads the resulting schedule to pick review
 * words yet — that switch is opt-in and lands with phase 2b.
 *
 * Because this is a shadow write, a failure here must never break the review
 * the user just submitted: {@see execute()} swallows storage errors and
 * reports false rather than propagating.
 */
final class RecordScheduledReview
{
    private SchedulerInterface $scheduler;
    private TermScheduleRepositoryInterface $repository;

    public function __construct(
        ?SchedulerInterface $scheduler = null,
        ?TermScheduleRepositoryInterface $repository = null,
    ) {
        $this->scheduler = $scheduler ?? new Fsrs6Scheduler();
        $this->repository = $repository ?? new MySqlTermScheduleRepository();
    }

    /**
     * Apply a grade and persist the resulting state plus its log entry.
     *
     * @param int                    $wordId Term being reviewed
     * @param Rating                 $rating Grade given
     * @param DateTimeImmutable|null $now    Review time (defaults to now)
     *
     * @return bool Whether scheduling state was recorded
     */
    public function execute(int $wordId, Rating $rating, ?DateTimeImmutable $now = null): bool
    {
        $now ??= new DateTimeImmutable();

        try {
            $current = $this->repository->findOrSeed($wordId);

            $result = $this->scheduler->review($current, $rating, $now);

            $this->repository->saveReview(
                $wordId,
                $result,
                $rating,
                $current?->state->value ?? 0
            );

            return true;
        } catch (Throwable) {
            // Shadow write — never fail the user's review because the
            // scheduling side-table could not be updated.
            return false;
        }
    }

    /**
     * Compute the scheduling outcome without persisting it.
     *
     * Used by the API to preview what each grade would do (the "1d / 3d / 10d"
     * hints under the review buttons).
     */
    public function preview(int $wordId, Rating $rating, ?DateTimeImmutable $now = null): ?SchedulingResult
    {
        $now ??= new DateTimeImmutable();

        $current = $this->repository->findOrSeed($wordId);

        return $this->scheduler->review($current, $rating, $now);
    }

    /**
     * Preview every grade at once, for the hints under the review buttons.
     *
     * Reads the term's state once rather than per grade — the scheduler is
     * pure, so the four outcomes come from the same starting point anyway.
     *
     * @param int                    $wordId Term being reviewed
     * @param DateTimeImmutable|null $now    Review time (defaults to now)
     *
     * @return array<int, int> Interval in days, keyed by Rating value
     */
    public function previewIntervals(int $wordId, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();

        $current = $this->repository->findOrSeed($wordId);

        $intervals = [];
        foreach (Rating::cases() as $rating) {
            $intervals[$rating->value] = $this->scheduler->review($current, $rating, $now)->intervalDays;
        }

        return $intervals;
    }
}
