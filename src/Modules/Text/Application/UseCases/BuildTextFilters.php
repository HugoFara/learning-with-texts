<?php

/**
 * Build Text Filters Use Case
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

/**
 * Use case for building text query filters.
 *
 * Constructs WHERE and HAVING clauses for text list queries
 * based on search criteria and tag filters.
 *
 * @since 3.0.0
 */
class BuildTextFilters
{
    /**
     * Build WHERE clause for language filtering.
     *
     * @param string|int $langId Language ID (empty string or 0 for no filter)
     *
     * @return array{clause: string, params: array} SQL WHERE clause and parameters
     */
    public function buildLangWhereClause(string|int $langId): array
    {
        if ($langId === '' || $langId === 0) {
            return ['clause' => '', 'params' => []];
        }
        return ['clause' => ' AND TxLgID = ?', 'params' => [(int)$langId]];
    }

    /**
     * Build WHERE clause for text query filtering.
     *
     * @param string $query       Search query string
     * @param string $queryMode   Query mode ('title,text', 'title', 'text')
     * @param string $regexMode   Regex mode ('' for LIKE, 'r' for RLIKE)
     * @param string $tablePrefix Column prefix ('Tx' for texts, 'At' for archived)
     *
     * @return array{clause: string, params: array} SQL WHERE clause and parameters
     */
    public function buildQueryWhereClause(
        string $query,
        string $queryMode,
        string $regexMode,
        string $tablePrefix = 'Tx'
    ): array {
        if ($query === '') {
            return ['clause' => '', 'params' => []];
        }

        $titleCol = $tablePrefix . 'Title';
        $textCol = $tablePrefix . 'Text';

        $searchValue = $regexMode === ''
            ? str_replace("*", "%", mb_strtolower($query, 'UTF-8'))
            : $query;
        $operator = $regexMode . 'LIKE';

        switch ($queryMode) {
            case 'title,text':
                return [
                    'clause' => " AND ({$titleCol} {$operator} ? OR {$textCol} {$operator} ?)",
                    'params' => [$searchValue, $searchValue]
                ];
            case 'title':
                return [
                    'clause' => " AND ({$titleCol} {$operator} ?)",
                    'params' => [$searchValue]
                ];
            case 'text':
                return [
                    'clause' => " AND ({$textCol} {$operator} ?)",
                    'params' => [$searchValue]
                ];
            default:
                return [
                    'clause' => " AND ({$titleCol} {$operator} ? OR {$textCol} {$operator} ?)",
                    'params' => [$searchValue, $searchValue]
                ];
        }
    }

    /**
     * Build WHERE clause for archived text query filtering.
     *
     * Note: Archived texts are now stored in the texts table with TxArchivedAt set,
     * so they use the same Tx column prefix as active texts.
     *
     * @param string $query     Search query string
     * @param string $queryMode Query mode
     * @param string $regexMode Regex mode
     *
     * @return array{clause: string, params: array}
     */
    public function buildArchivedQueryWhereClause(
        string $query,
        string $queryMode,
        string $regexMode
    ): array {
        return $this->buildQueryWhereClause($query, $queryMode, $regexMode, 'Tx');
    }

    /**
     * Build HAVING clause for tag filtering.
     *
     * @param string|int $tag1     First tag filter (must be numeric or empty)
     * @param string|int $tag2     Second tag filter (must be numeric or empty)
     * @param string     $tag12    AND/OR operator
     * @param string     $tagIdCol Tag ID column (AgT2ID for archived, TtT2ID for active)
     *
     * @return string SQL HAVING clause
     */
    public function buildTagHavingClause(
        string|int $tag1,
        string|int $tag2,
        string $tag12,
        string $tagIdCol = 'TtT2ID'
    ): string {
        if ($tag1 === '' && $tag2 === '') {
            return '';
        }

        // Sanitize tag IDs to prevent SQL injection - cast to int for safety
        // Non-numeric strings become 0, which won't match any valid tag ID
        $tag1Int = ($tag1 !== '' && is_numeric($tag1)) ? (int)$tag1 : null;
        $tag2Int = ($tag2 !== '' && is_numeric($tag2)) ? (int)$tag2 : null;

        $whTag1 = null;
        $whTag2 = null;

        if ($tag1Int !== null) {
            if ($tag1Int === -1) {
                $whTag1 = "GROUP_CONCAT({$tagIdCol}) IS NULL";
            } else {
                $whTag1 = "CONCAT('/', GROUP_CONCAT({$tagIdCol} SEPARATOR '/'), '/') LIKE '%/{$tag1Int}/%'";
            }
        }

        if ($tag2Int !== null) {
            if ($tag2Int === -1) {
                $whTag2 = "GROUP_CONCAT({$tagIdCol}) IS NULL";
            } else {
                $whTag2 = "CONCAT('/', GROUP_CONCAT({$tagIdCol} SEPARATOR '/'), '/') LIKE '%/{$tag2Int}/%'";
            }
        }

        if ($tag1Int !== null && $tag2Int === null) {
            return " HAVING ({$whTag1})";
        }
        if ($tag2Int !== null && $tag1Int === null) {
            return " HAVING ({$whTag2})";
        }
        if ($tag1Int === null && $tag2Int === null) {
            return '';
        }

        $operator = $tag12 ? 'AND' : 'OR';
        return " HAVING (({$whTag1}) {$operator} ({$whTag2}))";
    }

