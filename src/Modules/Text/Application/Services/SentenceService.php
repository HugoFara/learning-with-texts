<?php

/**
 * Sentence Service - Sentence operations and retrieval functions.
 *
 * This service contains functions for finding, formatting, and displaying
 * sentences containing specific words.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Text\Application\Services
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0 Migrated from Core/Text/sentence_operations.php
 */

declare(strict_types=1);

namespace Lwt\Modules\Text\Application\Services;

use Lwt\Modules\Language\Domain\WordSpacing;
use Lwt\Shared\Infrastructure\Globals;
use Lwt\Shared\Infrastructure\Utilities\StringUtils;
use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Shared\Infrastructure\Database\Settings;
use Lwt\Shared\Infrastructure\Database\UserScopedQuery;
use Lwt\Shared\UI\Helpers\IconHelper;
use Lwt\Modules\Language\Application\Services\TextParsingService;

/**
 * Service class for sentence operations.
 *
 * @category Lwt
 * @package  Lwt\Modules\Text\Application\Services
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */
class SentenceService
{
    private TextParsingService $textParsingService;

    /**
     * Constructor - initialize dependencies.
     *
     * @param TextParsingService|null $textParsingService Text parsing service (optional for BC)
     */
    public function __construct(?TextParsingService $textParsingService = null)
    {
        $this->textParsingService = $textParsingService ?? new TextParsingService();
    }

    /**
     * Build a parent-row user-scope clause for queries that touch the
     * `sentences` table. The `sentences` table has no UsID column of its
     * own — ownership is derived from the parent `texts` row via SeTxID.
     *
     * Returns an SQL fragment like
     * ` AND SeTxID IN (SELECT TxID FROM texts WHERE TxUsID = ?)` and
     * pushes the current user ID onto $bindings, or an empty string
     * when multi-user mode is off / no user is authenticated. Without
     * this gate, raw `FROM sentences …` queries leak rows from every
     * user's texts as long as the language ID matches.
     *
     * @param array<int, mixed> $bindings Reference to bindings array
     *
     * @return string SQL fragment (with leading space) or empty string
     */
    private function parentTextUserScope(array &$bindings): string
    {
        if (!Globals::isMultiUserEnabled()) {
            return '';
        }
        $userId = Globals::getCurrentUserId();
        if ($userId === null) {
            return '';
        }
        $bindings[] = $userId;
        return ' AND SeTxID IN (SELECT TxID FROM texts WHERE TxUsID = ?)';
    }

    /**
     * Check whether the parent text of a sentence is owned by the
     * current user. In single-user mode this is always true. Used as
     * a guard before {@see formatSentence} runs its content-fetching
     * SQL — that SQL joins `word_occurrences` and `languages` with no
     * UsID column to filter on, so an arbitrary SeID would otherwise
     * return another user's sentence text.
     */
    private function ownsSentence(int $seid): bool
    {
        if (!Globals::isMultiUserEnabled()) {
            return true;
        }
        $userId = Globals::getCurrentUserId();
        if ($userId === null) {
            return true;
        }
        /** @var int|string|null $hit */
        $hit = Connection::preparedFetchValue(
            "SELECT 1 AS owned
             FROM sentences, texts
             WHERE SeID = ? AND SeTxID = TxID AND TxUsID = ?
             LIMIT 1",
            [$seid, $userId],
            'owned'
        );
        return $hit !== null;
    }

