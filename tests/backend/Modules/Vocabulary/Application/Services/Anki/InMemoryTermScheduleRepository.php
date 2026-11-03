<?php

declare(strict_types=1);

namespace Lwt\Tests\Modules\Vocabulary\Application\Services\Anki;

use DateTimeImmutable;
use Lwt\Modules\Review\Domain\Scheduling\MemoryState;
use Lwt\Modules\Review\Domain\Scheduling\Rating;
use Lwt\Modules\Review\Domain\Scheduling\ReviewLogEntry;
use Lwt\Modules\Review\Domain\Scheduling\SchedulingResult;
use Lwt\Modules\Review\Domain\Scheduling\SchedulingState;
use Lwt\Modules\Review\Domain\TermScheduleRepositoryInterface;

/**
 * Schedule persistence in memory, recording what was saved.
 */
class InMemoryTermScheduleRepository implements TermScheduleRepositoryInterface
{
    /** @var array<int, MemoryState> */
    public array $states = [];

    /** @var list<array{wordId: int, result: SchedulingResult, rating: Rating, stateBefore: int}> */
    public array $saved = [];

    /** @var list<array{wordId: int, due: DateTimeImmutable}> */
    public array $rescheduled = [];

    /** @var array<int, list<ReviewLogEntry>> Review history, oldest first */
    public array $history = [];

    /** @var array<int, MemoryState> What seedFor() answers, per term */
    public array $seeds = [];

    public function find(int $wordId): ?MemoryState
    {
        return $this->states[$wordId] ?? null;
    }

    public function findOrSeed(int $wordId): ?MemoryState
    {
        return $this->find($wordId);
    }

    public function saveReview(int $wordId, SchedulingResult $result, Rating $rating, int $stateBefore): void
    {
        $this->states[$wordId] = $result->state;
        $this->history[$wordId][] = self::logEntry($wordId, $result, $rating, $stateBefore);
        $this->saved[] = [
            'wordId' => $wordId,
            'result' => $result,
            'rating' => $rating,
            'stateBefore' => $stateBefore,
        ];
    }

    public function reschedule(int $wordId, DateTimeImmutable $due): bool
    {
        $state = $this->find($wordId);
        if ($state === null) {
            return false;
        }

        $this->states[$wordId] = new MemoryState(
            stability: $state->stability,
            difficulty: $state->difficulty,
            due: $due,
            lastReview: $state->lastReview,
            reps: $state->reps,
            lapses: $state->lapses,
            state: $state->state,
        );
        $this->rescheduled[] = ['wordId' => $wordId, 'due' => $due];

        return true;
    }

    public function countDue(?int $languageId = null): int
    {
        return 0;
    }

    /**
     * @param list<int> $wordIds
     *
     * @return array<int, MemoryState>
     */
    public function findMany(array $wordIds): array
    {
        $out = [];
        foreach ($wordIds as $wordId) {
            if (isset($this->states[$wordId])) {
                $out[$wordId] = $this->states[$wordId];
            }
        }

        return $out;
    }

    /**
     * @param list<int> $wordIds
     *
     * @return array<int, list<ReviewLogEntry>>
     */
    public function historyFor(array $wordIds): array
    {
        $out = [];
        foreach ($wordIds as $wordId) {
            if (isset($this->history[$wordId])) {
                $out[$wordId] = $this->history[$wordId];
            }
        }

        return $out;
    }

    public function replaceHistoryFrom(int $wordId, DateTimeImmutable $from, array $reviews): bool
    {
        if ($reviews === []) {
            return false;
        }

        $kept = array_values(array_filter(
            $this->history[$wordId] ?? [],
            static fn(ReviewLogEntry $entry): bool => $entry->reviewedAt < $from
        ));
        foreach ($reviews as $review) {
            $kept[] = self::logEntry($wordId, $review['result'], $review['rating'], $review['stateBefore']);
            $this->saved[] = ['wordId' => $wordId] + $review;
        }
        $this->history[$wordId] = $kept;
        $this->states[$wordId] = $reviews[count($reviews) - 1]['result']->state;

        return true;
    }

    public function seedFor(int $wordId): ?MemoryState
    {
        return $this->seeds[$wordId] ?? null;
    }

    private static function logEntry(
        int $wordId,
        SchedulingResult $result,
        Rating $rating,
        int $stateBefore
    ): ReviewLogEntry {
        return new ReviewLogEntry(
            wordId: $wordId,
            grade: $rating,
            stateBefore: SchedulingState::from($stateBefore),
            stability: $result->state->stability,
            difficulty: $result->state->difficulty,
            elapsedDays: $result->elapsedDays,
            scheduledDays: $result->intervalDays,
            reviewedAt: $result->state->lastReview ?? new DateTimeImmutable(),
        );
    }
}
