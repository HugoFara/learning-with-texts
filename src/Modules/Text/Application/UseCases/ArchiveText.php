<?php

/**
 * Archive Text Use Case
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
use Lwt\Shared\Infrastructure\Database\TextParsing;
use Lwt\Shared\Infrastructure\Database\UserScopedQuery;

/**
 * Use case for archiving and unarchiving texts.
 *
 * Texts are archived by setting TxArchivedAt to the current timestamp.
 * Unarchiving sets TxArchivedAt back to NULL.
 *
 * @since 3.0.0
 */
class ArchiveText
{
    /**
     * Archive an active text.
     *
     * Sets TxArchivedAt to current timestamp and deletes parsed data.
     *
     * @param int $textId Text ID
     *
     * @return array{sentences: int, textItems: int, archived: int} Counts
     */
    public function execute(int $textId): array
    {
        // Delete parsed data
        $count3 = QueryBuilder::table('word_occurrences')
            ->where('Ti2TxID', '=', $textId)
            ->delete();
        $count2 = QueryBuilder::table('sentences')
            ->where('SeTxID', '=', $textId)
            ->delete();

        // Mark as archived
        $bindings = [$textId];
        $archived = Connection::preparedExecute(
            "UPDATE texts SET TxArchivedAt = NOW(), TxPosition = 0, TxAudioPosition = 0
            WHERE TxID = ? AND TxArchivedAt IS NULL"
            . UserScopedQuery::forTablePrepared('texts', $bindings),
            $bindings
        );

        Maintenance::adjustAutoIncrement('sentences', 'SeID');

        return ['sentences' => $count2, 'textItems' => $count3, 'archived' => $archived];
    }

    /**
     * Archive multiple texts.
     *
     * @param array $textIds Array of text IDs
     *
     * @return array{count: int} Count of archived texts
     */
    public function archiveMultiple(array $textIds): array
    {
        if (empty($textIds)) {
            return ['count' => 0];
        }

        $ids = array_map('intval', $textIds);
        $count = 0;

        DB::beginTransaction();
        try {
            foreach ($ids as $textId) {
                // Delete parsed data
                QueryBuilder::table('word_occurrences')
                    ->where('Ti2TxID', '=', $textId)
                    ->delete();
                QueryBuilder::table('sentences')
                    ->where('SeTxID', '=', $textId)
                    ->delete();

                // Mark as archived
                $bindings = [$textId];
                $count += Connection::preparedExecute(
                    "UPDATE texts SET TxArchivedAt = NOW(), TxPosition = 0, TxAudioPosition = 0
                    WHERE TxID = ? AND TxArchivedAt IS NULL"
                    . UserScopedQuery::forTablePrepared('texts', $bindings),
                    $bindings
                );
            }

            Maintenance::adjustAutoIncrement('sentences', 'SeID');
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollback();
            throw $e;
        }

        return ['count' => $count];
    }

    /**
     * Unarchive a text (restore from archived state).
     *
     * Sets TxArchivedAt to NULL and re-parses the text.
     *
     * @param int $textId Text ID (archived)
     *
     * @return array{success: bool, textId: ?int, unarchived: int, sentences: int, textItems: int, error: ?string}
     */
    public function unarchive(int $textId): array
    {
        // Get language ID first
        $bindings = [$textId];
        $text = Connection::preparedFetchOne(
            "SELECT TxLgID, TxText FROM texts
            WHERE TxID = ? AND TxArchivedAt IS NOT NULL"
            . UserScopedQuery::forTablePrepared('texts', $bindings),
            $bindings
        );

        if ($text === null) {
            return [
                'success' => false,
                'textId' => null,
                'unarchived' => 0,
                'sentences' => 0,
                'textItems' => 0,
                'error' => 'Archived text not found'
            ];
        }

        // Unarchive
        $bindings2 = [$textId];
        $unarchived = Connection::preparedExecute(
            "UPDATE texts SET TxArchivedAt = NULL
            WHERE TxID = ? AND TxArchivedAt IS NOT NULL"
            . UserScopedQuery::forTablePrepared('texts', $bindings2),
            $bindings2
        );

        // Re-parse the text
        TextParsing::parseAndSave((string)($text['TxText'] ?? ''), (int) $text['TxLgID'], $textId);

        // Get statistics
        $bindings3 = [$textId];
        $sentenceCount = (int)Connection::preparedFetchValue(
            "SELECT COUNT(*) AS cnt FROM sentences WHERE SeTxID = ?"
            . UserScopedQuery::forTablePrepared('sentences', $bindings3, '', 'texts'),
            $bindings3,
            'cnt'
        );
        $bindings4 = [$textId];
        $itemCount = (int)Connection::preparedFetchValue(
            "SELECT COUNT(*) AS cnt FROM word_occurrences WHERE Ti2TxID = ?"
            . UserScopedQuery::forTablePrepared('word_occurrences', $bindings4, '', 'texts'),
            $bindings4,
            'cnt'
        );

        return [
            'success' => true,
            'textId' => $textId,
            'unarchived' => $unarchived,
            'sentences' => $sentenceCount,
            'textItems' => $itemCount,
            'error' => null
        ];
    }

    /**
     * Unarchive multiple texts.
     *
     * @param array $textIds Array of archived text IDs
     *
     * @return array{count: int} Count of unarchived texts
     */
    public function unarchiveMultiple(array $textIds): array
    {
        if (empty($textIds)) {
            return ['count' => 0];
        }

        $ids = array_map('intval', $textIds);
        $count = 0;

        foreach ($ids as $textId) {
            $result = $this->unarchive($textId);
            if ($result['success']) {
                $count++;
            }
        }

        return ['count' => $count];
    }
}
