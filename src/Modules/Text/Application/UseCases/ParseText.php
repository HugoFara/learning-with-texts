<?php

/**
 * Parse Text Use Case
 *
 * PHP version 8.1
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

use Lwt\Modules\Text\Domain\ParseCoverage;
use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Database\TextParsing;
use Lwt\Shared\Infrastructure\Database\UserScopedQuery;

/**
 * Use case for text parsing operations.
 *
 * Handles text parsing preview and validation without saving,
 * useful for checking text before import.
 *
 * @since 3.0.0
 */
class ParseText
{
    /**
     * Check/preview text parsing without saving.
     *
     * @param string $text       Text content to parse
     * @param int    $languageId Language ID
     *
     * @return array{sentences: int, words: int, unknownPercent: float, preview: string,
     *         warning: string} `warning` is a ParseCoverage verdict, 'ok' when fine
     */
    public function execute(string $text, int $languageId): array
    {
        // Get language parsing settings
        $bindings = [$languageId];
        $langSettings = Connection::preparedFetchOne(
            "SELECT LgName, LgRemoveSpaces, LgSplitEachChar,
                LgRegexpSplitSentences, LgExceptionsSplitSentences,
                LgRegexpWordCharacters
            FROM languages WHERE LgID = ?"
            . UserScopedQuery::forTablePrepared('languages', $bindings),
            $bindings
        );

        if ($langSettings === null) {
            return [
                'sentences' => 0,
                'words' => 0,
                'unknownPercent' => 100.0,
                'preview' => 'Language not found',
                'warning' => ParseCoverage::OK
            ];
        }

        // Parse text (preview only, no save)
        $result = TextParsing::checkText($text, $languageId);

        $words = $result['words'] ?? 0;

        return [
            'sentences' => $result['sentences'] ?? 0,
            'words' => $words,
            'unknownPercent' => $result['unknownPercent'] ?? 100.0,
            'preview' => $result['preview'] ?? '',
            // A language whose word characters do not fit the script parses
            // successfully into nothing; say so rather than report a zero
            'warning' => ParseCoverage::assess($words, mb_strlen($text, 'UTF-8'))
        ];
    }

    /**
     * Validate text length (max 65000 bytes for MySQL TEXT column).
     *
     * @param string $text Text to validate
     *
     * @return bool True if valid, false if too long
     */
    public function validateTextLength(string $text): bool
    {
        return strlen($text) <= 65000;
    }

    /**
     * Get text length info.
     *
     * @param string $text Text content
     *
     * @return array{bytes: int, characters: int, words: int, valid: bool}
     */
    public function getTextLengthInfo(string $text): array
    {
        $bytes = strlen($text);
        $characters = mb_strlen($text, 'UTF-8');
        $words = str_word_count($text);

        return [
            'bytes' => $bytes,
            'characters' => $characters,
            'words' => $words,
            'valid' => $bytes <= 65000
        ];
    }

    /**
     * Set term sentences for words from texts.
     *
     * Links words to sentences they appear in.
     *
     * @param array $textIds    Array of text IDs
     * @param bool  $activeOnly Only update active (non-well-known) words
     *
     * @return int Number of terms updated
     */
    public function setTermSentences(array $textIds, bool $activeOnly = false): int
    {
        if (empty($textIds)) {
            return 0;
        }

        /**
 * @var array<int, int> $ids
*/
        $ids = array_values(array_map('intval', $textIds));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        // Get words from texts
        $sql = "SELECT DISTINCT Ti2WoID, Ti2SeID
            FROM word_occurrences, sentences, texts
            WHERE Ti2TxID IN ({$placeholders})
            AND Ti2SeID = SeID
            AND Ti2TxID = TxID
            AND Ti2WoID > 0"
            . UserScopedQuery::forTablePrepared('texts', $ids);

        if ($activeOnly) {
            $sql = "SELECT DISTINCT Ti2WoID, Ti2SeID
                FROM word_occurrences, sentences, texts, words
                WHERE Ti2TxID IN ({$placeholders})
                AND Ti2SeID = SeID
                AND Ti2TxID = TxID
                AND Ti2WoID = WoID
                AND WoStatus < 98
                AND Ti2WoID > 0"
                . UserScopedQuery::forTablePrepared('texts', $ids)
                . UserScopedQuery::forTablePrepared('words', $ids);
        }

        $rows = Connection::preparedFetchAll($sql, $ids);
        $count = 0;

        foreach ($rows as $row) {
            $bindings = [(int) $row['Ti2SeID'], (int) $row['Ti2WoID']];
            Connection::preparedExecute(
                "UPDATE words SET WoSentence = ? WHERE WoID = ?"
                . UserScopedQuery::forTablePrepared('words', $bindings),
                $bindings
            );
            $count++;
        }

        return $count;
    }
}
