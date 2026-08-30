<?php

/**
 * Word List Filter Builder - Builds SQL filter conditions for word list queries
 *
 * PHP version 8.2
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

use Lwt\Shared\Infrastructure\Globals;
use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Modules\Vocabulary\Application\Helpers\StatusHelper;

/**
 * Builds SQL filter conditions for word list queries.
 *
 * Handles language, status, query text, and tag filter construction
 * for the word list display and export features.
 *
 * @category   Lwt
 * @package    Lwt\Modules\Vocabulary\Application\Services
 * @author     HugoFara <git@hugofara.net>
 * @license    Unlicense <http://unlicense.org/>
 * @link       https://hugofara.github.io/lwt/developer/api
 * @since      3.0.0
 */
class WordListFilterBuilder
{
    /**
     * Build query condition for language filter.
     *
     * @param string     $langId Language ID
     * @param array|null &$params Optional: Reference to params array for prepared statements
     *
     * @return string SQL condition
     */
    public function buildLangCondition(string $langId, ?array &$params = null): string
    {
        if ($langId == '') {
            return '';
        }
        if ($params !== null) {
            $params[] = (int)$langId;
            return ' and WoLgID = ?';
        }
        return ' and WoLgID=' . (int)$langId;
    }

    /**
     * Build query condition for status filter.
     *
     * @param string $status Status code
     *
     * @return string SQL condition
     */
    public function buildStatusCondition(string $status): string
    {
        if ($status == '') {
            return '';
        }
        return ' and ' . StatusHelper::makeCondition('WoStatus', (int)$status);
    }

    /**
     * Build query condition for search query with prepared statement parameters.
     *
     * NOTE: When upgrading calling code, pass a $params array by reference to get
     * parameterized queries. For backward compatibility, if $params is null,
     * this returns old-style SQL with embedded values (using mysqli_real_escape_string).
     *
     * @param string     $query     Search query
     * @param string     $queryMode Query mode (term, rom, transl, etc.)
     * @param string     $regexMode Regex mode ('' or 'r')
     * @param array|null &$params   Optional: Reference to params array for prepared statements
     *
     * @return string SQL condition (with ? placeholders if $params provided, or embedded values if not)
     */
    public function buildQueryCondition(
        string $query,
        string $queryMode,
        string $regexMode,
        ?array &$params = null
    ): string {
        if ($query === '') {
            return '';
        }

        /** @var string $queryValue */
        $queryValue = ($regexMode == '') ?
            str_replace("*", "%", mb_strtolower($query, 'UTF-8')) :
            $query;

        $op = $regexMode . 'like';

        $fieldSets = [
            'term,rom,transl' => ['WoText', "IFNULL(WoRomanization,'*')", 'WoTranslation'],
            'term,rom' => ['WoText', "IFNULL(WoRomanization,'*')"],
            'rom,transl' => ["IFNULL(WoRomanization,'*')", 'WoTranslation'],
            'term,transl' => ['WoText', 'WoTranslation'],
            'term' => ['WoText'],
            'rom' => ["IFNULL(WoRomanization,'*')"],
            'transl' => ['WoTranslation'],
        ];

        $fields = $fieldSets[$queryMode] ?? $fieldSets['term,rom,transl'];

        // If $params is provided, use prepared statements with ? placeholders
        if ($params !== null) {
            $conditions = [];
            foreach ($fields as $field) {
                $conditions[] = "{$field} {$op} ?";
                $params[] = $queryValue;
            }
            return ' and (' . implode(' or ', $conditions) . ')';
        }

        // Backward compatibility: build old-style SQL with embedded values
        // Using mysqli_real_escape_string directly instead of Escaping::toSqlSyntax()
        $dbConn = Globals::getDbConnection();
        if ($dbConn === null) {
            return '';
        }
        $escapedValue = "'" . (string) mysqli_real_escape_string($dbConn, $queryValue) . "'";

        $whQuery = "{$op} {$escapedValue}";

        switch ($queryMode) {
            case 'term,rom,transl':
                return " and (WoText $whQuery or IFNULL(WoRomanization,'*') $whQuery or WoTranslation $whQuery)";
            case 'term,rom':
                return " and (WoText $whQuery or IFNULL(WoRomanization,'*') $whQuery)";
            case 'rom,transl':
                return " and (IFNULL(WoRomanization,'*') $whQuery or WoTranslation $whQuery)";
            case 'term,transl':
                return " and (WoText $whQuery or WoTranslation $whQuery)";
            case 'term':
                return " and (WoText $whQuery)";
            case 'rom':
                return " and (IFNULL(WoRomanization,'*') $whQuery)";
            case 'transl':
                return " and (WoTranslation $whQuery)";
            default:
                return " and (WoText $whQuery or IFNULL(WoRomanization,'*') $whQuery or WoTranslation $whQuery)";
        }
    }

    /**
     * Validate a regex pattern.
     *
     * @param string $pattern The regex pattern to validate
     *
     * @return bool True if valid, false otherwise
     */
    public function validateRegexPattern(string $pattern): bool
    {
        try {
            Connection::preparedFetchValue('SELECT "test" RLIKE ?', [$pattern]);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Build tag filter condition.
     *
     * @param string     $tag1   First tag ID (must be numeric or empty)
     * @param string     $tag2   Second tag ID (must be numeric or empty)
     * @param string     $tag12  Tag logic (0=OR, 1=AND)
     * @param array|null &$params Optional: Reference to params array for prepared statements
     *
     * @return string SQL HAVING clause
     */
    public function buildTagCondition(string $tag1, string $tag2, string $tag12, ?array &$params = null): string
    {
        if ($tag1 == '' && $tag2 == '') {
            return '';
        }

        // Sanitize tag IDs to prevent SQL injection - cast to int for safety
        // Non-numeric strings become null and are ignored
        $tag1Int = ($tag1 !== '' && is_numeric($tag1)) ? (int)$tag1 : null;
        $tag2Int = ($tag2 !== '' && is_numeric($tag2)) ? (int)$tag2 : null;

        $whTag1 = null;
        $whTag2 = null;

        if ($tag1Int !== null) {
            if ($tag1Int === -1) {
                $whTag1 = "group_concat(WtTgID) IS NULL";
            } elseif ($params !== null) {
                $whTag1 = "concat('/',group_concat(WtTgID separator '/'),'/') like concat('%/', ?, '/%')";
                $params[] = $tag1Int;
            } else {
                $whTag1 = "concat('/',group_concat(WtTgID separator '/'),'/') like '%/" . $tag1Int . "/%'";
            }
        }

        if ($tag2Int !== null) {
            if ($tag2Int === -1) {
                $whTag2 = "group_concat(WtTgID) IS NULL";
            } elseif ($params !== null) {
                $whTag2 = "concat('/',group_concat(WtTgID separator '/'),'/') like concat('%/', ?, '/%')";
                $params[] = $tag2Int;
            } else {
                $whTag2 = "concat('/',group_concat(WtTgID separator '/'),'/') like '%/" . $tag2Int . "/%'";
            }
        }

        if ($whTag1 !== null && $whTag2 === null) {
            return " having (" . $whTag1 . ') ';
        } elseif ($whTag2 !== null && $whTag1 === null) {
            return " having (" . $whTag2 . ') ';
        } elseif ($whTag1 === null && $whTag2 === null) {
            return '';
        } else {
            return " having ((" . $whTag1 . ($tag12 ? ') AND (' : ') OR (') . $whTag2 . ")) ";
        }
    }
}
