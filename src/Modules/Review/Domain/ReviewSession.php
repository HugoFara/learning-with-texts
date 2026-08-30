<?php

/**
 * Review Session Entity
 *
 * Represents an active review/test session state.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Review\Domain
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Review\Domain;

/**
 * Entity representing an active review session.
 *
 * Tracks progress through a vocabulary test including
 * start time, total words, and correct/wrong counts.
 *
 * @since 3.0.0
 */
final class ReviewSession
{
    private int $startTime;
    private int $total;
    private int $correct;
    private int $wrong;

    /**
     * Constructor.
     *
     * @param int $startTime Unix timestamp when session started
     * @param int $total     Total number of words to test
     * @param int $correct   Number of correct answers (default 0)
     * @param int $wrong     Number of wrong answers (default 0)
     */
    public function __construct(
        int $startTime,
        int $total,
        int $correct = 0,
        int $wrong = 0
    ) {
        $this->startTime = $startTime;
        $this->total = $total;
        $this->correct = $correct;
        $this->wrong = $wrong;
    }

    /**
     * Start a new review session.
     *
     * @param int $total Total number of words to test
     *
     * @return self New session with current time as start
     */
    public static function start(int $total): self
    {
        return new self(time() + 2, $total, 0, 0);
    }

    /**
     * Get remaining words to test.
     *
     * @return int Number of remaining words
     */
    public function remaining(): int
    {
        return max(0, $this->total - $this->correct - $this->wrong);
    }

    /**
     * Record a correct answer.
     *
     * @return void
     */
    public function recordCorrect(): void
    {
        if ($this->remaining() > 0) {
            $this->correct++;
        }
    }

    /**
     * Record a wrong answer.
     *
     * @return void
     */
    public function recordWrong(): void
    {
        if ($this->remaining() > 0) {
            $this->wrong++;
        }
    }

    /**
     * Record an answer based on status change direction.
     *
     * @param int $statusChange -1 for wrong, 0 or positive for correct
     *
     * @return void
     */
    public function recordAnswer(int $statusChange): void
    {
        if ($statusChange >= 0) {
            $this->recordCorrect();
        } else {
            $this->recordWrong();
        }
    }

    /**
     * Check if session is finished.
     *
     * @return bool True if no words remaining
     */
    public function isFinished(): bool
    {
        return $this->remaining() === 0;
    }

    /**
     * Get start time.
     *
     * @return int Unix timestamp
     */
    public function getStartTime(): int
    {
        return $this->startTime;
    }

    /**
     * Get total words count.
     *
     * @return int Total words
     */
    public function getTotal(): int
    {
        return $this->total;
    }

    /**
     * Get correct answers count.
     *
     * @return int Correct count
     */
    public function getCorrect(): int
    {
        return $this->correct;
    }

    /**
     * Get wrong answers count.
     *
     * @return int Wrong count
     */
    public function getWrong(): int
    {
        return $this->wrong;
    }

    /**
     * Convert to array for serialization.
     *
     * @return array{start: int, total: int, correct: int, wrong: int, remaining: int}
     */
    public function toArray(): array
    {
        return [
            'start' => $this->startTime,
            'total' => $this->total,
            'correct' => $this->correct,
            'wrong' => $this->wrong,
            'remaining' => $this->remaining()
        ];
    }
}
