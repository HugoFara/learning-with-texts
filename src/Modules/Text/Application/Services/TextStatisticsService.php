<?php

/**
 * Text Statistics Service - Word count and statistics functions.
 *
 * This service handles text statistics, word counts, and "words to do" progress tracking.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Text\Application\Services
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0 Migrated from Core/Text/text_statistics.php
 */

declare(strict_types=1);

namespace Lwt\Modules\Text\Application\Services;

use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Shared\Infrastructure\Database\Settings;
use Lwt\Shared\Infrastructure\Database\UserScopedQuery;
use Lwt\Shared\UI\Helpers\IconHelper;

/**
 * Service class for text statistics operations.
 *
 * @category Lwt
 * @package  Lwt\Modules\Text\Application\Services
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */
class TextStatisticsService
{
    /**
     * Return statistics about a list of text IDs.
     *
     * It is useful for unknown percent with this fork.
     *
     * @param int[] $textIds Array of text IDs
     *
     * @return array{
     *     total: array<int, int>, expr: array<int, int>, stat: array<int, array<int, int>>,
     *     totalu: array<int, int>, expru: array<int, int>, statu: array<int, array<int, int>>
     * }
     *               Total number of words, number of expressions, statistics, total unique,
     *               number of unique expressions, unique statistics
     */
    public function getTextWordCount(array $textIds): array
    {
        $r = array(
            // Total for text
            'total' => array(),
            'expr' => array(),
            'stat' => array(),
            // Unique words
            'totalu' => array(),
            'expru' => array(),
            'statu' => array()
        );

        if (empty($textIds)) {
            return $r;
        }

        // word_occurrences has no user column; it inherits scope from its
        // Ti2TxID -> texts FK only when joined. Without this filter a
        // caller passing arbitrary TxIDs (or mixing owned + unowned) gets
        // back word counts and status breakdowns for texts they do not
        // own. Drop unowned IDs silently — mirrors WordListService.
        $textIds = $this->filterOwnedTextIds($textIds);
        if (empty($textIds)) {
            return $r;
        }

        // Raw SQL needed for complex aggregation with DISTINCT LOWER()
        // word_occurrences inherits user context via Ti2TxID -> texts FK
        $bindings = [];
        $inClause = Connection::buildPreparedInClause($textIds, $bindings);
        $sql = "SELECT Ti2TxID AS text, COUNT(DISTINCT LOWER(Ti2Text)) AS unique_cnt,
            COUNT(LOWER(Ti2Text)) AS total
            FROM word_occurrences
            WHERE Ti2WordCount = 1 AND Ti2TxID IN {$inClause}
            GROUP BY Ti2TxID";
        $rows = Connection::preparedFetchAll($sql, $bindings);
        foreach ($rows as $record) {
            $textId = (int) $record['text'];
            $r["total"][$textId] = (int) $record['total'];
            $r["totalu"][$textId] = (int) $record['unique_cnt'];
        }

        // Raw SQL needed for complex aggregation with DISTINCT
        // word_occurrences inherits user context via Ti2TxID -> texts FK
        $bindings = [];
        $inClause = Connection::buildPreparedInClause($textIds, $bindings);
        $sql = "SELECT Ti2TxID AS text, COUNT(DISTINCT Ti2WoID) AS unique_cnt,
            COUNT(Ti2WoID) AS total
            FROM word_occurrences
            WHERE Ti2WordCount > 1 AND Ti2TxID IN {$inClause}
            GROUP BY Ti2TxID";
        $rows = Connection::preparedFetchAll($sql, $bindings);
        foreach ($rows as $record) {
            $textId = (int) $record['text'];
            $r["expr"][$textId] = (int) $record['total'];
            $r["expru"][$textId] = (int) $record['unique_cnt'];
        }

        // Raw SQL needed for complex aggregation with DISTINCT and implicit JOIN
        // word_occurrences inherits user context via Ti2TxID -> texts FK
        // words has user scope (WoUsID), need to apply user filtering
        $bindings = [];
        $inClause = Connection::buildPreparedInClause($textIds, $bindings);
        $sql = "SELECT Ti2TxID AS text, COUNT(DISTINCT Ti2WoID) AS unique_cnt,
            COUNT(Ti2WoID) AS total, WoStatus AS status
            FROM word_occurrences, words
            WHERE Ti2WoID != 0 AND Ti2TxID IN {$inClause} AND Ti2WoID = WoID"
            . UserScopedQuery::forTablePrepared('words', $bindings, 'words') .
            " GROUP BY Ti2TxID, WoStatus";
        $rows = Connection::preparedFetchAll($sql, $bindings);
        foreach ($rows as $record) {
            $textId = (int) $record['text'];
            $status = (int) $record['status'];
            $r["stat"][$textId][$status] = (int) $record['total'];
            $r["statu"][$textId][$status] = (int) $record['unique_cnt'];
        }

        return $r;
    }

