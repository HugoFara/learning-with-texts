<?php

/**
 * Expression Service - Multi-word expression handling
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

use Lwt\Modules\Language\Domain\WordSpacing;
use Lwt\Shared\Infrastructure\Utilities\StringUtils;
use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Database\Escaping;
use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Shared\Infrastructure\Database\Settings;
use Lwt\Modules\Language\Application\Services\TextParsingService;

/**
 * Service class for multi-word expression handling.
 *
 * Contains functions for finding and inserting multi-word expressions,
 * including MeCab integration for Japanese text processing.
 *
 * @since 3.0.0
 */
class ExpressionService
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
     * Find all occurrences of an expression using MeCab.
     *
     * @param string     $text Text to insert
     * @param string|int $lid  Language ID
     *
     * @return array<int, array{SeID: int, TxID: int, position: int, term: string}>
     */
    public function findMecabExpression(string $text, string|int $lid): array
    {
        $db_to_mecab = tempnam(sys_get_temp_dir(), "lwt_db_to_mecab");
        if ($db_to_mecab === false) {
            throw new \RuntimeException('Failed to create temporary file for MeCab expression search');
        }

        try {
            $mecab_args = " -F %m\\t%t\\t\\n -U %m\\t%t\\t\\n -E \\t\\n ";

            $mecab = $this->textParsingService->getMecabPath($mecab_args);
            $likeText = "%$text%";
            $rows = QueryBuilder::table('sentences')
                ->select(['SeID', 'SeTxID', 'SeFirstPos', 'SeText'])
                ->where('SeLgID', '=', $lid)
                ->where('SeText', 'LIKE', $likeText)
                ->getPrepared();

            $parsed_text = '';
            $fp = fopen($db_to_mecab, 'w');
            if ($fp === false) {
                return [];
            }
            fwrite($fp, $text);
            fclose($fp);
            $handle = popen($mecab . escapeshellarg($db_to_mecab), "r");
            if ($handle === false) {
                return [];
            }
            while (!feof($handle)) {
                $row = fgets($handle, 16132);
                if ($row === false) {
                    break;
                }
                $arr = explode("\t", $row, 4);
                // Not a word (punctuation)
                if (
                    isset($arr[0]) && $arr[0] !== '' && $arr[0] !== "EOP"
                    && isset($arr[1]) && in_array($arr[1], ["2", "6", "7"])
                ) {
                    $parsed_text .= $arr[0] . ' ';
                }
            }
            pclose($handle);

            $occurrences = [];
            // For each sentence in database containing $text
            foreach ($rows as $record) {
                $sent = trim((string) $record['SeText']);
                $fp = fopen($db_to_mecab, 'w');
                if ($fp === false) {
                    continue;
                }
                fwrite($fp, $sent . "\n");
                fclose($fp);

                $handle = popen($mecab . escapeshellarg($db_to_mecab), "r");
                if ($handle === false) {
                    continue;
                }
                $parsed_sentence = '';
                // For each word in sentence
                while (!feof($handle)) {
                    $row = fgets($handle, 16132);
                    if ($row === false) {
                        break;
                    }
                    $arr = explode("\t", $row, 4);
                    // Not a word (punctuation)
                    if (
                        isset($arr[0]) && $arr[0] !== '' && $arr[0] !== "EOP"
                        && isset($arr[1]) && in_array($arr[1], ["2", "6", "7"])
                    ) {
                        $parsed_sentence .= $arr[0] . ' ';
                    }
                }

                // Finally we check if parsed text is in parsed sentence
                $seek = mb_strpos($parsed_sentence, $parsed_text);
                // For each occurrence of multi-word in sentence
                while ($seek !== false) {
                    // pos = Number of words * 2 + initial position
                    $matchCount = preg_match_all('/ /', mb_substr($parsed_sentence, 0, $seek));
                    $pos = ($matchCount !== false ? $matchCount : 0) * 2 +
                    (int) $record['SeFirstPos'];
                    $occurrences[] = [
                        "SeID" => (int) $record['SeID'],
                        "TxID" => (int) $record['SeTxID'],
                        "position" => $pos,
                        "term" => $text
                    ];
                    $seek = mb_strpos($parsed_sentence, $parsed_text, $seek + 1);
                }
                pclose($handle);
            }

            return $occurrences;
        } finally {
            if (file_exists($db_to_mecab)) {
                unlink($db_to_mecab);
            }
        }
    }

    /**
     * Find all occurrences of an expression, do not use parsers like MeCab.
     *
     * @param string     $textlc Text to insert in lower case
     * @param string|int $lid    Language ID
     *
     * @return array<int, array{SeID: int, SeTxID: int, position: int, term: ?string, term_display: ?string}>
     */
    public function findStandardExpression(string $textlc, string|int $lid): array
    {
        $occurrences = [];
        $record = QueryBuilder::table('languages')
            ->where('LgID', '=', $lid)
            ->getPrepared()[0] ?? null;

        if ($record === null) {
            return $occurrences;
        }

        $removeSpaces = $record["LgRemoveSpaces"] == 1;
        $splitEachChar = $record['LgSplitEachChar'] != 0;
        $termchar = (string)$record['LgRegexpWordCharacters'];
        $likeTextlc = "%$textlc%";
        if ($removeSpaces && !$splitEachChar) {
            // Complex JOIN query - use raw SQL with UserScopedQuery
            $bindings = [$lid, $likeTextlc];
            $sql = "SELECT
            GROUP_CONCAT(Ti2Text ORDER BY Ti2Order SEPARATOR ' ') AS SeText, SeID,
            SeTxID, SeFirstPos, SeTxID
            FROM word_occurrences
            JOIN sentences
            ON SeID=Ti2SeID AND SeLgID = Ti2LgID
            WHERE Ti2LgID = ?
            AND SeText LIKE ?
            AND Ti2WordCount < 2
            GROUP BY SeID";
            $rows = Connection::preparedFetchAll($sql, $bindings);
        } else {
            $rows = QueryBuilder::table('sentences')
                ->where('SeLgID', '=', $lid)
                ->where('SeText', 'LIKE', $likeTextlc)
                ->getPrepared();
        }

        if ($splitEachChar) {
            $textlc = (string) preg_replace('/([^\s])/u', "$1 ", $textlc);
        }
        $wis = $textlc;
        $notermchar = "/[^$termchar]($textlc)[^$termchar]/ui";
        // For each sentence in the language containing the query
        $matches = null;
        $rSflag = false; // Flag to prevent repeat space-removal processing
        foreach ($rows as $record) {
            $string = ' ' . (string)$record['SeText'] . ' ';
            if ($splitEachChar) {
                $replaced = preg_replace('/([^\s])/u', "$1 ", $string);
                $string = $replaced ?? $string;
            } elseif ($removeSpaces && !$rSflag) {
                $patternPart = preg_replace('/(.)/ui', "$1[ ]*", $textlc);
                if ($patternPart !== null) {
                    preg_match(
                        '/(?<=[ ])(' . $patternPart . ')(?=[ ])/ui',
                        $string,
                        $ma
                    );
                    if (isset($ma[1]) && $ma[1] !== '') {
                        $textlc = trim($ma[1]);
                        $notermchar = "/[^$termchar]($textlc)[^$termchar]/ui";
                        $rSflag = true; // Pattern found, stop further processing
                    }
                }
            }
            $last_pos = mb_strripos($string, $textlc, 0, 'UTF-8');
            // For each occurrence of query in sentence
            while ($last_pos !== false) {
                if (
                    $splitEachChar || $removeSpaces
                    || preg_match($notermchar, " $string ", $matches, 0, $last_pos - 1)
                ) {
                    // Number of terms before group
                    $cnt = preg_match_all(
                        "/([$termchar]+)/u",
                        mb_substr($string, 0, $last_pos, 'UTF-8'),
                        $_
                    );
                    $pos = 2 * ($cnt !== false ? $cnt : 0) + (int) $record['SeFirstPos'];
                    $txt = '';
                    $matchedTerm = $matches[1] ?? $textlc;
                    if ($matchedTerm != $textlc) {
                        $txt = $splitEachChar ? $wis : $matchedTerm;
                    }
                    if ($splitEachChar || $removeSpaces) {
                        $display = $wis;
                    } else {
                        $display = $matchedTerm;
                    }
                    $occurrences[] = [
                        "SeID" => (int) $record['SeID'],
                        "SeTxID" => (int) $record['SeTxID'],
                        "position" => $pos,
                        "term" => $txt,
                        "term_display" => $display
                    ];
                }
                // Cut the sentence to before the right-most term starts
                $string = mb_substr($string, 0, $last_pos, 'UTF-8');
                $last_pos = mb_strripos($string, $textlc, 0, 'UTF-8');
            }
        }
        return $occurrences;
    }

    /**
     * Alter the database to add a new word.
     *
     * @param string $textlc Text in lower case
     * @param int    $lid    Language ID
     * @param int    $wid    Word ID
     * @param int    $len    Number of words in the expression
     * @param int    $mode   Function mode
     *                       - 0: Default mode, do nothing special
     *                       - 1: Runs an expression inserter interactable
     *                       - 2: Return prepared statement data for batch insert
     *
     * @return array{placeholders: list<string>, params: list<mixed>}|null
     *         If $mode == 2 returns array with placeholders and params for prepared statement,
     *         null otherwise.
     */
    public function insertExpressions(string $textlc, int $lid, int $wid, int $len, int $mode): array|null
    {
        $regexp = (string)(QueryBuilder::table('languages')
            ->where('LgID', '=', $lid)
            ->valuePrepared('LgRegexpWordCharacters') ?? '');

        if (WordSpacing::usesMecabMagicWord($regexp)) {
            $occurrences = $this->findMecabExpression($textlc, $lid);
        } else {
            $occurrences = $this->findStandardExpression($textlc, $lid);
        }

        // Update the term visually through JS
        if ($mode == 0) {
            /** @var array<int, array<int, string>> $appendtext */
            $appendtext = [];
            foreach ($occurrences as $occ) {
                $txId = $occ['SeTxID'] ?? $occ['TxID'] ?? 0;
                $appendtext[$txId] = [];
                if (Settings::getZeroOrOne('showallwords', 1)) {
                    // A literal non-breaking space, not the entity: the client
                    // sets this as text content, so markup would show verbatim.
                    $appendtext[$txId][$occ['position']] = "\u{00A0}$len\u{00A0}";
                } else {
                    if (WordSpacing::usesMecabMagicWord($regexp)) {
                        $appendtext[$txId][$occ['position']] = $occ['term'] ?? '';
                    } else {
                        $appendtext[$txId][$occ['position']] = $occ['term_display'] ?? $occ['term'] ?? '';
                    }
                }
            }
            $hex = StringUtils::toClassName(Escaping::prepareTextdata($textlc));
            $this->newMultiWordInteractable($hex, $appendtext, $wid, $len);
        }
        if (!empty($occurrences)) {
            $placeholders = [];
            $params = [];
            foreach ($occurrences as $occ) {
                $txId = $occ["SeTxID"] ?? $occ["TxID"] ?? 0;
                $placeholders[] = "(?, ?, ?, ?, ?, ?, ?)";
                $params[] = $wid;
                $params[] = $lid;
                $params[] = $txId;
                $params[] = $occ["SeID"];
                $params[] = $occ["position"];
                $params[] = $len;
                $params[] = $occ["term"];
            }

            if ($mode == 2) {
                // Return prepared statement data for batch insert
                return ['placeholders' => $placeholders, 'params' => $params];
            }

            $sql = "INSERT INTO word_occurrences
                 (Ti2WoID,Ti2LgID,Ti2TxID,Ti2SeID,Ti2Order,Ti2WordCount,Ti2Text)
                 VALUES " . implode(',', $placeholders);
            Connection::preparedExecute($sql, $params);
        }
        return null;
    }

    /**
     * Prepare a JavaScript dialog to insert a new expression.
     *
     * @param string     $hex        Lowercase text, formatted version of the text.
     * @param array<int, array<int, string>> $multiwords Multi-words to append, format [textid][position][text]
     * @param int        $wid        Term ID
     * @param int        $len        Words count.
     *
     * @return void
     */
    public function newMultiWordInteractable(string $hex, array $multiwords, int $wid, int $len): void
    {
        $showAll = (bool)Settings::getZeroOrOne('showallwords', 1);
        $showType = $showAll ? "m" : "";

        $record = QueryBuilder::table('words')
            ->where('WoID', '=', $wid)
            ->getPrepared()[0] ?? null;

        $woStatus = (int)($record["WoStatus"] ?? 1);
        $attrs = [
            "class" => "click mword {$showType}wsty word$wid status" . $woStatus,
            "data_hex" => $hex,
            "data_trans" => (string)($record["WoTranslation"] ?? ''),
            "data_rom" => (string)($record["WoRomanization"] ?? ''),
            "data_code" => $len,
            "data_status" => $woStatus,
            "data_wid" => $wid
        ];

        ?>
<script type="application/json" data-lwt-multiword-config>
        <?php echo json_encode([
        'attrs' => $attrs,
        'multiWords' => $multiwords,
        'hex' => $hex,
        'showAll' => $showAll
        ], JSON_HEX_TAG | JSON_HEX_AMP); ?>
</script>
        <?php
        flush();
    }

    /**
     * Prepare a JavaScript dialog to insert a new expression (version 2).
     *
     * @param string   $hex        Lowercase text, formatted version of the text.
     * @param string[] $appendtext Text to append
     * @param int      $wid        Term ID
     * @param int      $len        Words count.
     *
     * @return void
     */
    public function newExpressionInteractable2(string $hex, array $appendtext, int $wid, int $len): void
    {
        $showAll = (bool)Settings::getZeroOrOne('showallwords', 1);
        $showType = $showAll ? "m" : "";

        $record = QueryBuilder::table('words')
            ->where('WoID', '=', $wid)
            ->getPrepared()[0] ?? null;

        $woStatus = (int)($record["WoStatus"] ?? 1);
        $attrs = [
            "class" => "click mword {$showType}wsty word$wid status" . $woStatus,
            "data_hex" => $hex,
            "data_trans" => (string)($record["WoTranslation"] ?? ''),
            "data_rom" => (string)($record["WoRomanization"] ?? ''),
            "data_code" => $len,
            "data_status" => $woStatus,
            "data_wid" => $wid
        ];

        $term = array_values($appendtext)[0];

        ?>
<script type="application/json" data-lwt-expression-config>
        <?php echo json_encode([
        'attrs' => $attrs,
        'appendText' => $appendtext,
        'term' => $term,
        'len' => $len,
        'hex' => $hex,
        'showAll' => $showAll
        ], JSON_HEX_TAG | JSON_HEX_AMP); ?>
</script>
        <?php
        flush();
    }
}
