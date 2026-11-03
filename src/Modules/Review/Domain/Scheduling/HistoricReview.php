<?php

/**
 * A review that happened somewhere else, waiting to join a term's history.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Review\Domain\Scheduling
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://github.com/HugoFara/lwt
 */

declare(strict_types=1);

namespace Lwt\Modules\Review\Domain\Scheduling;

use DateTimeImmutable;

/**
 * One past answer: when it was given and which grade.
 *
 * Only what the learner did, not what any scheduler made of it. Anki's own
 * interval and memory state are deliberately not carried, because LWT folds
 * every review through its own scheduler (#264).
 *
 * @phpstan-immutable
 */
final class HistoricReview
{
    public function __construct(
        public readonly DateTimeImmutable $reviewedAt,
        public readonly Rating $rating,
    ) {
    }

    /**
     * Identity used to recognise the same review coming back.
     *
     * A review LWT exported to Anki returns with the same second and grade,
     * and `review_log` stores seconds, so those two are what can be compared.
     */
    public function key(): string
    {
        return $this->reviewedAt->format('U') . ':' . $this->rating->value;
    }
}