    /**
     * Build HAVING clause for tag filtering (parameterized version).
     *
     * Returns {clause, params} like the query WHERE clause builders.
     *
     * @param string|int $tag1     First tag filter (must be numeric or empty)
     * @param string|int $tag2     Second tag filter (must be numeric or empty)
     * @param string     $tag12    AND/OR operator
     * @param string     $tagIdCol Tag ID column (TtT2ID for active/archived)
     *
     * @return array{clause: string, params: array} SQL HAVING clause and parameters
     */
    public function buildTagHavingClausePrepared(
        string|int $tag1,
        string|int $tag2,
        string $tag12,
        string $tagIdCol = 'TtT2ID'
    ): array {
        if ($tag1 === '' && $tag2 === '') {
            return ['clause' => '', 'params' => []];
        }

        $tag1Int = ($tag1 !== '' && is_numeric($tag1)) ? (int)$tag1 : null;
        $tag2Int = ($tag2 !== '' && is_numeric($tag2)) ? (int)$tag2 : null;

        $whTag1 = null;
        $whTag2 = null;
        $params = [];

        if ($tag1Int !== null) {
            if ($tag1Int === -1) {
                $whTag1 = "GROUP_CONCAT({$tagIdCol}) IS NULL";
            } else {
                $whTag1 = "CONCAT('/', GROUP_CONCAT({$tagIdCol} SEPARATOR '/'), '/') LIKE CONCAT('%/', ?, '/%')";
                $params[] = $tag1Int;
            }
        }

        if ($tag2Int !== null) {
            if ($tag2Int === -1) {
                $whTag2 = "GROUP_CONCAT({$tagIdCol}) IS NULL";
            } else {
                $whTag2 = "CONCAT('/', GROUP_CONCAT({$tagIdCol} SEPARATOR '/'), '/') LIKE CONCAT('%/', ?, '/%')";
                $params[] = $tag2Int;
            }
        }

        if ($tag1Int !== null && $tag2Int === null) {
            return ['clause' => " HAVING ({$whTag1})", 'params' => $params];
        }
        if ($tag2Int !== null && $tag1Int === null) {
            return ['clause' => " HAVING ({$whTag2})", 'params' => $params];
        }
        if ($tag1Int === null && $tag2Int === null) {
            return ['clause' => '', 'params' => []];
        }

        $operator = $tag12 ? 'AND' : 'OR';
        return ['clause' => " HAVING (({$whTag1}) {$operator} ({$whTag2}))", 'params' => $params];
    }

    /**
     * Build HAVING clause for archived text tag filtering.
     *
     * Note: Archived texts now use the same text_tag_map table as active texts,
     * so they use the same TtT2ID column.
     *
     * @param string|int $tag1  First tag filter
     * @param string|int $tag2  Second tag filter
     * @param string     $tag12 AND/OR operator
     *
     * @return string SQL HAVING clause
     */
    public function buildArchivedTagHavingClause($tag1, $tag2, string $tag12): string
    {
        return $this->buildTagHavingClause($tag1, $tag2, $tag12, 'TtT2ID');
    }

    /**
     * Build HAVING clause for active text tag filtering.
     *
     * @param string|int $tag1  First tag filter
     * @param string|int $tag2  Second tag filter
     * @param string     $tag12 AND/OR operator
     *
     * @return string SQL HAVING clause
     */
    public function buildTextTagHavingClause($tag1, $tag2, string $tag12): string
    {
        return $this->buildTagHavingClause($tag1, $tag2, $tag12, 'TtT2ID');
    }

    /**
     * Validate regex query (test if regex is valid).
     *
     * @param string $query     Query string
     * @param string $regexMode Regex mode
     *
     * @return bool True if valid, false if invalid
     */
    public function validateRegexQuery(string $query, string $regexMode): bool
    {
        if ($query === '' || $regexMode === '') {
            return true;
        }

        try {
            $stmt = Connection::prepare('SELECT "test" RLIKE ?');
            $stmt->bind('s', $query)->execute();
            return true;
        } catch (\Exception $e) {
            error_log('BuildTextFilters::isValidRegex: ' . $e->getMessage());
            return false;
        }
    }
}
