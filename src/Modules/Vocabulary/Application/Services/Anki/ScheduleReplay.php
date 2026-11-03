<?php

declare(strict_types=1);

namespace Lwt\Modules\Vocabulary\Application\Services\Anki;

use DateTimeImmutable;
use Lwt\Modules\Review\Application\UseCases\MergeReviewHistory;
use Lwt\Modules\Review\Domain\Scheduling\HistoricReview;
use Lwt\Modules\Review\Domain\Scheduling\Rating;
use Lwt\Modules\Review\Domain\TermScheduleRepositoryInterface;
use Lwt\Modules\Review\Infrastructure\MySqlTermScheduleRepository;
use Lwt\Modules\Vocabulary\Domain\TermRepositoryInterface;
use Lwt\Modules\Vocabulary\Infrastructure\Anki\ApkgNote;
use Lwt\Modules\Vocabulary\Infrastructure\Anki\ApkgReview;
use Lwt\Modules\Vocabulary\Infrastructure\Anki\ApkgSchedule;
use Lwt\Modules\Vocabulary\Infrastructure\MySqlTermRepository;

/**
 * Brings back what the user did in Anki, as reviews rather than as numbers.
 *
 * The file also states each card's own memory state, and taking that directly
 * would be the obvious thing to do. It is the wrong thing. Anki computes it
 * with its own FSRS parameters, or with SM-2 and no memory state at all, so
 * importing it either mixes two parameter sets in one table or writes a number
 * that no model here produced. Replaying the grades through LWT's own scheduler
 * keeps one model answering for the whole vocabulary, and works the same
 * whether the collection used FSRS or not: `revlog` records what the learner
 * actually did, which is the durable half of a schedule.
 *
 * **Reviews join the term's history in time order** ({@see MergeReviewHistory}).
 * An exported file carries LWT's history back out with it; those reviews come
 * back with the same second and grade and are recognised and dropped, so
 * nothing is applied twice. The rest are folded in where they happened: after
 * LWT's own reviews they are appended, and before one of them the later
 * history is recomputed on top, so studying in both places between two
 * imports gives the schedule it would have had in one (#264).
 *
 * That is the whole conflict policy, and it needs no sync protocol: reviews
 * are facts with timestamps, so the merge is their union in time order. A term
 * studied in only one of the two places is the degenerate case.
 */
final class ScheduleReplay
{
    public function __construct(
        private readonly TermRepositoryInterface $terms,
        private readonly TermScheduleRepositoryInterface $schedules,
        private readonly MergeReviewHistory $merge,
    ) {
    }

    public static function default(): self
    {
        return new self(
            new MySqlTermRepository(),
            new MySqlTermScheduleRepository(),
            new MergeReviewHistory(),
        );
    }

    /**
     * Replay every note's newer reviews into LWT's schedule.
     *
     * @param list<ApkgNote> $notes Notes as read from the file
     *
     * @return array{terms: int, reviews: int, dueDatesMoved: int} Terms
     *         rescheduled, reviews applied, due dates set by hand in Anki
     */
    public function apply(array $notes): array
    {
        $byTerm = $this->schedulesByTerm($notes);
        if ($byTerm === []) {
            return ['terms' => 0, 'reviews' => 0, 'dueDatesMoved' => 0];
        }

        $states = $this->schedules->findMany(array_keys($byTerm));

        $termsRescheduled = 0;
        $reviewsApplied = 0;
        $dueDatesMoved = 0;
        foreach ($byTerm as $termId => $schedule) {
            if (!$this->isSchedulable($termId)) {
                continue;
            }

            $since = $states[$termId]?->lastReview ?? null;
            $applied = $this->replay($termId, $schedule->reviews);
            $moved = $this->applyManualDueDate($termId, $schedule, $since);

            if ($applied > 0 || $moved) {
                $termsRescheduled++;
            }
            $reviewsApplied += $applied;
            $dueDatesMoved += $moved ? 1 : 0;
        }

        return [
            'terms' => $termsRescheduled,
            'reviews' => $reviewsApplied,
            'dueDatesMoved' => $dueDatesMoved,
        ];
    }

    /**
     * The schedules in the file that say anything, keyed by LWT term id.
     *
     * A note whose card was never answered and never rescheduled by hand has
     * nothing to bring back, so it is dropped here rather than checked for
     * again at every step below.
     *
     * @param list<ApkgNote> $notes
     *
     * @return array<int, ApkgSchedule>
     */
    private function schedulesByTerm(array $notes): array
    {
        $byTerm = [];
        foreach ($notes as $note) {
            if ($note->lwtTermId <= 0 || $note->schedule === null) {
                continue;
            }
            if ($note->schedule->reviews === [] && $note->schedule->manualRescheduledAt === null) {
                continue;
            }
            $byTerm[$note->lwtTermId] = $note->schedule;
        }

        return $byTerm;
    }

    /**
     * Honour a due date the learner set by hand in Anki.
     *
     * Only when it is newer than everything else known about the term: LWT's
     * own last review, and every review in the file. Otherwise the card was
     * answered after being rescheduled, and the answer already decided when it
     * comes back.
     *
     * @param int                    $termId   Term to move
     * @param ApkgSchedule           $schedule Its state in the file
     * @param DateTimeImmutable|null $since    LWT's own last review, if any
     *
     * @return bool Whether the due date moved
     */
    private function applyManualDueDate(
        int $termId,
        ApkgSchedule $schedule,
        ?DateTimeImmutable $since
    ): bool {
        $manual = $schedule->manualRescheduledAt;
        if ($manual === null) {
            return false;
        }

        if ($since !== null && $manual <= $since) {
            return false;
        }

        foreach ($schedule->reviews as $review) {
            if ($review->reviewedAt >= $manual) {
                return false;
            }
        }

        return $this->schedules->reschedule($termId, $schedule->due);
    }

    /**
     * Whether this term can take a schedule at all.
     *
     * 98 (ignored) and 99 (well-known) are manual flags that were never in the
     * review queue, and a term the import has just marked ignored — because its
     * card came back suspended — is not one the user is studying. Scheduling
     * either would put it back in the queue the user took it out of.
     */
    private function isSchedulable(int $termId): bool
    {
        $term = $this->terms->find($termId);

        return $term !== null && !$term->status()->isSpecial();
    }

    /**
     * Merge one term's reviews from the file into its history.
     *
     * @param int              $termId  Term to reschedule
     * @param list<ApkgReview> $reviews Its history from the file
     *
     * @return int How many reviews were new here
     */
    private function replay(int $termId, array $reviews): int
    {
        $historic = [];
        foreach ($reviews as $review) {
            $rating = Rating::tryFrom($review->ease);
            if ($rating !== null) {
                $historic[] = new HistoricReview($review->reviewedAt, $rating);
            }
        }

        return $this->merge->execute($termId, $historic)['applied'];
    }
}
