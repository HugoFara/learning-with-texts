<?php

/**
 * \file
 * \brief Persist parsed tokens as sentences and word occurrences (pure PHP).
 *
 * PHP version 8.1
 *
 * @category Database
 * @package  Lwt
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.2.2
 */

declare(strict_types=1);

namespace Lwt\Shared\Infrastructure\Database;

use Lwt\Modules\Text\Domain\ParseCoverage;

/**
 * Turns a parsed token stream into `sentences` and `word_occurrences` rows,
 * detecting multi-word expressions along the way — all in PHP.
 *
 * This replaces the old scratch-table pipeline (temp_word_occurrences +
 * tempexprs + numbers + the LOAD DATA path + the stateful @-variable multi-word
 * detection SQL). Sentences are inserted first (to satisfy the FK on
 * word_occurrences), their real SeIDs are read back, and word occurrences —
 * single words and multi-word expressions — are inserted referencing them.
 *
 * @since 3.2.2
 */
final class TokenPersistence
{
    /** @var int Rows per INSERT statement. */
    private const CHUNK = 500;

    /**
     * Save parsed tokens as sentences + word occurrences for a text.
     *
     * @param ParsedToken[] $tokens Tokens for the whole text
     * @param int           $lid    Language ID
     * @param int           $textId Text ID
     *
     * @return void
     */
    public static function save(array $tokens, int $lid, int $textId): void
    {
        if (empty($tokens)) {
            return;
        }
        $bySentence = self::groupBySentence($tokens);
        $seIdMap = self::insertSentences($bySentence, $lid, $textId);

        $singleMap = self::singleWordTerms($lid, self::distinctWordLowercase($tokens));
        $mwTerms = self::multiWordTerms($lid);

        // Single tokens (words + non-words): every token becomes an occurrence.
        $rows = [];
        foreach ($tokens as $t) {
            $woId = null;
            if ($t->wordCount === 1) {
                $woId = $singleMap[self::lc($t->text)]['id'] ?? null;
            }
            $rows[] = [$woId, $lid, $textId, $seIdMap[$t->sentence], $t->order, $t->wordCount, $t->text];
        }
        // Multi-word expressions overlay the single words they span.
        foreach (self::detectMultiWords($bySentence, $mwTerms) as $mw) {
            // Store the span text only when it differs from its lowercase form
            // (matches the historical Ti2Text storage optimisation).
            $stored = $mw['text'] !== self::lc($mw['text']) ? $mw['text'] : '';
            $rows[] = [$mw['id'], $lid, $textId, $seIdMap[$mw['sentence']], $mw['order'], $mw['n'], $stored];
        }

        self::insertWordOccurrences($rows);
    }

    /**
     * Compute preview statistics for the check-text UI (no output).
     *
     * @param ParsedToken[] $tokens Tokens for the whole text
     * @param int           $lid    Language ID
     *
     * @return array{sentences: int, words: int, unknownPercent: float, preview: string}
     */
    public static function stats(array $tokens, int $lid): array
    {
        if (empty($tokens)) {
            return ['sentences' => 0, 'words' => 0, 'unknownPercent' => 100.0, 'preview' => ''];
        }
        $bySentence = self::groupBySentence($tokens);

        $counts = [];
        $total = 0;
        foreach ($tokens as $t) {
            if ($t->wordCount === 1) {
                $lc = self::lc($t->text);
                $counts[$lc] = ($counts[$lc] ?? 0) + 1;
                $total++;
            }
        }
        $single = self::singleWordTerms($lid, array_keys($counts));
        $unknown = 0;
        foreach ($counts as $lc => $cnt) {
            if (empty($single[$lc]['tr'] ?? '')) {
                $unknown += $cnt;
            }
        }
        $unknownPercent = $total > 0 ? round($unknown / $total * 100, 1) : 100.0;

        $texts = [];
        foreach ($bySentence as $sTokens) {
            $texts[] = self::sentenceText($sTokens);
        }
        $preview = implode(' ', array_slice($texts, 0, 3));
        if (count($texts) > 3) {
            $preview .= '...';
        }

        return [
            'sentences' => count($bySentence),
            'words' => $total,
            'unknownPercent' => $unknownPercent,
            'preview' => $preview,
        ];
    }

