<?php

declare(strict_types=1);

namespace Lwt\Tests\Modules\Vocabulary\Application\Services\Anki;

use Lwt\Modules\Review\Domain\Scheduling\MemoryState;
use Lwt\Modules\Review\Domain\Scheduling\Rating;
use Lwt\Modules\Review\Domain\Scheduling\ReviewLogEntry;
use Lwt\Modules\Review\Domain\Scheduling\SchedulingResult;
use Lwt\Modules\Review\Domain\TermScheduleRepositoryInterface;

/**
 * Schedule persistence in memory, recording what was saved.
 */
final class InMemoryTermScheduleRepository implements TermScheduleRepositoryInterface
{
    /** @var array<int, MemoryState> */
    public array $states = [];

    /** @var list<array{wordId: int, result: SchedulingResult, rating: Rating, stateBefore: int}> */
    public array $saved = [];

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
        $this->saved[] = [
            'wordId' => $wordId,
            'result' => $result,
            'rating' => $rating,
            'stateBefore' => $stateBefore,
        ];
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
        return [];
    }
}
