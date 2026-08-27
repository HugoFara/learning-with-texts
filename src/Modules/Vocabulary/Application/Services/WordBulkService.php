<?php

/**
 * Word Bulk Service - Batch operations on multiple words
 *
 * PHP version 8.1
 *
 * @category Lwt
 * @package  Lwt\Modules\Vocabulary\Application\Services
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Vocabulary\Application\Services;

use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Database\DB;
use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Shared\Infrastructure\Database\UserScopedQuery;

/**
 * Service for batch operations on multiple words.
 *
 * Handles:
 * - Bulk deletion
 * - Bulk status updates
 * - Bulk text transformations
 * - Bulk term saving
 *
 * @since 3.0.0
 */
class WordBulkService
{
    /**
     * Delete multiple words.
     *
     * @param int[] $wordIds Array of word IDs to delete
     *
     * @return int Number of deleted words
     */
    public function deleteMultiple(array $wordIds): int
    {
        if (empty($wordIds)) {
            return 0;
        }

        // Restrict to IDs the caller owns before touching word_occurrences.
        // That table has no UsID column, so a whereIn on Ti2WoID alone would
        // delete another user's multi-word occurrences — and because the
        // words delete below *is* scoped, their terms would survive, leaving
        // a silent partial corruption rather than a visible failure.
        // WordListService::deleteByIdList() gates the same pair this way.
        $ids = $this->filterOwnedWordIds($wordIds);
        if (empty($ids)) {
            return 0;
        }

        // Delete multi-word text items first (before word deletion triggers FK SET NULL)
        QueryBuilder::table('word_occurrences')
            ->where('Ti2WordCount', '>', 1)
            ->whereIn('Ti2WoID', $ids)
            ->deletePrepared();

        // Delete words - FK constraints handle:
        // - Single-word word_occurrences.Ti2WoID set to NULL (ON DELETE SET NULL)
        // - word_tag_map deleted (ON DELETE CASCADE)
        $count = QueryBuilder::table('words')
            ->whereIn('WoID', $ids)
            ->deletePrepared();

        return $count;
    }

    /**
     * Narrow a list of word IDs to the ones the current user owns.
     *
     * `words` is user-scoped, so this is the bridge that lets an unscoped
     * child table (word_occurrences) be written safely.
     *
     * @param int[] $ids Candidate word IDs
     *
     * @return list<int> The subset the caller owns; all of them when
     *                   multi-user mode is off
     */
    private function filterOwnedWordIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        /** @var array<int, mixed> $bindings */
        $bindings = [];
        $inClause = Connection::buildPreparedInClause(array_map('intval', $ids), $bindings);
        $userScope = UserScopedQuery::forTablePrepared('words', $bindings);
        if ($userScope === '') {
            return array_values(array_map('intval', $ids));
        }

        $rows = Connection::preparedFetchAll(
            'SELECT WoID FROM words WHERE WoID in ' . $inClause . $userScope,
            $bindings
        );

