<?php

/**
 * MySQL Activity Repository
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Activity\Infrastructure
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Activity\Infrastructure;

use Lwt\Modules\Activity\Domain\ActivityRepositoryInterface;
use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Database\UserScopedQuery;

/**
 * MySQL implementation of ActivityRepositoryInterface.
 *
 * Uses INSERT ... ON DUPLICATE KEY UPDATE for atomic counter upserts.
 *
 * @since 3.0.0
 */
class MySqlActivityRepository implements ActivityRepositoryInterface
{
    /**
     * {@inheritdoc}
     */
    public function incrementTermsCreated(int $count = 1): void
    {
        $this->incrementColumn('AlTermsCreated', $count);
    }

    /**
     * {@inheritdoc}
     */
    public function incrementTermsReviewed(int $count = 1): void
    {
        $this->incrementColumn('AlTermsReviewed', $count);
    }

    /**
     * {@inheritdoc}
     */
    public function incrementTextsRead(int $count = 1): void
    {
        $this->incrementColumn('AlTextsRead', $count);
    }

    /**
     * {@inheritdoc}
     */
    public function getActivityForDateRange(string $startDate, string $endDate): array
    {
        $bindings = [$startDate, $endDate];
        $userScope = UserScopedQuery::forTablePrepared('activity_log', $bindings);

        $tableName = 'activity_log';
        $rows = Connection::preparedFetchAll(
            "SELECT AlDate, AlTermsCreated, AlTermsReviewed, AlTextsRead
             FROM {$tableName}
             WHERE AlDate BETWEEN ? AND ?{$userScope}
             ORDER BY AlDate",
            $bindings
        );

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'date' => (string) $row['AlDate'],
                'terms_created' => (int) $row['AlTermsCreated'],
                'terms_reviewed' => (int) $row['AlTermsReviewed'],
                'texts_read' => (int) $row['AlTextsRead'],
            ];
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function getActiveDatesDescending(): array
    {
        $bindings = [];
        $userScope = UserScopedQuery::forTablePrepared('activity_log', $bindings);

        $tableName = 'activity_log';
        $rows = Connection::preparedFetchAll(
            "SELECT AlDate
             FROM {$tableName}
             WHERE (AlTermsCreated > 0 OR AlTermsReviewed > 0 OR AlTextsRead > 0)
             {$userScope}
             ORDER BY AlDate DESC",
            $bindings
        );

        $dates = [];
        foreach ($rows as $row) {
            $dates[] = (string) $row['AlDate'];
        }

        return $dates;
    }

    /**
     * {@inheritdoc}
     */
    public function getTodaySummary(): array
    {
        $bindings = [];
        $userScope = UserScopedQuery::forTablePrepared('activity_log', $bindings);

        $tableName = 'activity_log';
        $row = Connection::preparedFetchOne(
            "SELECT AlTermsCreated, AlTermsReviewed, AlTextsRead
             FROM {$tableName}
             WHERE AlDate = CURDATE(){$userScope}",
            $bindings
        );

        if ($row === null) {
            return ['terms_created' => 0, 'terms_reviewed' => 0, 'texts_read' => 0];
        }

        return [
            'terms_created' => (int) $row['AlTermsCreated'],
            'terms_reviewed' => (int) $row['AlTermsReviewed'],
            'texts_read' => (int) $row['AlTextsRead'],
        ];
    }

    /**
     * Atomically increment a column for today's row.
     *
     * Uses INSERT ... ON DUPLICATE KEY UPDATE for upsert.
     *
     * @param string $column Column name to increment
     * @param int    $count  Amount to add
     *
     * @return void
     */
    private function incrementColumn(string $column, int $count): void
    {
        $tableName = 'activity_log';
        $userId = UserScopedQuery::getUserIdForInsert('activity_log');

        $bindings = [$userId, $count, $count];
        Connection::preparedExecute(
            "INSERT INTO {$tableName}
                (AlUsID, AlDate, {$column})
             VALUES (?, CURDATE(), ?)
             ON DUPLICATE KEY UPDATE {$column} = {$column} + ?",
            $bindings
        );
    }
}