    /**
     * Return the number of words left to do in this text.
     *
     * @param int $textId Text ID
     *
     * @return int Number of words
     */
    public function getTodoWordsCount(int $textId): int
    {
        // Verify ownership: word_occurrences has no user column, so a
        // non-owner could otherwise read the "words left to learn" count
        // for any text by guessing the TxID.
        if (!$this->ownsText($textId)) {
            return 0;
        }
        // Raw SQL needed for COUNT(DISTINCT LOWER())
        // word_occurrences inherits user context via Ti2TxID -> texts FK
        /** @var int|string|null $count */
        $count = Connection::preparedFetchValue(
            "SELECT COUNT(DISTINCT LOWER(Ti2Text)) AS cnt
            FROM word_occurrences
            WHERE Ti2WordCount=1 AND Ti2WoID IS NULL AND Ti2TxID=?",
            [$textId],
            'cnt'
        );
        if ($count === null) {
            return 0;
        }
        return (int) $count;
    }

    /**
     * Check if the current user owns the given text.
     *
     * @param int $textId The text ID to check
     */
    private function ownsText(int $textId): bool
    {
        return QueryBuilder::table('texts')
            ->where('TxID', '=', $textId)
            ->count() > 0;
    }

    /**
     * Filter text IDs down to those the current user owns.
     *
     * Mirrors WordListService::filterOwnedWordIds — single user-scoped
     * SELECT, return surviving IDs.
     *
     * @param int[] $textIds Array of text IDs
     *
     * @return int[] Owned IDs (subset of $textIds)
     */
    private function filterOwnedTextIds(array $textIds): array
    {
        if (empty($textIds)) {
            return [];
        }
        $bindings = [];
        $inClause = Connection::buildPreparedInClause($textIds, $bindings);
        $userScope = UserScopedQuery::forTablePrepared('texts', $bindings);
        if ($userScope === '') {
            return array_values(array_map('intval', $textIds));
        }
        $rows = Connection::preparedFetchAll(
            'SELECT TxID FROM texts WHERE TxID IN ' . $inClause . $userScope,
            $bindings
        );
        $owned = [];
        foreach ($rows as $row) {
            $owned[] = (int) $row['TxID'];
        }
        return $owned;
    }

    /**
     * Prepare HTML interactions for the words left to do in this text.
     *
     * @param int $textId Text ID
     *
     * @return string HTML result
     *
     * @since 2.7.0-fork Adapted to use LibreTranslate dictionary as well.
     */
    public function getTodoWordsContent(int $textId): string
    {
        $c = $this->getTodoWordsCount($textId);
        if ($c <= 0) {
            return '<span title="No unknown word remaining" class="status0 word-count-badge">' .
            $c . '</span>';
        }

        // Get language codes directly from language columns
        $bindings = [$textId];
        $sql = "SELECT LgSourceLang, LgTargetLang
            FROM languages, texts
            WHERE LgID = TxLgID and TxID = ?"
            . UserScopedQuery::forTablePrepared('languages', $bindings, 'languages')
            . UserScopedQuery::forTablePrepared('texts', $bindings, 'texts');
        $langRow = Connection::preparedFetchOne($sql, $bindings);
        $sl = (string)($langRow['LgSourceLang'] ?? '');
        $tl = (string)($langRow['LgTargetLang'] ?? '');

        $bulkTranslateUrl = 'bulk_translate_words.php?tid=' . $textId .
            '&offset=0&sl=' . $sl . '&tl=' . $tl;
        $res = '<span title="Number of unknown words" class="status0 word-count-badge">' .
        $c . '</span>' .
        IconHelper::render('file-down', [
            'class' => 'bulk-translate-icon',
            'data-action' => 'bulk-translate',
            'data-url' => htmlspecialchars($bulkTranslateUrl, ENT_QUOTES, 'UTF-8'),
            'title' => 'Lookup New Words',
            'alt' => 'Lookup New Words'
        ]);

        $show_buttons = (int) Settings::getWithDefault('set-words-to-do-buttons');
        if ($show_buttons != 2) {
            $res .= '<input type="button" data-action="know-all" data-text-id="' . $textId .
            '" value="Set All to Known" />';
        }
        if ($show_buttons != 1) {
            $res .= '<input type="button" data-action="ignore-all" data-text-id="' . $textId .
            '" value="Ignore All" />';
        }
        return $res;
    }
}