    /**
     * Execute a SQL query to find sentences containing a word (complex search).
     *
     * @param string $wordlc Word to look for in lowercase
     * @param int    $lid    Language ID
     * @param int    $limit  Maximum number of sentences to return
     *
     * @return array<int, array<string, mixed>> Query result rows
     */
    private function executeSentencesContainingWordQuery(string $wordlc, int $lid, int $limit = -1): array
    {
        $mecab_str = null;
        $record = QueryBuilder::table('languages')
            ->select(['LgRegexpWordCharacters', 'LgRemoveSpaces'])
            ->where('LgID', '=', $lid)
            ->firstPrepared();
        if ($record === null) {
            return [];
        }
        $removeSpaces = (int)$record["LgRemoveSpaces"];
        $regexpWordChars = (string)($record["LgRegexpWordCharacters"] ?? '');

        if (WordSpacing::usesMecabMagicWord($regexpWordChars)) {
            $mecab_file = sys_get_temp_dir() . "/lwt_mecab_to_db.txt";
            $mecab_args = ' -F %m\\t%t\\t%h\\n -U %m\\t%t\\t%h\\n -E EOP\\t3\\t7\\n ';
            if (file_exists($mecab_file)) {
                unlink($mecab_file);
            }
            $fp = fopen($mecab_file, 'w');
            if ($fp !== false) {
                fwrite($fp, $wordlc . "\n");
                fclose($fp);
            }
            $mecab = $this->textParsingService->getMecabPath($mecab_args);
            $handle = popen($mecab . escapeshellarg($mecab_file), "r");
            if ($handle !== false && !feof($handle)) {
                $row = fgets($handle, 256);
                if ($row !== false) {
                    $mecab_str = "\t" . (preg_replace_callback(
                        '([2678]?)\t[0-9]+$',
                        function ($matches) {
                            return isset($matches[1]) ? "\t" : "";
                        },
                        $row
                    ) ?? $row);
                }
            }
            if ($handle !== false) {
                pclose($handle);
            }
            unlink($mecab_file);
            $sql = "SELECT SeID, SeText,
                concat(
                    '\\t',
                    group_concat(Ti2Text ORDER BY Ti2Order asc SEPARATOR '\\t'),
                    '\\t'
                ) val
                FROM sentences, word_occurrences
                WHERE lower(SeText) LIKE ?
                AND SeID = Ti2SeID AND SeLgID = ? AND Ti2WordCount<2
                GROUP BY SeID HAVING val LIKE ?
                ORDER BY CHAR_LENGTH(SeText), SeText";
            $params = ["%$wordlc%", $lid, "%$mecab_str%"];
        } else {
            if ($removeSpaces == 1) {
                $pattern = $wordlc;
            } else {
                $pattern = '(^|[^' . $regexpWordChars . '])'
                     . StringUtils::removeSpaces($wordlc, (bool)$removeSpaces)
                     . '([^' . $regexpWordChars . ']|$)';
            }
            $sql = "SELECT DISTINCT SeID, SeText
                FROM sentences
                WHERE SeText RLIKE ? AND SeLgID = ?
                ORDER BY CHAR_LENGTH(SeText), SeText";
            $params = [$pattern, $lid];
        }
        if ($limit > 0) {
            $sql .= " LIMIT ?";
            $params[] = $limit;
        }
        $stmt = Connection::prepare($sql);
        $stmt->bindValues($params);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Perform a SQL query to find sentences containing a word.
     *
     * @param int|null $wid    Word ID or mode
     *                         - null: use $wordlc instead, simple search
     *                         - -1: use $wordlc with a more complex search
     *                         - 0 or above: sentences containing $wid
     * @param string   $wordlc Word to look for in lowercase
     * @param int      $lid    Language ID
     * @param int      $limit  Maximum number of sentences to return
     *
     * @return array<int, array<string, mixed>> Query result rows
     */
    public function findSentencesFromWord(?int $wid, string $wordlc, int $lid, int $limit = -1): array
    {
        if ($wid === null) {
            $params = [$wordlc, $lid];
            $userScope = $this->parentTextUserScope($params);
            $sql = "SELECT DISTINCT SeID, SeText
                FROM sentences, word_occurrences
                WHERE LOWER(Ti2Text) = ?
                AND Ti2WoID IS NULL AND SeID = Ti2SeID AND SeLgID = ?{$userScope}
                ORDER BY CHAR_LENGTH(SeText), SeText";
        } elseif ($wid == -1) {
            // For complex search, build the query dynamically
            return $this->executeSentencesContainingWordQuery($wordlc, $lid, $limit);
        } else {
            $params = [$wid, $lid];
            $userScope = $this->parentTextUserScope($params);
            $sql = "SELECT DISTINCT SeID, SeText
                FROM sentences, word_occurrences
                WHERE Ti2WoID = ? AND SeID = Ti2SeID AND SeLgID = ?{$userScope}
                ORDER BY CHAR_LENGTH(SeText), SeText";
        }
        if ($limit > 0) {
            $sql .= " LIMIT ?";
            $params[] = $limit;
        }
        $stmt = Connection::prepare($sql);
        $stmt->bindValues($params);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Format the sentence(s) $seid containing $wordlc highlighting $wordlc.
     *
     * @param int    $seid   Sentence ID
     * @param string $wordlc Term text in lower case
     * @param int    $mode   * Up to 1: return only the current sentence
     *                       * Above 1: return previous sentence and current sentence
     *                       * Above 2: return previous, current and next sentence
     *
     * @return string[] [0]=html, word in bold, [1]=text, word in {}
     */
    public function formatSentence(int $seid, string $wordlc, int $mode): array
    {
        // Verify the caller owns the parent text before pulling sentence
        // content. The main query below joins word_occurrences and
        // languages without a UsID column on either, so without this
        // gate a foreign SeID returns the foreign user's sentence text
        // verbatim. Defense in depth — find* paths now scope the SeIDs
        // they return, but formatSentence is also reachable directly.
        if (!$this->ownsSentence($seid)) {
            return [$mode > 1 ? '' : $wordlc, $wordlc];
        }

        $record = Connection::preparedFetchOne(
            "SELECT
            CONCAT(
                '​', group_concat(Ti2Text ORDER BY Ti2Order asc SEPARATOR '​'), '​'
            ) AS SeText, Ti2TxID AS SeTxID, LgRegexpWordCharacters,
            LgRemoveSpaces, LgSplitEachChar
            FROM word_occurrences, languages
            WHERE Ti2LgID = LgID AND Ti2WordCount < 2 AND Ti2SeID = ?
            AND Ti2Text != '¶'",
            [$seid]
        );
        if ($record === null) {
            return [$mode > 1 ? '' : $wordlc, $wordlc];
        }
        $removeSpaces = (int)$record["LgRemoveSpaces"] == 1;
        $splitEachChar = (int)$record['LgSplitEachChar'] != 0;
        $txtid = (int)$record["SeTxID"];
        $termchar = (string) $record["LgRegexpWordCharacters"];
        $seText = (string)($record["SeText"] ?? '');

        if (
            ($removeSpaces && !$splitEachChar)
            || WordSpacing::usesMecabMagicWord($termchar)
        ) {
            $text = $seText;
            $wordlcReplaced = preg_replace('/(.)/u', "$1[​]*", $wordlc);
            $wordlc = '[​]*' . ($wordlcReplaced ?? $wordlc);
            $pattern = "/(?<=[​])($wordlc)(?=[​])/ui";
        } else {
            // Convert ZWS markers to proper spacing for non-remove-spaces languages
            $text = $this->convertZwsToSpacing($seText, $termchar);
            if ($splitEachChar) {
                $pattern = "/($wordlc)/ui";
            } else {
                $pattern = '/(?<![' . $termchar . '])(' .
                StringUtils::removeSpaces($wordlc, $removeSpaces) . ')(?![' .
                $termchar . '])/ui';
            }
        }

        $se = str_replace('​', '', (string)preg_replace($pattern, '<b>$0</b>', $text));
        $sejs = str_replace('​', '', (string)preg_replace($pattern, '{$0}', $text));

        if ($mode > 1) {
            // Always use word_occurrences to get proper sentence content with word boundaries
            /**
 * @var string|null $prevseSentRaw
*/
            $prevseSentRaw = Connection::preparedFetchValue(
                "SELECT concat(
                    '​',
                    group_concat(Ti2Text order by Ti2Order asc SEPARATOR '​'),
                    '​'
                ) AS sentence_text
                from sentences, word_occurrences
                where Ti2SeID = SeID and SeID < ? and SeTxID = ?
                and trim(SeText) not in ('¶', '')
                and Ti2Text != '¶'
                group by SeID
                order by SeID desc",
                [$seid, $txtid],
                'sentence_text'
            );
            if (isset($prevseSentRaw)) {
                $prevseSent = $prevseSentRaw;
                if (WordSpacing::separatesWordsWithSpaces($removeSpaces, $splitEachChar, $termchar)) {
                    $prevseSent = $this->convertZwsToSpacing($prevseSent, $termchar);
                }
                $se = str_replace('​', '', (string)preg_replace($pattern, '<b>$0</b>', $prevseSent)) . $se;
                $sejs = str_replace('​', '', (string)preg_replace($pattern, '{$0}', $prevseSent)) . $sejs;
            }
        }

        if ($mode > 2) {
            // Always use word_occurrences to get proper sentence content with word boundaries
            /**
 * @var string|null $nextSentRaw
*/
            $nextSentRaw = Connection::preparedFetchValue(
                "SELECT concat(
                    '​',
                    group_concat(Ti2Text order by Ti2Order asc SEPARATOR '​'),
                    '​'
                ) as value
                from sentences, word_occurrences
                where Ti2SeID = SeID and SeID > ?
                and SeTxID = ? and trim(SeText) not in ('¶','')
                and Ti2Text != '¶'
                group by SeID
                order by SeID asc",
                [$seid, $txtid]
            );
            if (isset($nextSentRaw)) {
                $nextSent = $nextSentRaw;
                if (WordSpacing::separatesWordsWithSpaces($removeSpaces, $splitEachChar, $termchar)) {
                    $nextSent = $this->convertZwsToSpacing($nextSent, $termchar);
                }
                $se .= str_replace('​', '', (string)preg_replace($pattern, '<b>$0</b>', $nextSent));
                $sejs .= str_replace('​', '', (string)preg_replace($pattern, '{$0}', $nextSent));
            }
        }

        if ($removeSpaces) {
            $se = str_replace('​', '', $se);
            $sejs = str_replace('​', '', $sejs);
        }
        // [0]=html, word in bold, [1]=text, word in {}
        return array($se, $sejs);
    }

