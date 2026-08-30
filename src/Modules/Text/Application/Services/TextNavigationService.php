<?php

/**
 * Text Navigation Service - Navigation utilities for previous/next texts.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Text\Application\Services
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0 Migrated from Core/Text/text_navigation.php
 */

declare(strict_types=1);

namespace Lwt\Modules\Text\Application\Services;

use Lwt\Shared\Infrastructure\Http\InputValidator;
use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Shared\Infrastructure\Database\UserScopedQuery;
use Lwt\Shared\Infrastructure\Database\Validation;
use Lwt\Shared\Infrastructure\Database\Settings;
use Lwt\Shared\UI\Helpers\IconHelper;

/**
 * Service class for text navigation.
 *
 * @category Lwt
 * @package  Lwt\Modules\Text\Application\Services
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */
class TextNavigationService
{
    /**
     * Build the URL for navigation by replacing {id} placeholder or appending ID.
     *
     * @param string $url    URL pattern (may contain {id} placeholder or be a query param base)
     * @param int    $textId Text ID to insert
     *
     * @return string Complete URL with text ID
     */
    private function buildNavigationUrl(string $url, int $textId): string
    {
        if (str_contains($url, '{id}')) {
            return str_replace('{id}', (string) $textId, $url);
        }
        return $url . $textId;
    }

