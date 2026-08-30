<?php

/**
 * \file
 * \brief Persist parsed tokens as sentences and word occurrences (pure PHP).
 *
 * PHP version 8.2
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
     * Build the check-a-text report as data.
     *
     * The sentence split, the word/expression/non-word tallies and the
     * parse-coverage verdict — what the check page used to receive as HTML
     * echoed mid-request (#262, #266).
     *
     * Values are returned raw. The caller renders them — the client builds
     * text nodes rather than markup — so nothing is HTML-escaped here.
     *
     * @param ParsedToken[] $tokens    Tokens for the whole text
     * @param int           $lid       Language ID
     * @param bool          $rtlScript Whether the language is right-to-left
     *
     * @return array{sentences: list<string>, words: list<array{0: string, 1: int, 2: string}>,
     *         nonWords: list<array{0: string, 1: int}>,
     *         multiWords: list<array{0: string, 1: int, 2: string}>,
     *         rtlScript: bool, warning: string}
     */
    public static function report(array $tokens, int $lid, bool $rtlScript): array
    {
        $bySentence = self::groupBySentence($tokens);

        $sentences = [];
        foreach ($bySentence as $sTokens) {
            $sentences[] = self::sentenceText($sTokens);
        }

        $wordCounts = [];
        $nonWordCounts = [];
        $characters = 0;
        foreach ($tokens as $t) {
            $characters += \mb_strlen($t->text, 'UTF-8');
            $lc = self::lc($t->text);
            if ($t->wordCount === 1) {
                $wordCounts[$lc] = ($wordCounts[$lc] ?? 0) + 1;
            } else {
                $nonWordCounts[$lc] = ($nonWordCounts[$lc] ?? 0) + 1;
            }
        }

        $single = self::singleWordTerms($lid, array_keys($wordCounts));

        // The tallies are keyed by the term itself, and PHP turns a numeric
        // key into an int — a text containing "42" would otherwise report a
        // number where every other term reports a string. Cast on the way out.
        $words = [];
        foreach ($wordCounts as $lc => $cnt) {
            /** @psalm-suppress RedundantCast, RedundantCastGivenDocblockType */
            $words[] = [(string) $lc, $cnt, (string) ($single[$lc]['tr'] ?? '')];
        }

        $nonWords = [];
        foreach ($nonWordCounts as $lc => $cnt) {
            /** @psalm-suppress RedundantCast */
            $nonWords[] = [(string) $lc, $cnt];
        }

        return [
            'sentences' => $sentences,
            'words' => $words,
            'nonWords' => $nonWords,
            'multiWords' => self::multiWordOccurrences($bySentence, $lid),
            'rtlScript' => $rtlScript,
            'warning' => ParseCoverage::assess(array_sum($wordCounts), $characters),
        ];
    }

    /**
     * Tally the multi-word terms occurring in a parsed text.
     *
     * @param array<int, list<ParsedToken>> $bySentence Tokens grouped by sentence
     * @param int                           $lid        Language ID
     *
     * @return list<array{0: string, 1: int, 2: string}> [term, occurrences, translation]
     */
    private static function multiWordOccurrences(array $bySentence, int $lid): array
    {
        $mwTerms = self::multiWordTerms($lid);
        $occ = self::detectMultiWords($bySentence, $mwTerms);

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
            $mw[] = [self::lc($info['text']), $cnt, $info['tr']];
        }
        \usort($mw, fn(array $a, array $b): int => \strcmp((string) $a[0], (string) $b[0]));

        return $mw;
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