    /**
     * Convert zero-width space (ZWS) markers to proper spacing.
     *
     * For languages that use spaces between words (LgRemoveSpaces = 0),
     * this method converts ZWS markers in the text to actual spaces where
     * appropriate (between words and after punctuation).
     *
     * @param string $text     Text with ZWS markers between tokens
     * @param string $termchar Language's word character regex pattern
     *
     * @return string Text with proper spacing
     */
    private function convertZwsToSpacing(string $text, string $termchar): string
    {
        // Without a word-character class, the regex character classes below
        // would be empty and fail to compile.
        if ($termchar === '') {
            return trim(str_replace("​", "", $text));
        }

        // Step 1: Add space between consecutive word characters
        $pattern1 = "/([$termchar])​(?=[$termchar])/u";
        $result = preg_replace($pattern1, "$1 ", $text) ?? $text;

        // Step 2: Add space after sentence punctuation when followed by word char
        $pattern2 = "/([.!?,;:…])​(?=[$termchar])/u";
        $result = preg_replace($pattern2, "$1 ", $result) ?? $result;

        // Step 3: Add space after closing quotes/brackets when followed by word char
        $pattern3 = '/([\]})»›"\'」』])​(?=[' . $termchar . '])/u';
        $result = preg_replace($pattern3, '$1 ', $result) ?? $result;

        // Step 4: Remove remaining ZWS markers (preserving any actual space tokens)
        $result = str_replace("​", "", $result);

        return trim($result);
    }

