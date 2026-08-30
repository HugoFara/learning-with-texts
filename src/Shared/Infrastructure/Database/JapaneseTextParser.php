<?php

/**
 * \file
 * \brief Japanese text parsing with MeCab.
 *
 * PHP version 8.1
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

use Lwt\Modules\Language\Application\Services\TextParsingService;

/**
 * Japanese text parsing using MeCab.
 *
 * Handles splitting, previewing, and tokenization for Japanese text. Produces
 * ParsedToken objects consumed by TokenPersistence — no scratch table involved.
 *
 * @since 3.0.0
 */
class JapaneseTextParser
{
    /**
     * Split Japanese text into sentences (split-only mode).
     *
     * @param string $text Preprocessed text
     *
     * @return string[] Array of sentences
     *
     * @psalm-return non-empty-list<string>
     */
    public static function splitJapaneseSentences(string $text): array
    {
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = trim($text);
        $text = preg_replace("/[\n]+/u", "\n¶", $text) ?? $text;
        return explode("\n", $text);
    }

    /**
     * The text as the preview shows it, with runs of whitespace collapsed.
     *
     * The data behind {@see displayJapanesePreview()}.
     *
     * @param string $text Preprocessed text (substitutions applied)
     *
     * @return string Preview text
     */
    public static function previewText(string $text): string
    {
        return trim(preg_replace('/[ \t]+/u', ' ', $text) ?? $text);
    }

    /**
     * Display preview HTML for Japanese text.
     *
     * @param string $text Preprocessed text
     *
     * @return void
     */
    public static function displayJapanesePreview(string $text): void
    {
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = trim($text);
        echo '<div id="check_text" style="margin-right:50px;">
        <h2>Text</h2>
        <p>' . str_replace("\n", "<br /><br />", \htmlspecialchars($text, ENT_QUOTES, 'UTF-8')) . '</p>';
    }

    /**
     * Tokenize Japanese text with MeCab into ParsedToken objects.
     *
     * @param string $text Preprocessed text (character substitutions applied)
     *
     * @return ParsedToken[]
     */
    public static function tokenize(string $text): array
    {
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = trim($text);

        $file_name = tempnam(sys_get_temp_dir(), "tmpti");
        if ($file_name === false) {
            throw new \RuntimeException('Failed to create temporary file for MeCab parsing');
        }

        try {
            // We use the format "word\ttype\tnode" for all nodes.
            $mecab_args = " -F %m\\t%t\\t%h\\n -U %m\\t%t\\t%h\\n -E EOP\\t3\\t7\\n";
            $mecab_args .= " -o " . escapeshellarg($file_name) . " ";
            $mecab = (new TextParsingService())->getMecabPath($mecab_args);

            // WARNING: \n is converted to PHP_EOL here!
            $handle = popen($mecab, 'w');
            if ($handle !== false) {
                fwrite($handle, $text);
                pclose($handle);
            }

            $handle = fopen($file_name, 'r');
            $mecabed = '';
            if ($handle !== false) {
                $size = filesize($file_name);
                if ($size !== false && $size > 0) {
                    $result = fread($handle, $size);
                    $mecabed = $result !== false ? $result : '';
                }
                fclose($handle);
            }
            return self::buildTokensFromMecab($mecabed);
        } finally {
            if (file_exists($file_name)) {
                unlink($file_name);
            }
        }
    }

    /**
     * Convert raw MeCab output into ParsedToken objects.
     *
     * Kept separate from the MeCab subprocess call so it can be unit-tested with
     * captured/synthetic MeCab output. Mirrors the former SQL pipeline: build
     * staging rows, drop the final EOP order group, then group rows sharing a
     * TiOrder into one token (text concatenated in TiCount order).
     *
     * @param string $mecabed Raw MeCab output ("word\ttype\tnode" per line)
     *
     * @return ParsedToken[]
     */
    public static function buildTokensFromMecab(string $mecabed): array
    {
        /** @var array<int, array{0:int,1:int,2:int,3:string,4:int}> $values */
        $values = array();
        $order = 0;
        $sid = 1;
        $term_type = 0;
        $last_node_type = 0;
        $count = 0;
        $separator = mb_chr(9);
        if ($separator === false) {
            $separator = "\t";
        }
        // MeCab's line terminator comes from MeCab, not from the host PHP runs
        // on, so splitting on PHP_EOL broke this everywhere PHP_EOL is not
        // "\n": on Windows the whole output stayed a single line and every
        // token collapsed into one. Normalise first, then split on "\n".
        $mecabed = str_replace(["\r\n", "\r"], "\n", $mecabed);
        foreach (explode("\n", $mecabed) as $line) {
            if (trim($line) == "") {
                continue;
            }
            $parts = explode($separator, $line);
            $term = $parts[0] ?? '';
            $node_type = $parts[1] ?? '';
            $third = $parts[2] ?? '';
            if ($term_type == 2 || $term == 'EOP' && $third == '7') {
                $sid += 1;
            }
            $tiSeID = $sid;
            $tiCount = $count + 1;
            $count += mb_strlen($term);
            $last_term_type = $term_type;
            if ($third == '7') {
                if ($term == 'EOP') {
                    $term = '¶';
                }
                $term_type = 2;
            } elseif (in_array($node_type, ['2', '6', '7', '8'])) {
                $term_type = 0;
            } else {
                $term_type = 1;
            }

            // Increase word order:
            // Once if the current or the previous term were words
            // Twice if current or the previous were not of unmanaged type
            $order += (int)($term_type == 0 && $last_term_type == 0) +
                (int)($term_type != 1 || $last_term_type != 1);
            $tiWordCount = $term_type == 0 ? 1 : 0;
            $values[] = array($tiSeID, $tiCount, $order, $term, $tiWordCount);

            // Special case for kazu (numbers): merge consecutive number nodes.
            if ($last_node_type == 8 && $node_type == 8) {
                $lastKey = array_key_last($values);
                if ($lastKey > 0 && isset($values[$lastKey - 1][3])) {
                    $values[$lastKey - 1][3] = $values[$lastKey - 1][3] . $term;
                    array_pop($values);
                }
            }
            $last_node_type = $node_type;
        }

        // Group staging rows by TiOrder, dropping the final EOP order group.
        // (This replaces "DELETE WHERE TiOrder=@order" + "GROUP BY TiOrder".)
        /** @var array<int, list<array{0:int,1:int,2:int,3:string,4:int}>> $groups */
        $groups = array();
        foreach ($values as $r) {
            if ($r[2] === $order) {
                continue;
            }
            $groups[$r[2]][] = $r;
        }
        ksort($groups);

        $tokens = array();
        foreach ($groups as $ord => $rows) {
            // Concatenate merged node text in TiCount order.
            usort($rows, fn(array $a, array $b): int => $a[1] <=> $b[1]);
            $text = '';
            foreach ($rows as $rr) {
                $text .= $rr[3];
            }
            $tokens[] = new ParsedToken($rows[0][0], $ord, $rows[0][4], $text);
        }
        return $tokens;
    }
}
