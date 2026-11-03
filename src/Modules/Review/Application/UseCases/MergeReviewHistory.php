<?php

/**
 * Merge reviews done elsewhere into a term's history.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Review\Application\UseCases
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://github.com/HugoFara/lwt
 */

declare(strict_types=1);

namespace Lwt\Modules\Review\Application\UseCases;

use DateInterval;
use Lwt\Modules\Review\Domain\Scheduling\Fsrs6Scheduler;
use Lwt\Modules\Review\Domain\Scheduling\HistoricReview;
use Lwt\Modules\Review\Domain\Scheduling\MemoryState;
use Lwt\Modules\Review\Domain\Scheduling\Rating;
use Lwt\Modules\Review\Domain\Scheduling\ReviewLogEntry;
use Lwt\Modules\Review\Domain\Scheduling\SchedulerInterface;
use Lwt\Modules\Review\Domain\Scheduling\SchedulingState;
use Lwt\Modules\Review\Domain\TermScheduleRepositoryInterface;
use Lwt\Modules\Review\Infrastructure\MySqlTermScheduleRepository;

/**
 * Fold reviews done in another app into a term's history, in time order.
 *
 * A term's schedule is its review history folded through the scheduler. When
 * reviews arrive from Anki they join that history: those LWT already holds
 * (a review it exported and is now reading back) are recognised by second and
 * grade and dropped, and the rest are applied in the order they happened.
 *
 * If every new review is later than LWT's latest, they are simply appended.
 * If one predates it -- studied on the phone in the morning, in LWT at noon,
 * synced in the evening -- the history is recomputed from that review
 * onwards, LWT's later reviews included, so the result is what it would have
 * been had both been done in one place (#264).
 *
 * The state the recomputation starts from is rebuilt exactly from the last
 * review before it: the log stores stability, difficulty and interval after
 * each review, and reps, lapses and state follow from the grades. The one
 * thing not stored is the seed a term had before its first graded review, so a
 * review predating a seeded term's whole history starts from a fresh seed of
 * its current status instead (see {@see TermScheduleRepositoryInterface::seedFor()}).
 */
final class MergeReviewHistory
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
     * Merge reviews into one term's history.
     *
     * The caller decides whether the term may be scheduled at all (ignored and
     * well-known terms are not in the queue); this only orders and folds.
     *
     * @param int                  $wordId   Term the reviews belong to
     * @param list<HistoricReview> $incoming Reviews from elsewhere, any order
     *
     * @return array{applied: int, duplicates: int, rebuilt: bool} New reviews
     *         recorded, reviews already known, and whether later history had
     *         to be recomputed
     */
    public function execute(int $wordId, array $incoming): array
    {
        $history = $this->repository->historyFor([$wordId])[$wordId] ?? [];

        [$new, $duplicates] = $this->unseen($history, $incoming);
        if ($new === []) {
            return ['applied' => 0, 'duplicates' => $duplicates, 'rebuilt' => false];
        }

        $from = $new[0]->reviewedAt;
        $earlier = [];
        $later = [];
        foreach ($history as $entry) {
            if ($entry->reviewedAt < $from) {
                $earlier[] = $entry;
            } else {
                $later[] = $entry;
            }
        }

        // Appending after everything known: the stored state is the truest
        // start, since a due date set by hand moves it without a log row.
        $start = $later === [] && $history !== []
            ? ($this->repository->find($wordId) ?? self::stateAfter($history))
            : $this->startingState($wordId, $history, $earlier);

        // LWT's own later reviews are replayed alongside the new ones. Listed
        // first, so a stable sort keeps them ahead of an Anki review in the
        // same second.
        $events = [];
        foreach ($later as $entry) {
            $events[] = new HistoricReview($entry->reviewedAt, $entry->grade);
        }
        $events = array_merge($events, $new);
        usort(
            $events,
            static fn(HistoricReview $a, HistoricReview $b): int => $a->reviewedAt <=> $b->reviewedAt
        );

        $reviews = [];
        $state = $start;
        foreach ($events as $event) {
            $result = $this->scheduler->review($state, $event->rating, $event->reviewedAt);
            $reviews[] = [
                'result' => $result,
                'rating' => $event->rating,
                'stateBefore' => $state?->state->value ?? SchedulingState::New->value,
            ];
            $state = $result->state;
        }

        if (!$this->repository->replaceHistoryFrom($wordId, $from, $reviews)) {
            return ['applied' => 0, 'duplicates' => $duplicates, 'rebuilt' => false];
        }

        return ['applied' => count($new), 'duplicates' => $duplicates, 'rebuilt' => $later !== []];
    }

    /**
     * The incoming reviews LWT does not have yet, oldest first.
     *
     * @param list<ReviewLogEntry> $history
     * @param list<HistoricReview> $incoming
     *
     * @return array{0: list<HistoricReview>, 1: int} New reviews and how many
     *         were already known (including repeats within $incoming)
     */
    private function unseen(array $history, array $incoming): array
    {
        $known = [];
        foreach ($history as $entry) {
            $known[(new HistoricReview($entry->reviewedAt, $entry->grade))->key()] = true;
        }

        usort(
            $incoming,
            static fn(HistoricReview $a, HistoricReview $b): int => $a->reviewedAt <=> $b->reviewedAt
        );

        $new = [];
        $duplicates = 0;
        foreach ($incoming as $review) {
            $key = $review->key();
            if (isset($known[$key])) {
                $duplicates++;
                continue;
            }
            $known[$key] = true;
            $new[] = $review;
        }

        return [$new, $duplicates];
    }

    /**
     * The state to fold the merged reviews from.
     *
     * @param list<ReviewLogEntry> $history All of the term's reviews
     * @param list<ReviewLogEntry> $earlier Those before the first new review
     */
    private function startingState(int $wordId, array $history, array $earlier): ?MemoryState
    {
        if ($earlier !== []) {
            return self::stateAfter($earlier);
        }

        if ($history === []) {
            // Never graded here: its current state is its seed (or nothing).
            return $this->repository->findOrSeed($wordId);
        }

        // Every recorded review is being recomputed. Start where the first
        // one did: from nothing if the term was new, otherwise from a seed.
        return $history[0]->stateBefore === SchedulingState::New
            ? null
            : $this->repository->seedFor($wordId);
    }

    /**
     * The memory state right after the last of these reviews.
     *
     * Mirrors what the scheduler produced: reps count every review, lapses
     * count Again, and Again leaves the term relearning.
     *
     * @param non-empty-list<ReviewLogEntry> $entries A term's reviews, oldest first
     */
    private static function stateAfter(array $entries): MemoryState
    {
        $last = $entries[count($entries) - 1];
        $lapses = 0;
        foreach ($entries as $entry) {
            if ($entry->grade === Rating::Again) {
                $lapses++;
            }
        }

        return new MemoryState(
            stability: $last->stability,
            difficulty: $last->difficulty,
            due: $last->reviewedAt->add(new DateInterval('P' . $last->scheduledDays . 'D')),
            lastReview: $last->reviewedAt,
            reps: count($entries),
            lapses: $lapses,
            state: $last->grade === Rating::Again ? SchedulingState::Relearning : SchedulingState::Review,
        );
    }
}