    /**
     * Get the formatted text of a sentence by its ID.
     *
     * Reconstructs the sentence from word_occurrences table with proper spacing.
     * Use this instead of reading SeText directly from sentences table.
     *
     * @param int $seid Sentence ID
     *
     * @return string|null Formatted sentence text, or null if not found
     */
    public function getSentenceText(int $seid): ?string
    {
        $record = Connection::preparedFetchOne(
            "SELECT
                CONCAT(
                    '​', GROUP_CONCAT(Ti2Text ORDER BY Ti2Order ASC SEPARATOR '​'), '​'
                ) AS SeText,
                LgRegexpWordCharacters,
                LgRemoveSpaces,
                LgSplitEachChar
            FROM word_occurrences
            JOIN languages ON Ti2LgID = LgID
            WHERE Ti2WordCount < 2
              AND Ti2SeID = ?
              AND Ti2Text != '¶'",
            [$seid]
        );

        if ($record === null || $record['SeText'] === null) {
            return null;
        }

        $removeSpaces = (int) $record['LgRemoveSpaces'] == 1;
        $splitEachChar = (int) $record['LgSplitEachChar'] != 0;
        $termchar = (string) $record['LgRegexpWordCharacters'];

        // For languages that don't remove spaces and don't split each char
        // (like most Western languages), apply spacing conversion
        $seText = (string)$record['SeText'];
        if (WordSpacing::separatesWordsWithSpaces($removeSpaces, $splitEachChar, $termchar)) {
            $text = $this->convertZwsToSpacing($seText, $termchar);
        } else {
            // For Asian languages etc., just remove the ZWS markers
            $text = str_replace('​', '', $seText);
        }

        return trim($text);
    }