    /**
     * Return navigation arrows to previous and next texts.
     *
     * @param int    $textId  ID of the current text
     * @param string $url     Base URL to append before $textId, or pattern with {id} placeholder
     * @param bool   $onlyAnn Restrict to annotated texts only
     * @param string $add     Some content to add before the output
     *
     * @return string Arrows to previous and next texts.
     */
    public function getPreviousAndNextTextLinks(int $textId, string $url, bool $onlyAnn, string $add): string
    {
        $params = [];

        $currentlang = Validation::language(
            InputValidator::getStringWithDb("filterlang", 'currentlanguage')
        );
        $wh_lang = '';
        if ($currentlang != '') {
            $wh_lang = ' AND TxLgID = ?';
            $params[] = $currentlang;
        }

        $currentquery = InputValidator::getString("query");
        $currentquerymode = InputValidator::getString("query_mode", 'title,text');
        $currentregexmode = Settings::getWithDefault("set-regex-mode");
        $wh_query = '';
        if ($currentquery != '') {
            $queryParam = $currentregexmode == ''
                ? str_replace("*", "%", mb_strtolower($currentquery, 'UTF-8'))
                : $currentquery;

            $likeClause = $currentregexmode . 'LIKE ?';
            switch ($currentquerymode) {
                case 'title,text':
                    $wh_query = ' AND (TxTitle ' . $likeClause . ' OR TxText ' . $likeClause . ')';
                    $params[] = $queryParam;
                    $params[] = $queryParam;
                    break;
                case 'title':
                    $wh_query = ' AND (TxTitle ' . $likeClause . ')';
                    $params[] = $queryParam;
                    break;
                case 'text':
                    $wh_query = ' AND (TxText ' . $likeClause . ')';
                    $params[] = $queryParam;
                    break;
            }
        }

        $currenttag1 = Validation::textTag(
            InputValidator::getString("tag1"),
            $currentlang
        );
        $currenttag2 = Validation::textTag(
            InputValidator::getString("tag2"),
            $currentlang
        );
        $currenttag12 = InputValidator::getString("tag12");
        $wh_tag1 = null;
        $wh_tag2 = null;
        if ($currenttag1 == '' && $currenttag2 == '') {
            $wh_tag = '';
        } else {
            if ($currenttag1 != '') {
                $tag1Int = (int)$currenttag1;
                if ($tag1Int === -1) {
                    $wh_tag1 = "group_concat(TtT2ID) IS NULL";
                } else {
                    $wh_tag1 = "concat('/',group_concat(TtT2ID separator '/'),'/') like concat('%/', ?, '/%')";
                    $params[] = $tag1Int;
                }
            }
            if ($currenttag2 != '') {
                $tag2Int = (int)$currenttag2;
                if ($tag2Int === -1) {
                    $wh_tag2 = "group_concat(TtT2ID) IS NULL";
                } else {
                    $wh_tag2 = "concat('/',group_concat(TtT2ID separator '/'),'/') like concat('%/', ?, '/%')";
                    $params[] = $tag2Int;
                }
            }
            if ($currenttag1 != '' && $currenttag2 == '') {
                $wh_tag = " having (" . (string)$wh_tag1 . ') ';
            } elseif ($currenttag2 != '' && $currenttag1 == '') {
                $wh_tag = " having (" . (string)$wh_tag2 . ') ';
            } else {
                $operator = $currenttag12 ? ') AND (' : ') OR (';
                $wh_tag = " having ((" . (string)$wh_tag1 . $operator . (string)$wh_tag2 . ')) ';
            }
        }

        $currentsort = InputValidator::getIntWithDb("sort", 'currenttextsort', 1);
        $sorts = array('TxTitle','TxID desc','TxID asc');
        $lsorts = count($sorts);
        if ($currentsort < 1) {
            $currentsort = 1;
        }
        if ($currentsort > $lsorts) {
            $currentsort = $lsorts;
        }

        $textScope = UserScopedQuery::forTablePrepared('texts', $params);
        if ($onlyAnn) {
            $sql = 'SELECT TxID
            FROM (
                (texts
                    LEFT JOIN text_tag_map ON TxID = TtTxID
                )
                LEFT JOIN text_tags ON T2ID = TtT2ID
            ), languages
            WHERE LgID = TxLgID AND LENGTH(TxAnnotatedText) > 0 '
            . $wh_lang . $wh_query . $textScope . '
            GROUP BY TxID ' . $wh_tag . '
            ORDER BY ' . $sorts[$currentsort - 1];
        } else {
            $sql = 'SELECT TxID
            FROM (
                (texts
                    LEFT JOIN text_tag_map ON TxID = TtTxID
                )
                LEFT JOIN text_tags ON T2ID = TtT2ID
            ), languages
            WHERE LgID = TxLgID ' . $wh_lang . $wh_query . $textScope . '
            GROUP BY TxID ' . $wh_tag . '
            ORDER BY ' . $sorts[$currentsort - 1];
        }

        $list = array(0);
        $rows = Connection::preparedFetchAll($sql, $params);
        foreach ($rows as $record) {
            array_push($list, (int) $record['TxID']);
        }
        array_push($list, 0);
        $listlen = count($list);
        for ($i = 1; $i < $listlen - 1; $i++) {
            if ($list[$i] == $textId) {
                /** @var int<0, max> $prevIdx */
                $prevIdx = $i - 1;
                $prevId = $list[$prevIdx];
                $nextId = $list[$i + 1];
                if ($prevId !== 0) {
                    $title = htmlspecialchars(
                        self::getTextTitle($prevId),
                        ENT_QUOTES,
                        'UTF-8'
                    );
                    $icon = IconHelper::render(
                        'circle-chevron-left',
                        [
                            'title' => 'Previous Text: ' . $title,
                            'alt' => 'Previous Text: ' . $title
                        ]
                    );
                    $prevUrl = $this->buildNavigationUrl(
                        $url,
                        $prevId
                    );
                    $prev = '<a href="' . $prevUrl
                        . '" target="_top">' . $icon . '</a>';
                } else {
                    $prev = IconHelper::render(
                        'circle-chevron-left',
                        [
                            'title' => 'No Previous Text',
                            'alt' => 'No Previous Text',
                            'class' => 'icon-muted'
                        ]
                    );
                }
                if ($nextId !== 0) {
                    $title = htmlspecialchars(
                        self::getTextTitle($nextId),
                        ENT_QUOTES,
                        'UTF-8'
                    );
                    $icon = IconHelper::render(
                        'circle-chevron-right',
                        [
                            'title' => 'Next Text: ' . $title,
                            'alt' => 'Next Text: ' . $title
                        ]
                    );
                    $nextUrl = $this->buildNavigationUrl(
                        $url,
                        $nextId
                    );
                    $next = '<a href="' . $nextUrl
                        . '" target="_top">' . $icon . '</a>';
                } else {
                    $next = IconHelper::render(
                        'circle-chevron-right',
                        [
                            'title' => 'No Next Text',
                            'alt' => 'No Next Text',
                            'class' => 'icon-muted'
                        ]
                    );
                }
                return $add . $prev . ' ' . $next;
            }
        }
        $prevIcon = IconHelper::render(
            'circle-chevron-left',
            ['title' => 'No Previous Text', 'alt' => 'No Previous Text', 'class' => 'icon-muted']
        );
        $nextIcon = IconHelper::render(
            'circle-chevron-right',
            ['title' => 'No Next Text', 'alt' => 'No Next Text', 'class' => 'icon-muted']
        );
        return $add . $prevIcon . ' ' . $nextIcon;
    }

    /**
     * Get the title of a text by its ID.
     *
     * @param int $textId Text ID
     *
     * @return string Text title, or empty string if not found
     */
    public static function getTextTitle(int $textId): string
    {
        /**
 * @var string|null $result
*/
        $result = QueryBuilder::table('texts')
            ->where('TxID', '=', $textId)
            ->valuePrepared('TxTitle');
        return $result ?? '';
    }
}