        $owned = [];
        foreach ($rows as $row) {
            $owned[] = (int) $row['WoID'];
        }
        return $owned;
    }

    /**
     * Update status for multiple words.
     *
     * @param int[] $wordIds  Array of word IDs
     * @param int   $status   New status value (1-5, 98, 99)
     * @param bool  $relative If true, change status by +1 or -1
     *
     * @return int Number of updated words
     */
    public function updateStatusMultiple(array $wordIds, int $status, bool $relative = false): int
    {
        if (empty($wordIds)) {
            return 0;
        }

        /** @var array<int, int> $ids */
        $ids = array_map('intval', $wordIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        if ($relative) {
            if ($status > 0) {
                // Increment status
                $sql = "UPDATE words
                        SET WoStatus = WoStatus + 1, WoStatusChanged = NOW()
                        WHERE WoStatus IN (1,2,3,4) AND WoID IN ({$placeholders})"
                        . UserScopedQuery::forTablePrepared('words', $ids);
                return Connection::preparedExecute($sql, $ids);
            } else {
                // Decrement status
                $sql = "UPDATE words
                        SET WoStatus = WoStatus - 1, WoStatusChanged = NOW()
                        WHERE WoStatus IN (2,3,4,5) AND WoID IN ({$placeholders})"
                        . UserScopedQuery::forTablePrepared('words', $ids);
                return Connection::preparedExecute($sql, $ids);
            }
        }

        // Absolute status
        /** @var array<int, int> $bindings */
        $bindings = array_merge([$status], $ids);
        $sql = "UPDATE words
                SET WoStatus = ?, WoStatusChanged = NOW()
                WHERE WoID IN ({$placeholders})"
                . UserScopedQuery::forTablePrepared('words', $bindings);

        return Connection::preparedExecute($sql, $bindings);
    }

    /**
     * Update status changed date for multiple words.
     *
     * @param int[] $wordIds Array of word IDs
     *
     * @return int Number of updated words
     */
    public function updateStatusDateMultiple(array $wordIds): int
    {
        if (empty($wordIds)) {
            return 0;
        }

        /** @var array<int, int> $ids */
        $ids = array_map('intval', $wordIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $sql = "UPDATE words
                SET WoStatusChanged = NOW()
                WHERE WoID IN ({$placeholders})"
                . UserScopedQuery::forTablePrepared('words', $ids);

        return Connection::preparedExecute($sql, $ids);
    }

    /**
     * Delete sentences for multiple words.
     *
     * @param int[] $wordIds Array of word IDs
     *
     * @return int Number of updated words
     */
    public function deleteSentencesMultiple(array $wordIds): int
    {
        if (empty($wordIds)) {
            return 0;
        }

        /** @var array<int, int> $ids */
        $ids = array_map('intval', $wordIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $sql = "UPDATE words SET WoSentence = NULL WHERE WoID IN ({$placeholders})"
            . UserScopedQuery::forTablePrepared('words', $ids);

        return Connection::preparedExecute($sql, $ids);
    }

    /**
     * Convert words to lowercase.
     *
     * @param int[] $wordIds Array of word IDs
     *
     * @return int Number of updated words
     */
    public function toLowercaseMultiple(array $wordIds): int
    {
        if (empty($wordIds)) {
            return 0;
        }

        /** @var array<int, int> $ids */
        $ids = array_map('intval', $wordIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $sql = "UPDATE words SET WoText = WoTextLC WHERE WoID IN ({$placeholders})"
            . UserScopedQuery::forTablePrepared('words', $ids);

        return Connection::preparedExecute($sql, $ids);
    }

    /**
     * Capitalize words.
     *
     * @param int[] $wordIds Array of word IDs
     *
     * @return int Number of updated words
     */
    public function capitalizeMultiple(array $wordIds): int
    {
        if (empty($wordIds)) {
            return 0;
        }

        /** @var array<int, int> $ids */
        $ids = array_map('intval', $wordIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $sql = "UPDATE words
             SET WoText = CONCAT(UPPER(LEFT(WoTextLC, 1)), SUBSTRING(WoTextLC, 2))
             WHERE WoID IN ({$placeholders})"
             . UserScopedQuery::forTablePrepared('words', $ids);

        return Connection::preparedExecute($sql, $ids);
    }

    /**
     * Save multiple terms in bulk.
     *
     * Used by the bulk translate feature to save multiple words at once.
     *
     * @param array<int, array{lg: int, text: string, status: int, trans?: string}> $terms Array of term data
     *
     * @return int The max word ID before insertion (for finding new words)
     */
    public function bulkSaveTerms(array $terms): int
    {
        $bindings = [];
        /** @var int $max */
        $max = (int) Connection::preparedFetchValue(
            "SELECT COALESCE(MAX(WoID), 0) AS max_id FROM words WHERE 1=1"
            . UserScopedQuery::forTablePrepared('words', $bindings),
            $bindings,
            'max_id'
        );

        if (empty($terms)) {
            return $max;
        }


        DB::beginTransaction();
        try {
            // Insert each term using prepared statements for safety
            foreach ($terms as $row) {
                $trans = (!isset($row['trans']) || $row['trans'] == '') ? '*' : $row['trans'];
                $textlc = mb_strtolower($row['text'], 'UTF-8');

                $bindings = [$row['lg'], $textlc, $row['text'], $row['status'], $trans];
                $sql = "INSERT INTO words (
                        WoLgID, WoTextLC, WoText, WoStatus, WoTranslation, WoSentence,
                        WoRomanization, WoStatusChanged"
                        . UserScopedQuery::insertColumn('words')
                    . ") VALUES (?, ?, ?, ?, ?, '', '', NOW()"
                        . UserScopedQuery::insertValuePrepared('words', $bindings)
                    . ")";

                Connection::preparedExecute($sql, $bindings);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollback();
            throw $e;
        }

        return $max;
    }

    /**
     * Get newly created words after bulk insert.
     *
     * @param int $maxWoId The max word ID before insertion
     *
     * @return array<int, array<string, mixed>> Array of rows with WoID, WoTextLC, WoStatus, WoTranslation
     */
    public function getNewWordsAfter(int $maxWoId): array
    {
        $bindings = [$maxWoId];
        return Connection::preparedFetchAll(
            "SELECT WoID, WoTextLC, WoStatus, WoTranslation
             FROM words
             WHERE WoID > ?"
             . UserScopedQuery::forTablePrepared('words', $bindings),
            $bindings
        );
    }
}