    /**
     * Get the sentence text at a specific position in a text.
     *
     * This method extracts the sentence containing the word at the given position.
     * It handles cases where texts weren't properly split into sentences during parsing
     * by finding sentence boundaries (punctuation) around the target position.
     *
     * @param int $textId   Text ID
     * @param int $position Word position (Ti2Order)
     *
     * @return string|null The sentence containing the word, or null if not found
     */
    public function getSentenceAtPosition(int $textId, int $position): ?string
    {
        // Get the sentence ID for this position
        /**
 * @var int|null $seidRaw
*/
        $seidRaw = Connection::preparedFetchValue(
            "SELECT Ti2SeID FROM word_occurrences WHERE Ti2TxID = ? AND Ti2Order = ?",
            [$textId, $position],
            'Ti2SeID'
        );

        if ($seidRaw === null) {
            return null;
        }
        $seid = $seidRaw;

        // Get language settings
        $langRecord = Connection::preparedFetchOne(
            "SELECT LgRegexpWordCharacters, LgRemoveSpaces, LgSplitEachChar,
                    LgRegexpSplitSentences
             FROM word_occurrences
             JOIN languages ON Ti2LgID = LgID
             WHERE Ti2TxID = ? LIMIT 1",
            [$textId]
        );

        if ($langRecord === null) {
            return null;
        }

        $removeSpaces = (int) $langRecord['LgRemoveSpaces'] == 1;
        $splitEachChar = (int) $langRecord['LgSplitEachChar'] != 0;
        $termchar = (string) $langRecord['LgRegexpWordCharacters'];
        $splitSentence = (string) $langRecord['LgRegexpSplitSentences'];

        // Get tokens around the position (larger context to find sentence boundaries)
        // We'll get ~100 tokens before and after the target position
        $contextRange = 100;
        $minOrder = max(1, $position - $contextRange);
        $maxOrder = $position + $contextRange;

        $tokens = Connection::preparedFetchAll(
            "SELECT Ti2Order, Ti2Text, Ti2WordCount
             FROM word_occurrences
             WHERE Ti2TxID = ? AND Ti2SeID = ?
               AND Ti2Order >= ? AND Ti2Order <= ?
               AND Ti2Text != '¶'
             ORDER BY Ti2Order ASC",
            [$textId, $seid, $minOrder, $maxOrder]
        );

        if (empty($tokens)) {
            return null;
        }

        // Build the text with ZWS markers, tracking positions
        $textWithZws = '​';
        $positionMap = []; // Map token order to character position in text
        $currentPos = 1; // Start after initial ZWS

        foreach ($tokens as $token) {
            $order = (int) $token['Ti2Order'];
            $tokenText = (string) $token['Ti2Text'];

            $positionMap[$order] = $currentPos;
            $textWithZws .= $tokenText . '​';
            $currentPos += mb_strlen($tokenText) + 1; // +1 for ZWS
        }

        // Convert ZWS to proper spacing
        if (WordSpacing::separatesWordsWithSpaces($removeSpaces, $splitEachChar, $termchar)) {
            $text = $this->convertZwsToSpacing($textWithZws, $termchar);
        } else {
            $text = str_replace('​', '', $textWithZws);
        }

        // Get the target word text to locate it in the formatted string
        $targetWord = null;
        foreach ($tokens as $token) {
            if ((int) $token['Ti2Order'] === $position) {
                $targetWord = (string) $token['Ti2Text'];
                break;
            }
        }

        if ($targetWord === null) {
            return $this->extractCenteredPortion($text, 500);
        }

        // Find the position of the target word in the text (character position)
        $targetPos = mb_stripos($text, $targetWord);
        if ($targetPos === false) {
            return $this->extractCenteredPortion($text, 500);
        }

        // Build sentence boundary pattern - matches sentence-ending punctuation
        // followed by optional closing quotes/brackets and then whitespace or end
        $sentenceEndChars = '.!?…';
        if (!empty($splitSentence)) {
            $sentenceEndChars .= $splitSentence;
        }
        $sentenceEndPattern = '/[' . preg_quote($sentenceEndChars, '/') . ']+[\'\"\'\"»›」』\])]*(?:\s|$)/u';

        // Find the previous sentence boundary (before the target word)
        $textBefore = mb_substr($text, 0, $targetPos);
        $sentenceStart = 0;
        if (preg_match_all($sentenceEndPattern, $textBefore, $matches, PREG_OFFSET_CAPTURE) > 0) {
            // Get the last match - this is the end of the previous sentence
            $lastMatch = end($matches[0]);
            if ($lastMatch !== false) {
                // PREG_OFFSET_CAPTURE returns byte offsets, convert to character offset
                $byteOffset = $lastMatch[1] + strlen($lastMatch[0]);
                $sentenceStart = mb_strlen(substr($textBefore, 0, $byteOffset));
            }
        }

        // Find the next sentence boundary (after the target word)
        $textAfter = mb_substr($text, $targetPos + mb_strlen($targetWord));
        $sentenceEnd = mb_strlen($text);
        if (preg_match($sentenceEndPattern, $textAfter, $match, PREG_OFFSET_CAPTURE)) {
            // PREG_OFFSET_CAPTURE returns byte offsets, convert to character offset
            $byteOffset = $match[0][1] + strlen(trim($match[0][0]));
            $charsAfterTarget = mb_strlen(substr($textAfter, 0, $byteOffset));
            $sentenceEnd = $targetPos + mb_strlen($targetWord) + $charsAfterTarget;
        }

        // Extract the sentence
        $result = trim(mb_substr($text, $sentenceStart, $sentenceEnd - $sentenceStart));

        // If still too long, extract a portion around the word
        if (mb_strlen($result) > 800) {
            $result = $this->extractPortionAroundWord($result, $targetWord, 400);
        }

        return $result ?: null;
    }

