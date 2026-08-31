<?php

/**
 * Word List Export Builder - Builds SQL queries for word list exports
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

use Lwt\Shared\Infrastructure\Database\Connection;

/**
 * Builds SQL queries for word list exports (Anki, TSV, flexible, test).
 *
 * Each method returns an array with 'sql' and 'params' keys for
 * use with prepared statements.
 *
 * @category   Lwt
 * @package    Lwt\Modules\Vocabulary\Application\Services
 * @author     HugoFara <git@hugofara.net>
 * @license    Unlicense <http://unlicense.org/>
 * @link       https://hugofara.github.io/lwt/developer/api
 * @since      3.0.0
 */
class WordListExportBuilder
{
    /**
     * Get Anki export SQL for selected words.
     *
     * @param int[]  $ids          Array of word IDs (empty for filter-based export)
     * @param string $textId       Text ID filter (comma-separated, empty for no filter)
     * @param string $whLang       Language condition (with ? placeholders)
     * @param string $whStat       Status condition
     * @param string $whQuery      Query condition (with ? placeholders)
     * @param string $whTag        Tag condition (with ? placeholders)
     * @param array  $filterParams Merged binding parameters for filter conditions
     *
     * @return array{sql: string, params: array} SQL query and parameters
     */
    public function getAnkiExportSql(
        array $ids,
        string $textId,
        string $whLang,
        string $whStat,
        string $whQuery,
        string $whTag,
        array $filterParams = []
    ): array {
        $ankiSelect = 'select distinct WoID, LgRightToLeft,
            LgRegexpWordCharacters, LgName, WoText, WoTranslation,
            WoRomanization, WoSentence,
            ifnull(group_concat(distinct TgText order by TgText separator \' \'),\'\') as taglist';
        $ankiFrom = 'from ((words left JOIN word_tag_map ON WoID = WtWoID)
            left join tags on TgID = WtTgID), languages';
        $ankiWhere = 'WoLgID = LgID AND WoTranslation != \'*\'
            AND WoTranslation != \'\' AND WoTranslation IS NOT NULL
            AND WoSentence IS NOT NULL AND WoSentence != \'\'
            AND (WoSentence LIKE CONCAT(\'%{\',WoText,\'}%\')
                 OR (WoSentence LIKE CONCAT(\'%\',WoText,\'%\') AND CHAR_LENGTH(WoSentence) > CHAR_LENGTH(WoText)))';

        if (!empty($ids)) {
            $params = [];
            $inClause = Connection::buildPreparedInClause($ids, $params);

            return [
                'sql' => "$ankiSelect $ankiFrom
                    where $ankiWhere AND WoTranslation != ''
                    and WoID in $inClause group by WoID",
                'params' => $params,
            ];
        }

        if ($textId == '') {
            return [
                'sql' => "$ankiSelect $ankiFrom
                    where $ankiWhere $whLang $whStat $whQuery
                    group by WoID $whTag",
                'params' => $filterParams,
            ];
        }

        $params = [];
        $textIds = array_map('intval', explode(',', $textId));
        $inClause = Connection::buildPreparedInClause($textIds, $params);
        $params = array_values(array_merge($params, $filterParams));

        return [
            'sql' => "$ankiSelect $ankiFrom, word_occurrences
                where Ti2LgID = WoLgID and Ti2WoID = WoID
                and Ti2TxID in $inClause and $ankiWhere
                $whLang $whStat $whQuery group by WoID $whTag",
            'params' => $params,
        ];
    }

    /**
     * Get TSV export SQL for selected words.
     *
     * @param int[]  $ids          Array of word IDs (empty for filter-based export)
     * @param string $textId       Text ID filter (comma-separated, empty for no filter)
     * @param string $whLang       Language condition (with ? placeholders)
     * @param string $whStat       Status condition
     * @param string $whQuery      Query condition (with ? placeholders)
     * @param string $whTag        Tag condition (with ? placeholders)
     * @param array  $filterParams Merged binding parameters for filter conditions
     *
     * @return array{sql: string, params: array} SQL query and parameters
     */
    public function getTsvExportSql(
        array $ids,
        string $textId,
        string $whLang,
        string $whStat,
        string $whQuery,
        string $whTag,
        array $filterParams = []
    ): array {
        $tsvSelect = 'select distinct WoID, LgName, WoText, WoTranslation,
            WoRomanization, WoSentence, WoStatus,
            ifnull(group_concat(distinct TgText order by TgText separator \' \'),\'\') as taglist';
        $tsvFrom = 'from ((words left JOIN word_tag_map ON WoID = WtWoID)
            left join tags on TgID = WtTgID), languages';

        if (!empty($ids)) {
            $params = [];
            $inClause = Connection::buildPreparedInClause($ids, $params);

            return [
                'sql' => "$tsvSelect $tsvFrom
                    where WoLgID = LgID and WoID in $inClause group by WoID",
                'params' => $params,
            ];
        }

        if ($textId == '') {
            return [
                'sql' => "$tsvSelect $tsvFrom
                    where WoLgID = LgID $whLang $whStat $whQuery
                    group by WoID $whTag",
                'params' => $filterParams,
            ];
        }

        $params = [];
        $textIds = array_map('intval', explode(',', $textId));
        $inClause = Connection::buildPreparedInClause($textIds, $params);
        $params = array_values(array_merge($params, $filterParams));

        return [
            'sql' => "$tsvSelect $tsvFrom, word_occurrences
                where Ti2LgID = WoLgID and Ti2WoID = WoID
                and Ti2TxID in $inClause and WoLgID = LgID
                $whLang $whStat $whQuery group by WoID $whTag",
            'params' => $params,
        ];
    }

    /**
     * Get flexible export SQL for selected words.
     *
     * @param int[]  $ids          Array of word IDs (empty for filter-based export)
     * @param string $textId       Text ID filter (comma-separated, empty for no filter)
     * @param string $whLang       Language condition (with ? placeholders)
     * @param string $whStat       Status condition
     * @param string $whQuery      Query condition (with ? placeholders)
     * @param string $whTag        Tag condition (with ? placeholders)
     * @param array  $filterParams Merged binding parameters for filter conditions
     *
     * @return array{sql: string, params: array} SQL query and parameters
     */
    public function getFlexibleExportSql(
        array $ids,
        string $textId,
        string $whLang,
        string $whStat,
        string $whQuery,
        string $whTag,
        array $filterParams = []
    ): array {
        $flexSelect = 'select distinct WoID, LgName, LgExportTemplate, LgRightToLeft,
            WoText, WoTextLC, WoTranslation, WoRomanization, WoSentence, WoStatus,
            ifnull(group_concat(distinct TgText order by TgText separator \' \'),\'\') as taglist';
        $flexFrom = 'from ((words left JOIN word_tag_map ON WoID = WtWoID)
            left join tags on TgID = WtTgID), languages';

        if (!empty($ids)) {
            $params = [];
            $inClause = Connection::buildPreparedInClause($ids, $params);

            return [
                'sql' => "$flexSelect $flexFrom
                    where WoLgID = LgID and WoID in $inClause group by WoID",
                'params' => $params,
            ];
        }

        if ($textId == '') {
            return [
                'sql' => "$flexSelect $flexFrom
                    where WoLgID = LgID $whLang $whStat $whQuery
                    group by WoID $whTag",
                'params' => $filterParams,
            ];
        }

        $params = [];
        $textIds = array_map('intval', explode(',', $textId));
        $inClause = Connection::buildPreparedInClause($textIds, $params);
        $params = array_values(array_merge($params, $filterParams));

        return [
            'sql' => "$flexSelect $flexFrom, word_occurrences
                where Ti2LgID = WoLgID and Ti2WoID = WoID
                and Ti2TxID in $inClause and WoLgID = LgID
                $whLang $whStat $whQuery group by WoID $whTag",
            'params' => $params,
        ];
    }

    /**
     * Get test SQL for selected words.
     *
     * @param string $textId       Text ID filter (comma-separated, empty for no filter)
     * @param string $whLang       Language condition (with ? placeholders)
     * @param string $whStat       Status condition
     * @param string $whQuery      Query condition (with ? placeholders)
     * @param string $whTag        Tag condition (with ? placeholders)
     * @param array  $filterParams Merged binding parameters for filter conditions
     *
     * @return array{sql: string, params: array} SQL query and parameters
     */
    public function getTestWordIdsSql(
        string $textId,
        string $whLang,
        string $whStat,
        string $whQuery,
        string $whTag,
        array $filterParams = []
    ): array {
        if ($textId == '') {
            return [
                'sql' => 'select distinct WoID
                    from (words left JOIN word_tag_map ON WoID = WtWoID)
                    where (1=1) ' . $whLang . $whStat . $whQuery .
                    ' group by WoID ' . $whTag,
                'params' => $filterParams,
            ];
        }

        $params = [];
        $textIds = array_map('intval', explode(',', $textId));
        $inClause = Connection::buildPreparedInClause($textIds, $params);
        $params = array_values(array_merge($params, $filterParams));

        return [
            'sql' => 'select distinct WoID
                from (words left JOIN word_tag_map ON WoID = WtWoID),
                word_occurrences
                where Ti2LgID = WoLgID and Ti2WoID = WoID
                and Ti2TxID in ' . $inClause .
                $whLang . $whStat . $whQuery .
                ' group by WoID ' . $whTag,
            'params' => $params,
        ];
    }
}
