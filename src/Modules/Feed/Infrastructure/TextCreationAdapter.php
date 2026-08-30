<?php

/**
 * Text Creation Adapter
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Feed\Infrastructure
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Feed\Infrastructure;

use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Database\DB;
use Lwt\Shared\Infrastructure\Database\Maintenance;
use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Shared\Infrastructure\Database\TextParsing;
use Lwt\Shared\Infrastructure\Database\UserScopedQuery;
use Lwt\Modules\Feed\Domain\TextCreationInterface;

/**
 * Adapter implementing TextCreationInterface.
 *
 * Provides text creation functionality for the Feed module by
 * using existing LWT infrastructure (TextParsing, QueryBuilder, etc.).
 *
 * @since 3.0.0
 */
class TextCreationAdapter implements TextCreationInterface
{
    /**
     * {@inheritdoc}
     */
    public function createText(
        int $languageId,
        string $title,
        string $text,
        string $audioUri,
        string $sourceUri,
        string $tagName
    ): int {
        DB::beginTransaction();
        try {
            // Ensure tag exists - use raw SQL for INSERT IGNORE.
            // forTablePrepared appends " AND T2UsID = ?" which is invalid
            // after a VALUES clause; inject T2UsID into the column/value
            // list instead so the row is correctly scoped to this user.
            $bindings = [$tagName];
            $userScopeColumn = '';
            $userScopeValue = '';
            $userIdForInsert = UserScopedQuery::getUserIdForInsert('text_tags');
            if ($userIdForInsert !== null) {
                $userScopeColumn = ', T2UsID';
                $userScopeValue = ', ?';
                $bindings[] = $userIdForInsert;
            }
            $sql = "INSERT IGNORE INTO text_tags (T2Text{$userScopeColumn}) VALUES (?{$userScopeValue})";
            Connection::preparedExecute($sql, $bindings);

            // Create the text
            $textId = QueryBuilder::table('texts')
                ->insertPrepared([
                    'TxLgID' => $languageId,
                    'TxTitle' => $title,
                    'TxText' => $text,
                    'TxAudioURI' => $audioUri,
                    'TxSourceURI' => $sourceUri,
                ]);

            // Parse the text into sentences and textitems
            TextParsing::parseAndSave(
                $text,
                $languageId,
                (int) $textId
            );

            // Apply tag to the text
            $bindings = [(int) $textId, $tagName];
            $sql = "INSERT INTO text_tag_map (TtTxID, TtT2ID)
                 SELECT ?, T2ID FROM text_tags
                 WHERE T2Text = ?"
                . UserScopedQuery::forTablePrepared('text_tags', $bindings);
            Connection::preparedExecute($sql, $bindings);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollback();
            throw $e;
        }

        return (int) $textId;
    }

    /**
     * {@inheritdoc}
     */
    public function archiveOldTexts(string $tagName, int $maxTexts): array
    {
        // Get all text IDs with this tag
        $bindings = [$tagName];
        $sql = "SELECT TtTxID FROM text_tag_map
             JOIN text_tags ON TtT2ID = T2ID
             WHERE T2Text = ?"
            . UserScopedQuery::forTablePrepared('text_tags', $bindings);
        $rows = Connection::preparedFetchAll($sql, $bindings);

        $textIds = [];
        foreach ($rows as $row) {
            $textIds[] = (int) $row['TtTxID'];
        }

        $stats = ['archived' => 0, 'sentences' => 0, 'textitems' => 0];

        if (count($textIds) <= $maxTexts) {
            return $stats;
        }

        // Sort by ID (oldest first) and archive the excess
        sort($textIds, SORT_NUMERIC);
        $textsToArchive = array_slice($textIds, 0, count($textIds) - $maxTexts);

        DB::beginTransaction();
        try {
            foreach ($textsToArchive as $textId) {
                // Delete textitems
                $stats['textitems'] += QueryBuilder::table('word_occurrences')
                    ->where('Ti2TxID', '=', $textId)
                    ->delete();

                // Delete sentences
                $stats['sentences'] += QueryBuilder::table('sentences')
                    ->where('SeTxID', '=', $textId)
                    ->delete();

                // Archive the text (soft delete - set TxArchivedAt)
                $bindings = [$textId];
                $sql = "UPDATE texts SET TxArchivedAt = NOW(), TxPosition = 0, TxAudioPosition = 0
                        WHERE TxID = ? AND TxArchivedAt IS NULL"
                    . UserScopedQuery::forTablePrepared('texts', $bindings);
                $archived = Connection::preparedExecute($sql, $bindings);

                if ($archived > 0) {
                    $stats['archived']++;
                }
            }

            Maintenance::adjustAutoIncrement('sentences', 'SeID');
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollback();
            throw $e;
        }

        return $stats;
    }

    /**
     * {@inheritdoc}
     */
    public function countTextsWithTag(string $tagName): int
    {
        $bindings = [$tagName];
        $sql = "SELECT COUNT(DISTINCT TtTxID) as cnt FROM text_tag_map
             JOIN text_tags ON TtT2ID = T2ID
             WHERE T2Text = ?"
            . UserScopedQuery::forTablePrepared('text_tags', $bindings);

        $row = Connection::preparedFetchOne($sql, $bindings);
        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * {@inheritdoc}
     */
    public function sourceUriExists(string $sourceUri): bool
    {
        $trimmedUri = trim($sourceUri);

        // Check texts table (includes both active and archived texts)
        return QueryBuilder::table('texts')
            ->where('TxSourceURI', '=', $trimmedUri)
            ->existsPrepared();
    }
}
