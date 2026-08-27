<?php

declare(strict_types=1);

namespace Lwt\Modules\Review\Domain\Scheduling;

use DateTimeImmutable;

/**
 * One row of a term's review history.
 *
 * The scheduler never reads these — FSRS schedules from current state alone.
 * They exist for parameter optimisation and for the .apkg round-trip, where
 * they become Anki `revlog` rows (issue #228).
 */
final class ReviewLogEntry
{
    public function __construct(
        public readonly int $wordId,
        public readonly Rating $grade,
        public readonly SchedulingState $stateBefore,
        public readonly float $stability,
        public readonly float $difficulty,
        public readonly int $elapsedDays,
        public readonly int $scheduledDays,
        public readonly DateTimeImmutable $reviewedAt,
    ) {
    }
}