    /**
     * Echo the sentence list and per-word JSON for the check-text preview.
     *
     * @param ParsedToken[] $tokens Tokens for the whole text
     * @param int           $lid    Language ID
     *
     * @return void
     */
    public static function echoCheckValid(array $tokens, int $lid): void
    {
        $bySentence = self::groupBySentence($tokens);
        echo '<h4>Sentences</h4><ol>';
        foreach ($bySentence as $sTokens) {
            echo '<li>' . \htmlspecialchars(self::sentenceText($sTokens), ENT_QUOTES, 'UTF-8') . '</li>';
        }
        echo '</ol>';

        $wordCounts = [];
        $nonWordCounts = [];
        foreach ($tokens as $t) {
            $lc = self::lc($t->text);
            if ($t->wordCount === 1) {
                $wordCounts[$lc] = ($wordCounts[$lc] ?? 0) + 1;
            } else {
                $nonWordCounts[$lc] = ($nonWordCounts[$lc] ?? 0) + 1;
            }
        }
        self::echoParseWarning($tokens, $wordCounts);

        $single = self::singleWordTerms($lid, array_keys($wordCounts));
        $wo = [];
        foreach ($wordCounts as $lc => $cnt) {
            $wo[] = [
                \htmlspecialchars($lc, ENT_QUOTES, 'UTF-8'),
                $cnt,
                \htmlspecialchars($single[$lc]['tr'] ?? '', ENT_QUOTES, 'UTF-8'),
            ];
        }
        $nw = [];
        foreach ($nonWordCounts as $lc => $cnt) {
            $nw[] = [
                \htmlspecialchars($lc, ENT_QUOTES, 'UTF-8'),
                \htmlspecialchars((string)$cnt, ENT_QUOTES, 'UTF-8'),
            ];
        }
        echo '<script type="application/json" id="text-check-words-config">';
        echo \json_encode(['words' => $wo, 'nonWords' => $nw], JSON_HEX_TAG | JSON_HEX_AMP);
        echo '</script>';
    }

    /**
     * Warn on the check-text page when the parse produced nothing learnable.
     *
     * This page exists to answer "did my parsing work?", and until now it
     * answered a failed parse with an empty list of words and no explanation.
     *
     * @param ParsedToken[]     $tokens     Tokens for the whole text
     * @param array<string,int> $wordCounts Word tokens by lowercase form
     *
     * @return void
     */
    private static function echoParseWarning(array $tokens, array $wordCounts): void
    {
        $characters = 0;
        foreach ($tokens as $t) {
            $characters += \mb_strlen($t->text, 'UTF-8');
        }

        $verdict = ParseCoverage::assess(array_sum($wordCounts), $characters);
        if (!ParseCoverage::isWarning($verdict)) {
            return;
        }

        $headline = $verdict === ParseCoverage::NO_WORDS
            ? __('text.parse_warning.no_words')
            : __('text.parse_warning.almost_no_words');

        echo '<div class="notification is-warning is-light"><strong>'
            . \htmlspecialchars($headline, ENT_QUOTES, 'UTF-8')
            . '</strong> '
            . \htmlspecialchars(__('text.parse_warning.check_language'), ENT_QUOTES, 'UTF-8')
            . '</div>';
    }

    /**
     * Echo the multi-word statistics JSON for the check-text preview.
     *
     * @param ParsedToken[] $tokens    Tokens for the whole text
     * @param int           $lid       Language ID
     * @param bool          $rtlScript Whether the language is right-to-left
     *
     * @return void
     */
    public static function echoStatistics(array $tokens, int $lid, bool $rtlScript): void
    {
        $mwTerms = self::multiWordTerms($lid);
        $occ = self::detectMultiWords(self::groupBySentence($tokens), $mwTerms);

        $idInfo = [];
        foreach ($mwTerms as $terms) {
            foreach ($terms as $info) {
                $idInfo[$info['id']] = $info;
            }
        }
        $byWo = [];
        foreach ($occ as $o) {
            $byWo[$o['id']] = ($byWo[$o['id']] ?? 0) + 1;
        }
        $mw = [];
        foreach ($byWo as $id => $cnt) {
            $info = $idInfo[$id] ?? ['text' => '', 'tr' => ''];
            $mw[] = [
                \htmlspecialchars(self::lc($info['text']), ENT_QUOTES, 'UTF-8'),
                $cnt,
                \htmlspecialchars($info['tr'], ENT_QUOTES, 'UTF-8'),
            ];
        }
        \usort($mw, fn(array $a, array $b): int => \strcmp((string)$a[0], (string)$b[0]));

        echo '<script type="application/json" id="text-check-config">';
        echo \json_encode(
            ['words' => [], 'multiWords' => $mw, 'nonWords' => [], 'rtlScript' => $rtlScript],
            JSON_HEX_TAG | JSON_HEX_AMP
        );
        echo '</script>';
    }

