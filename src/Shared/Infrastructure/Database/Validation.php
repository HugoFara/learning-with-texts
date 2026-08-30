<?php

/**
 * \file
 * \brief Database ID and tag validation utilities.
 *
 * PHP version 8.2
 *
 * @category Database
 * @package  Lwt
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Shared\Infrastructure\Database;

use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Shared\Infrastructure\Database\UserScopedQuery;

/**
 * Database ID and tag validation utilities.
 *
 * Provides methods for validating language IDs, text IDs, and tag IDs
 * against the database to ensure they exist.
 *
 * @since 3.0.0
 */
class Validation
{
    /**
     * Validate a language ID.
     *
     * @param string $currentlang Language ID to validate
     *
     * @return string '' if the language is not valid, $currentlang otherwise
     */
    public static function language(string $currentlang): string
    {
        if ($currentlang == '' || !is_numeric($currentlang)) {
            return '';
        }
        // Cast to integer for safety against SQL injection
        $currentlang_int = (int)$currentlang;
        $count = QueryBuilder::table('languages')
            ->where('LgID', '=', $currentlang_int)
            ->count();
        if ($count == 0) {
            return '';
        }
        return (string)$currentlang_int;
    }

    /**
     * Validate a text ID.
     *
     * @param string $currenttext Text ID to validate
     *
     * @return string '' if the text is not valid, $currenttext otherwise
     */
    public static function text(string $currenttext): string
    {
        if ($currenttext == '' || !is_numeric($currenttext)) {
            return '';
        }
        // Cast to integer for safety against SQL injection
        $currenttext_int = (int)$currenttext;
        $count = QueryBuilder::table('texts')
            ->where('TxID', '=', $currenttext_int)
            ->count();
        if ($count == 0) {
            return '';
        }
        return (string)$currenttext_int;
    }

    /**
     * Validate a tag ID for words.
     *
     * @param string $currenttag  Tag ID to validate
     * @param string $currentlang Optional language ID filter
     *
     * @return string '' if invalid, $currenttag otherwise
     */
    public static function tag(string $currenttag, string $currentlang): string
    {
        if ($currenttag != '' && $currenttag != '-1') {
            // Sanitize inputs to prevent SQL injection
            if (!is_numeric($currenttag)) {
                return '';
            }
            $currenttag_int = (int)$currenttag;

            $bindings = [$currenttag_int];
            $lang_condition = '';
            if ($currentlang != '') {
                if (!is_numeric($currentlang)) {
                    return '';
                }
                $currentlang_int = (int)$currentlang;
                $lang_condition = " AND WoLgID = ?";
                $bindings[] = $currentlang_int;
            }

            $sql = "SELECT (
                ? IN (
                    SELECT TgID
                    FROM words, tags, word_tag_map
                    WHERE TgID = WtTgID AND WtWoID = WoID" .
                    $lang_condition .
                    " group by TgID order by TgText
                )
            ) AS tag_exists"
                . UserScopedQuery::forTablePrepared('words', $bindings);
            /** @var int|string|null $r */
            $r = Connection::preparedFetchValue($sql, $bindings, 'tag_exists');
            if ($r == 0) {
                $currenttag = '';
            }
        }
        return $currenttag;
    }

    /**
     * Validate a tag ID for archived texts.
     *
     * @param string $currenttag  Tag ID to validate
     * @param string $currentlang Optional language ID filter
     *
     * @return string '' if invalid, $currenttag otherwise
     */
    public static function archTextTag(string $currenttag, string $currentlang): string
    {
        if ($currenttag != '' && $currenttag != '-1') {
            // Sanitize inputs to prevent SQL injection
            if (!is_numeric($currenttag)) {
                return '';
            }
            $currenttag_int = (int)$currenttag;

            $bindings = [$currenttag_int];
            if ($currentlang == '') {
                $sql = "select (
                    ? in (
                        select T2ID
                        from texts,
                        text_tags,
                        text_tag_map
                        where T2ID = TtT2ID and TtTxID = TxID and TxArchivedAt IS NOT NULL
                        group by T2ID order by T2Text
                    )
                ) as value"
                    . UserScopedQuery::forTablePrepared('texts', $bindings);
            } else {
                if (!is_numeric($currentlang)) {
                    return '';
                }
                $currentlang_int = (int)$currentlang;
                $bindings[] = $currentlang_int;
                $sql = "select (
                    ? in (
                        select T2ID
                        from texts,
                        text_tags,
                        text_tag_map
                        where T2ID = TtT2ID and TtTxID = TxID and TxArchivedAt IS NOT NULL
                            and TxLgID = ?
                        group by T2ID order by T2Text
                    )
                ) as value"
                    . UserScopedQuery::forTablePrepared('texts', $bindings);
            }
            /** @var int|string|null $r */
            $r = Connection::preparedFetchValue($sql, $bindings);
            if ($r == 0) {
                $currenttag = '';
            }
        }
        return $currenttag;
    }

    /**
     * Validate a tag ID for texts.
     *
     * @param string $currenttag  Tag ID to validate
     * @param string $currentlang Optional language ID filter
     *
     * @return string '' if invalid, $currenttag otherwise
     */
    public static function textTag(string $currenttag, string $currentlang): string
    {
        if ($currenttag != '' && $currenttag != '-1') {
            // Sanitize inputs to prevent SQL injection
            if (!is_numeric($currenttag)) {
                return '';
            }
            $currenttag_int = (int)$currenttag;

            $bindings = [$currenttag_int];
            if ($currentlang == '') {
                $sql = "select (
                    ? in (
                        select T2ID
                        from texts, text_tags, text_tag_map
                        where T2ID = TtT2ID and TtTxID = TxID
                        group by T2ID
                        order by T2Text
                    )
                ) as value"
                    . UserScopedQuery::forTablePrepared('texts', $bindings);
            } else {
                if (!is_numeric($currentlang)) {
                    return '';
                }
                $currentlang_int = (int)$currentlang;
                $bindings[] = $currentlang_int;
                $sql = "select (
                    ? in (
                        select T2ID
                        from texts, text_tags, text_tag_map
                        where T2ID = TtT2ID and TtTxID = TxID and TxLgID = ?
                        group by T2ID order by T2Text
                    )
                ) as value"
                    . UserScopedQuery::forTablePrepared('texts', $bindings);
            }
            /** @var int|string|null $r */
            $r = Connection::preparedFetchValue($sql, $bindings);
            if ($r == 0) {
                $currenttag = '';
            }
        }
        return $currenttag;
    }
}