    /**
     * Extract a centered portion of text.
     *
     * @param string $text      The text to extract from
     * @param int    $maxLength Maximum length of the result
     *
     * @return string The extracted portion
     */
    private function extractCenteredPortion(string $text, int $maxLength): string
    {
        $length = mb_strlen($text);
        if ($length <= $maxLength) {
            return $text;
        }

        $start = (int) (($length - $maxLength) / 2);
        $result = mb_substr($text, $start, $maxLength);

        // Try to start/end at word boundaries
        $result = preg_replace('/^\S*\s/', '', $result) ?? $result;
        $result = preg_replace('/\s\S*$/', '', $result) ?? $result;

        return '...' . trim($result) . '...';
    }

    /**
     * Extract a portion of text centered around a specific word.
     *
     * @param string $text      The text to extract from
     * @param string $word      The word to center around
     * @param int    $maxLength Maximum characters on each side of the word
     *
     * @return string The extracted portion
     */
    private function extractPortionAroundWord(string $text, string $word, int $maxLength): string
    {
        $pos = mb_stripos($text, $word);
        if ($pos === false) {
            return $this->extractCenteredPortion($text, $maxLength * 2);
        }

        $start = max(0, $pos - $maxLength);
        $end = min(mb_strlen($text), $pos + mb_strlen($word) + $maxLength);

        $result = mb_substr($text, $start, $end - $start);

        // Try to start/end at word boundaries
        if ($start > 0) {
            $result = preg_replace('/^\S*\s/', '', $result) ?? $result;
            $result = '...' . trim($result);
        }
        if ($end < mb_strlen($text)) {
            $result = preg_replace('/\s\S*$/', '', $result) ?? $result;
            $result = trim($result) . '...';
        }

        return $result;
    }

