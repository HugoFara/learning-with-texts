<?php

declare(strict_types=1);

namespace Lwt\Modules\Review\Infrastructure;

use DateTimeImmutable;
use Lwt\Modules\Review\Domain\Scheduling\LegacyStatusSeed;
use Lwt\Modules\Review\Domain\Scheduling\MemoryState;
use Lwt\Modules\Review\Domain\Scheduling\Rating;
use Lwt\Modules\Review\Domain\Scheduling\ReviewLogEntry;
use Lwt\Modules\Review\Domain\Scheduling\SchedulingResult;
use Lwt\Modules\Review\Domain\Scheduling\SchedulingState;
use Lwt\Modules\Review\Domain\TermScheduleRepositoryInterface;
use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Globals;

/**
 * MySQL persistence for FSRS scheduling state.
 *
 * `term_schedule` and `review_log` carry no user column of their own — they are
 * keyed by WoID, and `words.WoUsID` is the owner. QueryBuilder's automatic user
 * scope only covers tables in its table→column map, so every statement here
 * joins `words` and applies the scope explicitly. Skipping that would let a
 * caller read or write another user's scheduling state by WoID alone.
 */
final class MySqlTermScheduleRepository implements TermScheduleRepositoryInterface
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    public function find(int $wordId): ?MemoryState
    {
        if (!ScheduleSql::hasScheduleTable()) {
            return null;
        }

        $params = [$wordId];
        $scope = $this->appendUserScope($params);

        $row = Connection::preparedFetchOne(
            'SELECT TsStability, TsDifficulty, TsDue, TsLastReview, TsReps, TsLapses, TsState
             FROM term_schedule
             JOIN words ON WoID = TsWoID
             WHERE TsWoID = ?' . $scope,
            $params
        );

        if ($row === null) {
            return null;
        }

        return new MemoryState(
            stability: (float) $row['TsStability'],
            difficulty: (float) $row['TsDifficulty'],
            due: new DateTimeImmutable((string) $row['TsDue']),
            lastReview: $row['TsLastReview'] !== null
                ? new DateTimeImmutable((string) $row['TsLastReview'])
                : null,
            reps: (int) $row['TsReps'],
            lapses: (int) $row['TsLapses'],
            state: SchedulingState::from((int) $row['TsState']),
        );
    }

    public function findOrSeed(int $wordId): ?MemoryState
    {
        $existing = $this->find($wordId);
        if ($existing !== null) {
            return $existing;
        }

        $params = [$wordId];
        $scope = $this->appendUserScope($params);

        $row = Connection::preparedFetchOne(
            'SELECT WoStatus, WoStatusChanged FROM words WHERE WoID = ?' . $scope,
            $params
        );

        if ($row === null) {
            return null;
        }

        return LegacyStatusSeed::forStatus(
            (int) $row['WoStatus'],
            new DateTimeImmutable((string) $row['WoStatusChanged'])
        );
    }

    public function saveReview(int $wordId, SchedulingResult $result, Rating $rating, int $stateBefore): void
    {
        // Nowhere to write on a schema where the phase-2a migration did not
        // run. Dropping the write rather than failing keeps the review session
        // going: the term's status still moves, and the schedule starts being
        // kept once the schema is repaired.
        if (!ScheduleSql::hasScheduleTable()) {
            return;
        }

        // Ownership is checked once here rather than trusted from the caller,
        // so neither write below can touch a foreign term.
        if (!$this->ownsWord($wordId)) {
            return;
        }

        $state = $result->state;

        Connection::preparedExecute(
            'INSERT INTO term_schedule
                (TsWoID, TsStability, TsDifficulty, TsDue, TsLastReview, TsReps, TsLapses, TsState)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                TsStability = VALUES(TsStability),
                TsDifficulty = VALUES(TsDifficulty),
                TsDue = VALUES(TsDue),
                TsLastReview = VALUES(TsLastReview),
                TsReps = VALUES(TsReps),
                TsLapses = VALUES(TsLapses),
                TsState = VALUES(TsState)',
            [
                $wordId,
                $state->stability,
                $state->difficulty,
                $state->due->format(self::DATETIME_FORMAT),
                $state->lastReview?->format(self::DATETIME_FORMAT),
                $state->reps,
                $state->lapses,
                $state->state->value,
            ]
        );

        Connection::preparedExecute(
            'INSERT INTO review_log
                (RlWoID, RlGrade, RlState, RlStability, RlDifficulty,
                 RlElapsedDays, RlScheduledDays, RlReviewedAt)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $wordId,
                $rating->value,
                $stateBefore,
                $state->stability,
                $state->difficulty,
                $result->elapsedDays,
                $result->intervalDays,
                ($state->lastReview ?? new DateTimeImmutable())->format(self::DATETIME_FORMAT),
            ]
        );
    }

    public function countDue(?int $languageId = null): int
    {
        if (!ScheduleSql::hasScheduleTable()) {
            return 0;
        }

        $params = [];
        $sql = 'SELECT COUNT(*) AS value
                FROM term_schedule
                JOIN words ON WoID = TsWoID
                WHERE TsDue <= NOW()';

        if ($languageId !== null) {
            $sql .= ' AND WoLgID = ?';
            $params[] = $languageId;
        }

        $sql .= $this->appendUserScope($params);

        return (int) Connection::preparedFetchValue($sql, $params);
    }

    /**
     * Whether the current user owns this term.
     */
    private function ownsWord(int $wordId): bool
    {
        $params = [$wordId];
        $scope = $this->appendUserScope($params);

        /** @var int|string|null $hit */
        $hit = Connection::preparedFetchValue(
            'SELECT 1 AS value FROM words WHERE WoID = ?' . $scope,
            $params
        );

        return $hit !== null;
    }

    /**
     * {@inheritdoc}
     */
    public function findMany(array $wordIds): array
    {
        if ($wordIds === [] || !ScheduleSql::hasScheduleTable()) {
            return [];
        }

        $params = [];
        $inClause = Connection::buildPreparedInClause($wordIds, $params);
        $scope = $this->appendUserScope($params);

        $rows = Connection::preparedFetchAll(
            'SELECT TsWoID, TsStability, TsDifficulty, TsDue, TsLastReview, TsReps, TsLapses, TsState
             FROM term_schedule
             JOIN words ON WoID = TsWoID
             WHERE TsWoID IN ' . $inClause . $scope,
            $params
        );

        $states = [];
        foreach ($rows as $row) {
            $states[(int) $row['TsWoID']] = new MemoryState(
                stability: (float) $row['TsStability'],
                difficulty: (float) $row['TsDifficulty'],
                due: new DateTimeImmutable((string) $row['TsDue']),
                lastReview: $row['TsLastReview'] !== null
                    ? new DateTimeImmutable((string) $row['TsLastReview'])
                    : null,
                reps: (int) $row['TsReps'],
                lapses: (int) $row['TsLapses'],
                state: SchedulingState::from((int) $row['TsState']),
            );
        }

        return $states;
    }

    /**
     * {@inheritdoc}
     */
    public function historyFor(array $wordIds): array
    {
        if ($wordIds === [] || !ScheduleSql::hasScheduleTable()) {
            return [];
        }

        $params = [];
        $inClause = Connection::buildPreparedInClause($wordIds, $params);
        $scope = $this->appendUserScope($params);

        $rows = Connection::preparedFetchAll(
            'SELECT RlWoID, RlGrade, RlState, RlStability, RlDifficulty,
                    RlElapsedDays, RlScheduledDays, RlReviewedAt
             FROM review_log
             JOIN words ON WoID = RlWoID
             WHERE RlWoID IN ' . $inClause . $scope . '
             ORDER BY RlWoID, RlReviewedAt, RlID',
            $params
        );

        $history = [];
        foreach ($rows as $row) {
            $wordId = (int) $row['RlWoID'];
            $history[$wordId][] = new ReviewLogEntry(
                wordId: $wordId,
                grade: Rating::from((int) $row['RlGrade']),
                stateBefore: SchedulingState::from((int) $row['RlState']),
                stability: (float) $row['RlStability'],
                difficulty: (float) $row['RlDifficulty'],
                elapsedDays: (int) $row['RlElapsedDays'],
                scheduledDays: (int) $row['RlScheduledDays'],
                reviewedAt: new DateTimeImmutable((string) $row['RlReviewedAt']),
            );
        }

        return $history;
    }

    /**
     * Append the words-table user scope to $params and return the SQL fragment.
     *
     * Mirrors ReviewConfiguration::appendUserScope — inlined for the same
     * reason, to keep Psalm's `int|string` element type on $params.
     *
     * @param array<int, int|string> $params Reference to params array
     */
    private function appendUserScope(array &$params): string
    {
        if (!Globals::isMultiUserEnabled()) {
            return '';
        }

        $userId = Globals::getCurrentUserId();
        if ($userId === null) {
            return '';
        }

        $params[] = $userId;

        return ' AND WoUsID = ?';
    }
}