    // =========================================================================
    // Internal helpers
    // =========================================================================

    /**
     * Group tokens by their sentence index, preserving order.
     *
     * @param ParsedToken[] $tokens Tokens
     *
     * @return array<int, list<ParsedToken>>
     */
    private static function groupBySentence(array $tokens): array
    {
        $by = [];
        foreach ($tokens as $t) {
            $by[$t->sentence][] = $t;
        }
        ksort($by);
        return $by;
    }

    /**
     * Concatenate a sentence's token texts.
     *
     * @param list<ParsedToken> $sTokens Sentence tokens
     *
     * @return string
     */
    private static function sentenceText(array $sTokens): string
    {
        $s = '';
        foreach ($sTokens as $t) {
            $s .= $t->text;
        }
        return $s;
    }

    /**
     * Insert sentences and return a map of local sentence index -> real SeID.
     *
     * @param array<int, list<ParsedToken>> $bySentence Tokens grouped by sentence
     * @param int                           $lid        Language ID
     * @param int                           $textId     Text ID
     *
     * @return array<int, int>
     */
    private static function insertSentences(array $bySentence, int $lid, int $textId): array
    {
        $localSids = array_keys($bySentence);
        $params = [];
        $seOrder = 0;
        foreach ($bySentence as $sTokens) {
            $seOrder++;
            $firstPos = null;
            $text = '';
            foreach ($sTokens as $t) {
                $pos = $t->wordCount === 0 ? $t->order + 1 : $t->order;
                if ($firstPos === null || $pos < $firstPos) {
                    $firstPos = $pos;
                }
                $text .= $t->text;
            }
            array_push($params, $lid, $textId, $seOrder, (int)$firstPos, $text);
        }
        self::chunkedInsert(
            'INSERT INTO sentences (SeLgID, SeTxID, SeOrder, SeFirstPos, SeText) VALUES ',
            '(?, ?, ?, ?, ?)',
            5,
            $params
        );

        $bindings = [$textId];
        $rows = Connection::preparedFetchAll(
            'SELECT SeID FROM sentences WHERE SeTxID = ?'
            . UserScopedQuery::forTablePrepared('sentences', $bindings)
            . ' ORDER BY SeOrder',
            $bindings
        );
        $map = [];
        foreach ($localSids as $i => $localSid) {
            $map[$localSid] = (int)($rows[$i]['SeID'] ?? 0);
        }
        return $map;
    }

    /**
     * Insert word-occurrence rows in chunks.
     *
     * @param list<array{0:?int,1:int,2:int,3:int,4:int,5:int,6:string}> $rows Rows
     *
     * @return void
     */
    private static function insertWordOccurrences(array $rows): void
    {
        if (empty($rows)) {
            return;
        }
        $params = [];
        foreach ($rows as $r) {
            array_push($params, $r[0], $r[1], $r[2], $r[3], $r[4], $r[5], $r[6]);
        }
        self::chunkedInsert(
            'INSERT INTO word_occurrences '
            . '(Ti2WoID, Ti2LgID, Ti2TxID, Ti2SeID, Ti2Order, Ti2WordCount, Ti2Text) VALUES ',
            '(?, ?, ?, ?, ?, ?, ?)',
            7,
            $params
        );
    }

    /**
     * Execute a multi-row INSERT in chunks of CHUNK rows.
     *
     * @param string     $prefix        SQL up to and including "VALUES "
     * @param string     $rowPlaceholder Placeholder for one row, e.g. "(?, ?)"
     * @param int        $colsPerRow    Number of columns per row
     * @param list<mixed> $params        Flat parameter list
     *
     * @return void
     */
    private static function chunkedInsert(
        string $prefix,
        string $rowPlaceholder,
        int $colsPerRow,
        array $params
    ): void {
        $rowCount = intdiv(count($params), $colsPerRow);
        for ($i = 0; $i < $rowCount; $i += self::CHUNK) {
            $n = min(self::CHUNK, $rowCount - $i);
            $placeholders = implode(',', array_fill(0, $n, $rowPlaceholder));
            $slice = array_slice($params, $i * $colsPerRow, $n * $colsPerRow);
            Connection::preparedExecute($prefix . $placeholders, $slice);
        }
    }