    /**
     * Return sentences containing a word.
     *
     * @param int      $lang   Language ID
     * @param string   $wordlc Word to look for in lowercase
     * @param int|null $wid    Word ID
     *                         - null: use $wordlc instead, simple search
     *                         - -1: use $wordlc with a more complex search
     *                         - 0 or above: find sentences containing $wid
     * @param int|null $mode   Sentences to get:
     *                         - Up to 1 is 1 sentence,
     *                         - 2 is previous and current sentence,
     *                         - 3 is previous, current and next one
     * @param int      $limit  Maximum number of sentences to return
     *
     * @return string[][] Array of sentences found
     */
    public function getSentencesWithWord(int $lang, string $wordlc, ?int $wid, ?int $mode = 0, int $limit = 20): array
    {
        $r = array();
        $res = $this->findSentencesFromWord($wid, $wordlc, $lang, $limit);
        $last = '';
        if (is_null($mode)) {
            $mode = (int) Settings::getWithDefault('set-term-sentence-count');
        }
        foreach ($res as $record) {
            $seText = (string)$record['SeText'];
            if ($last != $seText) {
                $sent = $this->formatSentence((int)$record['SeID'], $wordlc, $mode);
                if (mb_strstr($sent[1], '}', false, 'UTF-8') !== false) {
                    $r[] = $sent;
                }
            }
            $last = $seText;
        }
        return $r;
    }

    /**
     * Show 20 sentences containing $wordlc.
     *
     * @param int      $lang        Language ID
     * @param string   $wordlc      Term in lower case.
     * @param int|null $wid         Word ID
     * @param string   $targetCtlId ID of the target textarea element
     * @param int      $mode        * Up to 1: return only the current sentence
     *                              * Above 1: return previous and current
     *                              sentence * Above 2: return previous,
     *                              current and next sentence
     *
     * @return string HTML-formatted string of which elements are candidate sentences to use.
     */
    public function get20Sentences(int $lang, string $wordlc, ?int $wid, string $targetCtlId, int $mode): string
    {
        $r = '<p><b>Sentences in active texts with <i>' . htmlspecialchars($wordlc, ENT_QUOTES, 'UTF-8') . '</i></b></p>
        <p>(Click on ' . IconHelper::render('circle-check', ['title' => 'Choose', 'alt' => 'Choose']) . '
        to copy sentence into above term)</p>';
        $sentences = $this->getSentencesWithWord($lang, $wordlc, $wid, $mode);
        foreach ($sentences as $sentence) {
            $r .= '<span class="click" data-action="copy-sentence" ' .
                'data-target="' . htmlspecialchars($targetCtlId, ENT_QUOTES, 'UTF-8') . '" ' .
                'data-sentence="' . htmlspecialchars($sentence[1], ENT_QUOTES, 'UTF-8') . '">' .
            IconHelper::render('circle-check', ['title' => 'Choose', 'alt' => 'Choose']) .
            '</span> &nbsp;' . $sentence[0] . '<br />';
        }
        $r .= '</p>';
        return $r;
    }

    /**
     * Render the area for example sentences of a word.
     *
     * @param int    $lang        Language ID
     * @param string $termlc      Term text in lowercase
     * @param string $targetCtlId ID of the target textarea element
     * @param int    $wid         Word ID
     *
     * @return string HTML output
     */
    public function renderExampleSentencesArea(int $lang, string $termlc, string $targetCtlId, int $wid): string
    {
        ob_start();
        ?>
<div id="exsent">
    <!-- Interactable text -->
    <div id="exsent-interactable">
        <span class="click" data-action="show-sentences"
            data-lang="<?php echo $lang; ?>"
            data-termlc="<?php echo htmlspecialchars($termlc, ENT_QUOTES, 'UTF-8'); ?>"
            data-target="<?php echo htmlspecialchars($targetCtlId, ENT_QUOTES, 'UTF-8'); ?>"
            data-wid="<?php echo $wid; ?>">
            <?php echo IconHelper::render('layers', ['title' => 'Show Sentences', 'alt' => 'Show Sentences']); ?>
            Show Sentences
        </span>
    </div>
    <!-- Loading icon -->
        <?php
        echo IconHelper::render(
            'loader-2',
            ['id' => 'exsent-waiting', 'alt' => 'Loading...', 'class' => 'icon-spin']
        );
        ?>
    <!-- Displayed output -->
    <div id="exsent-sentences">
        <p><b>Sentences in active texts with <i><?php echo htmlspecialchars($termlc, ENT_QUOTES, 'UTF-8') ?></i></b></p>
        <p>
            (Click on
            <?php echo IconHelper::render('circle-check', ['title' => 'Choose', 'alt' => 'Choose']); ?>
            to copy sentence into above term)
        </p>
    </div>
</div>
        <?php
        $output = ob_get_clean();
        return $output !== false ? $output : '';
    }
}
