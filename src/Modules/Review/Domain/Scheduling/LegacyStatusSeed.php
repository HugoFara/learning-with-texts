<?php

declare(strict_types=1);

namespace Lwt\Modules\Review\Domain\Scheduling;

use DateInterval;
use DateTimeImmutable;

/**
 * Seeds FSRS memory state for a term that has never been graded.
 *
 * Every existing install has terms with a `WoStatus` and a `WoStatusChanged`
 * but no review history, so starting them all as brand-new FSRS cards would
 * throw away everything the user has already learned and flood the queue.
 * Instead each status is mapped to the stability that reproduces the legacy
 * Leitner schedule.
 *
 * Seeding is lazy — a row appears in `term_schedule` on a term's first graded
 * review, not via a bulk backfill. A 100k-term vocabulary therefore costs
 * nothing at upgrade time, and terms never reviewed never occupy a row.
 */
final class LegacyStatusSeed
{
    /**
     * Days each legacy status took to become "due" under
     * TermStatusService::SCORE_FORMULA_TODAY, i.e. where
     * `base(status) - decay(status) * days` crosses zero.
     *
     * status 1: -7d              => due immediately
     * status 2: 6.9  - 3.50d     => ~2 days
     * status 3: 20   - 2.30d     => ~9 days
     * status 4: 46.4 - 1.75d     => ~27 days
     * status 5: 100  - 1.40d     => ~71 days
     *
     * Because stability is *defined* as the time for retrievability to fall to
     * 0.9, and the default target retention is also 0.9, stability in days is
     * numerically the legacy interval — so these double as seed stabilities.
     *
     * @var array<int, float>
     */
    private const STABILITY_BY_STATUS = [
        1 => 1.0,
        2 => 2.0,
        3 => 9.0,
        4 => 27.0,
        5 => 71.0,
    ];

    /**
     * The seed stability, in days, of every schedulable status.
     *
     * Exposed so the review queue can compute the same due date in SQL for a
     * term that has no `term_schedule` row yet, without duplicating the table.
     *
     * @return array<int, float> Status => stability in days
     */
    public static function stabilityByStatus(): array
    {
        return self::STABILITY_BY_STATUS;
    }

    /**
     * Build the seed state for a term, or null if the status is not schedulable.
     *
     * 98 (ignored) and 99 (well-known) are manual flags that were never part of
     * the review queue, so they get no scheduling state.
     *
     * @param int               $status        The term's WoStatus
     * @param DateTimeImmutable $statusChanged When it last changed (WoStatusChanged)
     */
    public static function forStatus(int $status, DateTimeImmutable $statusChanged): ?MemoryState
    {
        $stability = self::STABILITY_BY_STATUS[$status] ?? null;
        if ($stability === null) {
            return null;
        }

        // Difficulty prior: what FSRS itself assigns to a card first answered
        // "Good". We have no per-term evidence, and this keeps a seeded term
        // on the same footing as one genuinely reviewed once.
        $difficulty = self::defaultDifficulty();

        $due = $statusChanged->add(new DateInterval('P' . (int) round($stability) . 'D'));

        return new MemoryState(
            stability: $stability,
            difficulty: $difficulty,
            due: $due,
            lastReview: $statusChanged,
            reps: 0,
            lapses: 0,
            state: SchedulingState::Review,
        );
    }

    /**
     * FSRS's initial difficulty for a "Good" first answer, clamped.
     */
    public static function defaultDifficulty(): float
    {
        $params = new FsrsParameters();

        $difficulty = $params->w(4)
            - (M_E ** ($params->w(5) * (Rating::Good->value - 1)))
            + 1;

        return min(
            max($difficulty, FsrsParameters::MIN_DIFFICULTY),
            FsrsParameters::MAX_DIFFICULTY
        );
    }
}