    /**
     * Distinct lowercased word-token texts.
     *
     * @param ParsedToken[] $tokens Tokens
     *
     * @return list<string>
     */
    private static function distinctWordLowercase(array $tokens): array
    {
        $set = [];
        foreach ($tokens as $t) {
            if ($t->wordCount === 1) {
                $set[self::lc($t->text)] = true;
            }
        }
        return array_keys($set);
    }

    /**
     * Load single-word terms for the given lowercased words.
     *
     * @param int          $lid        Language ID
     * @param list<string> $lowerWords Distinct lowercased words to look up
     *
     * @return array<string, array{id: int, tr: string}>
     */
    private static function singleWordTerms(int $lid, array $lowerWords): array
    {
        if (empty($lowerWords)) {
            return [];
        }
        $bindings = [$lid];
        // Build the IN clause manually: these are string keys (WoTextLC), not
        // the integer IDs that Connection::buildPreparedInClause() expects.
        $inClause = '(' . implode(',', array_fill(0, count($lowerWords), '?')) . ')';
        foreach ($lowerWords as $word) {
            $bindings[] = $word;
        }
        $sql = 'SELECT WoID, WoTextLC, WoTranslation FROM words '
            . 'WHERE WoLgID = ? AND WoWordCount = 1 AND WoTextLC IN ' . $inClause
            . UserScopedQuery::forTablePrepared('words', $bindings);
        $map = [];
        foreach (Connection::preparedFetchAll($sql, $bindings) as $r) {
            $map[(string)$r['WoTextLC']] = [
                'id' => (int)$r['WoID'],
                'tr' => (string)($r['WoTranslation'] ?? ''),
            ];
        }
        return $map;
    }

    /**
     * Load multi-word terms grouped by word count.
     *
     * @param int $lid Language ID
     *
     * @return array<int, array<string, array{id: int, text: string, tr: string}>>
     */
    private static function multiWordTerms(int $lid): array
    {
        $bindings = [$lid];
        $sql = 'SELECT WoID, WoText, WoTextLC, WoTranslation, WoWordCount FROM words '
            . 'WHERE WoLgID = ? AND WoWordCount > 1'
            . UserScopedQuery::forTablePrepared('words', $bindings);
        $map = [];
        foreach (Connection::preparedFetchAll($sql, $bindings) as $r) {
            $n = (int)$r['WoWordCount'];
            $map[$n][(string)$r['WoTextLC']] = [
                'id' => (int)$r['WoID'],
                'text' => (string)$r['WoText'],
                'tr' => (string)($r['WoTranslation'] ?? ''),
            ];
        }
        return $map;
    }

    /**
     * Detect multi-word expression occurrences in the token stream.
     *
     * For each sentence and each known multi-word length n, slide a window of n
     * words and match the concatenated span (words + intervening separators)
     * against the language's n-word terms.
     *
     * @param array<int, list<ParsedToken>>                                  $bySentence Tokens by sentence
     * @param array<int, array<string, array{id:int,text:string,tr:string}>> $mwTerms    Multi-word terms
     *
     * @return list<array{id: int, sentence: int, order: int, n: int, text: string}>
     */
    private static function detectMultiWords(array $bySentence, array $mwTerms): array
    {
        if (empty($mwTerms)) {
            return [];
        }
        $lengths = array_keys($mwTerms);
        $occ = [];
        foreach ($bySentence as $localSid => $sTokens) {
            $wordIdx = [];
            foreach ($sTokens as $idx => $t) {
                if ($t->wordCount === 1) {
                    $wordIdx[] = $idx;
                }
            }
            $wordCount = count($wordIdx);
            foreach ($lengths as $n) {
                if ($n < 2) {
                    continue;
                }
                for ($i = 0; $i + $n - 1 < $wordCount; $i++) {
                    $firstIdx = $wordIdx[$i];
                    $lastIdx = $wordIdx[$i + $n - 1];
                    $span = '';
                    for ($k = $firstIdx; $k <= $lastIdx; $k++) {
                        $span .= $sTokens[$k]->text;
                    }
                    $lc = self::lc($span);
                    if (isset($mwTerms[$n][$lc])) {
                        $occ[] = [
                            'id' => $mwTerms[$n][$lc]['id'],
                            'sentence' => $localSid,
                            'order' => $sTokens[$firstIdx]->order,
                            'n' => $n,
                            'text' => $span,
                        ];
                    }
                }
            }
        }
        return $occ;
    }

    /**
     * Lowercase a string (UTF-8).
     *
     * @param string $s Input
     *
     * @return string
     */
    private static function lc(string $s): string
    {
        return mb_strtolower($s, 'UTF-8');
    }
}
