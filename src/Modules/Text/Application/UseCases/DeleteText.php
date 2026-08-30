<?php

/**
 * Delete Text Use Case
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Text\Application\UseCases
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Text\Application\UseCases;

use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Database\DB;
use Lwt\Shared\Infrastructure\Database\Maintenance;
use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Shared\Infrastructure\Database\UserScopedQuery;

/**
 * Use case for deleting texts.
 *
 * Handles deletion of both active and archived texts including
 * cleanup of related data (sentences, text items, tags).
 *
 * @since 3.0.0
 */
class DeleteText
{
    /**
     * Delete an active text.
     *
     * @param int $textId Text ID
     *
     * @return array{texts: int, sentences: int, textItems: int} Counts of deleted items
     */
    public function execute(int $textId): array
    {
        $count3 = QueryBuilder::table('word_occurrences')
            ->where('Ti2TxID', '=', $textId)
            ->delete();
        $count2 = QueryBuilder::table('sentences')
            ->where('SeTxID', '=', $textId)
            ->delete();
        $count1 = QueryBuilder::table('texts')
            ->where('TxID', '=', $textId)
            ->delete();

        Maintenance::adjustAutoIncrement('texts', 'TxID');
        Maintenance::adjustAutoIncrement('sentences', 'SeID');
        $this->cleanupTextTags();

        return ['texts' => $count1, 'sentences' => $count2, 'textItems' => $count3];
    }

    /**
     * Delete multiple active texts.
     *
     * @param array $textIds Array of text IDs
     *
     * @return array{count: int} Count of deleted texts
     */
    public function deleteMultiple(array $textIds): array
    {
        if (empty($textIds)) {
            return ['count' => 0];
        }

        $ids = array_map('intval', $textIds);

        DB::beginTransaction();
        try {
            // Delete text items
            QueryBuilder::table('word_occurrences')
                ->whereIn('Ti2TxID', $ids)
                ->delete();

            // Delete sentences
            QueryBuilder::table('sentences')
                ->whereIn('SeTxID', $ids)
                ->delete();

            // Delete texts
            $affectedRows = QueryBuilder::table('texts')
                ->whereIn('TxID', $ids)
                ->delete();

            Maintenance::adjustAutoIncrement('texts', 'TxID');
            Maintenance::adjustAutoIncrement('sentences', 'SeID');
            $this->cleanupTextTags();

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollback();
            throw $e;
        }

        return ['count' => $affectedRows];
    }

    /**
     * Delete an archived text.
     *
     * @param int $textId Archived text ID
     *
     * @return array{count: int} Count of deleted texts
     */
    public function deleteArchivedText(int $textId): array
    {
        $bindings = [$textId];
        $deleted = Connection::preparedExecute(
            "DELETE FROM texts WHERE TxID = ? AND TxArchivedAt IS NOT NULL"
            . UserScopedQuery::forTablePrepared('texts', $bindings),
            $bindings
        );
        Maintenance::adjustAutoIncrement('texts', 'TxID');
        $this->cleanupTextTags();
        return ['count' => $deleted];
    }

    /**
     * Delete multiple archived texts.
     *
     * @param array $textIds Array of archived text IDs
     *
     * @return array{count: int} Count of deleted texts
     */
    public function deleteArchivedTexts(array $textIds): array
    {
        if (empty($textIds)) {
            return ['count' => 0];
        }

        /**
 * @var array<int, int> $ids
*/
        $ids = array_values(array_map('intval', $textIds));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $deleted = Connection::preparedExecute(
            "DELETE FROM texts WHERE TxID IN ({$placeholders}) AND TxArchivedAt IS NOT NULL"
            . UserScopedQuery::forTablePrepared('texts', $ids),
            $ids
        );
        Maintenance::adjustAutoIncrement('texts', 'TxID');
        $this->cleanupTextTags();
        return ['count' => $deleted];
    }

    /**
     * Clean up orphaned text tags.
     *
     * @return void
     */
    private function cleanupTextTags(): void
    {
        $bindings = [];
        Connection::preparedExecute(
            "DELETE text_tag_map
            FROM (
                text_tag_map
                LEFT JOIN texts ON TtTxID = TxID
            )
            WHERE TxID IS NULL"
            . UserScopedQuery::forTablePrepared('text_tag_map', $bindings, '', 'texts'),
            $bindings
        );
    }
}
