<?php

declare(strict_types=1);

namespace Lwt\Modules\Review\Domain;

use DateTimeImmutable;
use Lwt\Modules\Review\Domain\Scheduling\MemoryState;
use Lwt\Modules\Review\Domain\Scheduling\Rating;
use Lwt\Modules\Review\Domain\Scheduling\ReviewLogEntry;
use Lwt\Modules\Review\Domain\Scheduling\SchedulingResult;

/**
 * Persistence for FSRS memory state and review history.
 */
interface TermScheduleRepositoryInterface
{
    /**
     * Load a term's memory state, or null if it has never been graded.
     *
     * Returns null for a term the current user does not own.
     */
    public function find(int $wordId): ?MemoryState;

    /**
     * Load a term's memory state, falling back to a seed derived from its
     * legacy WoStatus/WoStatusChanged when it has never been graded.
     *
     * Returns null for a term that does not exist, is not owned by the current
     * user, or whose status is not schedulable (98 ignored / 99 well-known).
     *
     * @see \Lwt\Modules\Review\Domain\Scheduling\LegacyStatusSeed
     */
    public function findOrSeed(int $wordId): ?MemoryState;

    /**
     * Persist memory state and append the matching review_log row.
     *
     * Both writes happen together: the log is the audit trail for the state,
     * so a state change without its log entry would corrupt any later
     * parameter optimisation.
     */
    public function saveReview(int $wordId, SchedulingResult $result, Rating $rating, int $stateBefore): void;

    /**
     * Move a term's due date without recording a review.
     *
     * This is the "set due date" case: the learner decided when the term
     * should come back rather than earning the date by answering it. No
     * `review_log` row is written, and stability and difficulty are left
     * alone -- inventing a grade to justify the new date would poison the
     * history any later parameter optimisation reads, and there is no grade to
     * invent, because none was given.
     *
     * A term with no scheduling state yet gets one seeded from its legacy
     * status first, so the date has something to sit on.
     *
     * @param int                $wordId Term to move
     * @param DateTimeImmutable  $due    When it should next come up
     *
     * @return bool Whether the due date was stored
     */
    public function reschedule(int $wordId, DateTimeImmutable $due): bool;

    /**
     * Number of terms whose next review is due at or before now.
     *
     * @param int|null $languageId Restrict to one language, or null for all
     */
    public function countDue(?int $languageId = null): int;

    /**
     * Load memory state for many terms at once.
     *
     * Terms with no state are simply absent from the result, so the caller can
     * tell "never graded" from "graded" without a second query. Built for the
     * .apkg exporter, which needs a whole language's schedules in one go.
     *
     * @param list<int> $wordIds
     *
     * @return array<int, MemoryState> Keyed by word ID
     */
    public function findMany(array $wordIds): array;

    /**
     * Load the review history of many terms at once, oldest first.
     *
     * @param list<int> $wordIds
     *
     * @return array<int, list<ReviewLogEntry>> Keyed by word ID
     */
    public function historyFor(array $wordIds): array;
}
