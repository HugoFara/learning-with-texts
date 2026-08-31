<?php

/**
 * Export Service - Business logic for exporting vocabulary data
 *
 * This service handles:
 * - Anki format exports with RTL support and masked sentences
 * - TSV (Tab-Separated Values) exports
 * - Flexible template-based exports with placeholder substitution
 * - Text normalization for export (tabs, newlines, whitespace)
 * - Term masking in sentences for cloze deletion
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
use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Utilities\CsvFormulaGuard;

/**
 * Service class for exporting vocabulary data.
 *
 * Provides methods for exporting terms in various formats:
 * - Anki (with RTL support and sentence masking)
 * - TSV (simple tab-separated format)
 * - Flexible (customizable template-based format)
 *
 * @category Lwt
 * @package  Lwt\Modules\Vocabulary\Application\Services
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */
class ExportService
{
    // =========================================================================
    // Export Methods (with HTTP response)
    // =========================================================================

    /**
     * Export terms to Anki format and send as download.
     *
     * @param string $sql    SQL query to retrieve terms
     * @param array  $params Prepared statement parameters
     *
     * @return never
     */
    public function exportAnki(string $sql, array $params = []): never
    {
        $content = $this->generateAnkiContent($sql, $params);
        $this->sendDownloadResponse(
            $content,
            'lwt_anki_export_' . date('Y-m-d-H-i-s') . '.txt'
        );
    }

    /**
     * Export terms to TSV format and send as download.
     *
     * @param string $sql    SQL query to retrieve terms
     * @param array  $params Prepared statement parameters
     *
     * @return never
     */
    public function exportTsv(string $sql, array $params = []): never
    {
        $content = $this->generateTsvContent($sql, $params);
        $this->sendDownloadResponse(
            $content,
            'lwt_tsv_export_' . date('Y-m-d-H-i-s') . '.txt'
        );
    }

    /**
     * Export terms using flexible template and send as download.
     *
     * @param string $sql    SQL query to retrieve terms
     * @param array  $params Prepared statement parameters
     *
     * @return never
     */
    public function exportFlexible(string $sql, array $params = []): never
    {
        $content = $this->generateFlexibleContent($sql, $params);
        $this->sendDownloadResponse(
            $content,
            'lwt_flexible_export_' . date('Y-m-d-H-i-s') . '.txt'
        );
    }

    // =========================================================================
    // Content Generation Methods (without HTTP)
    // =========================================================================

    /**
     * Generate Anki export content from SQL query.
     *
     * @param string $sql    SQL query to retrieve terms
     * @param array  $params Prepared statement parameters
     *
     * @return string Anki-formatted export content
     */
    public function generateAnkiContent(string $sql, array $params = []): string
    {
        $results = Connection::preparedFetchAll($sql, $params);
        if (empty($results)) {
            return '';
        }

        $content = '';
        foreach ($results as $record) {
            $content .= $this->formatAnkiRow($record);
        }

        return $content;
    }

    /**
     * Generate TSV export content from SQL query.
     *
     * @param string $sql    SQL query to retrieve terms
     * @param array  $params Prepared statement parameters
     *
     * @return string TSV-formatted export content
     */
    public function generateTsvContent(string $sql, array $params = []): string
    {
        $results = Connection::preparedFetchAll($sql, $params);
        if (empty($results)) {
            return '';
        }

        $content = '';
        foreach ($results as $record) {
            $content .= $this->formatTsvRow($record);
        }

        return $content;
    }

    /**
     * Generate flexible export content from SQL query.
     *
     * @param string $sql    SQL query to retrieve terms
     * @param array  $params Prepared statement parameters
     *
     * @return string Template-formatted export content
     */
    public function generateFlexibleContent(string $sql, array $params = []): string
    {
        $results = Connection::preparedFetchAll($sql, $params);
        if (empty($results)) {
            return '';
        }

        $content = '';
        foreach ($results as $record) {
            $content .= $this->formatFlexibleRow($record);
        }

        return $content;
    }

    // =========================================================================
    // Row Formatting Methods
    // =========================================================================

    /**
     * Format a single record for Anki export.
     *
     * @param array $record Database record
     *
     * @return string Formatted row
     */
    private function formatAnkiRow(array $record): string
    {
        if (WordSpacing::usesMecabMagicWord((string) $record['LgRegexpWordCharacters'])) {
            $termchar = '一-龥ぁ-ヾ';
        } else {
            $termchar = (string)$record['LgRegexpWordCharacters'];
        }

        $rtlScript = (int)$record['LgRightToLeft'] === 1;
        $span1 = ($rtlScript ? '<span dir="rtl">' : '');
        $span2 = ($rtlScript ? '</span>' : '');
        $lpar = ($rtlScript ? ']' : '[');
        $rpar = ($rtlScript ? '[' : ']');

        $rawSentence = (string)$record["WoSentence"];
        $woText = (string)$record["WoText"];

        // If sentence doesn't have {word} markup but contains the word, add it
        if (!str_contains($rawSentence, '{' . $woText . '}') && str_contains($rawSentence, $woText)) {
            $rawSentence = str_replace($woText, '{' . $woText . '}', $rawSentence);
        }

        $sent = htmlspecialchars(self::replaceTabNewline($rawSentence), ENT_QUOTES, 'UTF-8');
        $sent1 = str_replace(
            "{",
            '<span style="font-weight:600; color:#0000ff;">' . $lpar,
            str_replace(
                "}",
                $rpar . '</span>',
                self::maskTermInSentence($sent, $termchar)
            )
        );
        $sent2 = str_replace(
            "{",
            '<span style="font-weight:600; color:#0000ff;">',
            str_replace("}", '</span>', $sent)
        );

        $woTextDisplay = htmlspecialchars(self::replaceTabNewline($woText), ENT_QUOTES, 'UTF-8');
        return $span1 . $woTextDisplay . $span2 . "\t" .
            htmlspecialchars(self::replaceTabNewline((string)$record["WoTranslation"]), ENT_QUOTES, 'UTF-8') . "\t" .
            htmlspecialchars(self::replaceTabNewline((string)$record["WoRomanization"]), ENT_QUOTES, 'UTF-8') . "\t" .
            $span1 . $sent1 . $span2 . "\t" .
            $span1 . $sent2 . $span2 . "\t" .
            htmlspecialchars(self::replaceTabNewline((string)$record["LgName"]), ENT_QUOTES, 'UTF-8') . "\t" .
            htmlspecialchars((string)$record["WoID"], ENT_QUOTES, 'UTF-8') . "\t" .
            htmlspecialchars((string)$record["taglist"], ENT_QUOTES, 'UTF-8') .
            "\r\n";
    }

    /**
     * Format a single record for TSV export.
     *
     * @param array<string, mixed> $record Database record
     *
     * @return string Formatted row
     */
    private function formatTsvRow(array $record): string
    {
        // CsvFormulaGuard prepends a single quote to cells whose first
        // character would trigger formula evaluation in Excel / Calc /
        // Google Sheets (=, +, -, @, TAB, CR). The TSV file is saved
        // with a .txt extension, but users routinely rename and open
        // exports in spreadsheets — without the guard, an attacker
        // could store an =cmd|...!A1 payload in a translation and
        // execute it on import. WoStatus and WoID are numeric, no
        // escaping needed.
        return CsvFormulaGuard::escapeCell(self::replaceTabNewline((string)$record["WoText"])) . "\t" .
            CsvFormulaGuard::escapeCell(self::replaceTabNewline((string)$record["WoTranslation"])) . "\t" .
            CsvFormulaGuard::escapeCell(self::replaceTabNewline((string)$record["WoSentence"])) . "\t" .
            CsvFormulaGuard::escapeCell(self::replaceTabNewline((string)$record["WoRomanization"])) . "\t" .
            (string)($record["WoStatus"] ?? '') . "\t" .
            CsvFormulaGuard::escapeCell(self::replaceTabNewline((string)$record["LgName"])) . "\t" .
            (string)($record["WoID"] ?? '') . "\t" .
            CsvFormulaGuard::escapeCell((string)($record["taglist"] ?? '')) . "\r\n";
    }

    /**
     * Format a single record for flexible export.
     *
     * @param array $record Database record
     *
     * @return string Formatted row using template
     */
    private function formatFlexibleRow(array $record): string
    {
        if (!isset($record['LgExportTemplate'])) {
            return '';
        }

        $woid = (string)$record['WoID'];
        $langname = self::replaceTabNewline((string)$record['LgName']);
        $rtlScript = (int)$record['LgRightToLeft'] === 1;
        $span1 = ($rtlScript ? '<span dir="rtl">' : '');
        $span2 = ($rtlScript ? '</span>' : '');
        $term = self::replaceTabNewline((string)$record['WoText']);
        $term_lc = self::replaceTabNewline((string)$record['WoTextLC']);
        $transl = self::replaceTabNewline((string)$record['WoTranslation']);
        $rom = self::replaceTabNewline((string)$record['WoRomanization']);
        $sent_raw = self::replaceTabNewline((string)$record['WoSentence']);
        $sent = str_replace('{', '', str_replace('}', '', $sent_raw));
        $sent_c = self::maskTermInSentenceV2($sent_raw);
        $sent_d = str_replace('{', '[', str_replace('}', ']', $sent_raw));
        $sent_x = str_replace('{', '{{c1::', str_replace('}', '}}', $sent_raw));
        $sent_y = str_replace(
            '{',
            '{{c1::',
            str_replace('}', '::' . $transl . '}}', $sent_raw)
        );
        $status = (string)$record['WoStatus'];
        $taglist = trim((string)$record['taglist']);

        $output = self::replaceTabNewline((string)$record['LgExportTemplate']);

        // Replace plain text placeholders
        $output = str_replace('%w', $term, $output);
        $output = str_replace('%t', $transl, $output);
        $output = str_replace('%s', $sent, $output);
        $output = str_replace('%c', $sent_c, $output);
        $output = str_replace('%d', $sent_d, $output);
        $output = str_replace('%r', $rom, $output);
        $output = str_replace('%a', $status, $output);
        $output = str_replace('%k', $term_lc, $output);
        $output = str_replace('%z', $taglist, $output);
        $output = str_replace('%l', $langname, $output);
        $output = str_replace('%n', $woid, $output);
        $output = str_replace('%%', '%', $output);

        // Replace HTML-escaped placeholders
        $output = str_replace('$w', $span1 . htmlspecialchars($term, ENT_QUOTES, 'UTF-8') . $span2, $output);
        $output = str_replace('$t', htmlspecialchars($transl, ENT_QUOTES, 'UTF-8'), $output);
        $output = str_replace('$s', $span1 . htmlspecialchars($sent, ENT_QUOTES, 'UTF-8') . $span2, $output);
        $output = str_replace('$c', $span1 . htmlspecialchars($sent_c, ENT_QUOTES, 'UTF-8') . $span2, $output);
        $output = str_replace('$d', $span1 . htmlspecialchars($sent_d, ENT_QUOTES, 'UTF-8') . $span2, $output);
        $output = str_replace('$x', $span1 . htmlspecialchars($sent_x, ENT_QUOTES, 'UTF-8') . $span2, $output);
        $output = str_replace('$y', $span1 . htmlspecialchars($sent_y, ENT_QUOTES, 'UTF-8') . $span2, $output);
        $output = str_replace('$r', htmlspecialchars($rom, ENT_QUOTES, 'UTF-8'), $output);
        $output = str_replace('$k', $span1 . htmlspecialchars($term_lc, ENT_QUOTES, 'UTF-8') . $span2, $output);
        $output = str_replace('$z', htmlspecialchars($taglist, ENT_QUOTES, 'UTF-8'), $output);
        $output = str_replace('$l', htmlspecialchars($langname, ENT_QUOTES, 'UTF-8'), $output);
        $output = str_replace('$$', '$', $output);

        // Replace escape sequences
        $output = str_replace('\t', "\t", $output);
        $output = str_replace('\n', "\n", $output);
        $output = str_replace('\r', "\r", $output);
        $output = str_replace('\\\\', '\\', $output);

        return $output;
    }

    // =========================================================================
    // Text Processing Utilities
    // =========================================================================

    /**
     * Replace all whitespace characters with simple space.
     *
     * Converts tabs, newlines, and multiple spaces to single spaces.
     * The output string is also trimmed.
     *
     * @param string $s String to parse
     *
     * @return string String with normalized whitespace
     */
    public static function replaceTabNewline(string $s): string
    {
        $s = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $s);
        $s = preg_replace('/\s/u', ' ', $s) ?? $s;
        $s = preg_replace('/\s{2,}/u', ' ', $s) ?? $s;
        return trim($s);
    }

    /**
     * Mask the term in a sentence by replacing characters with bullets.
     *
     * Characters within {} brackets matching the regex pattern are replaced
     * with bullet characters (•).
     *
     * @param string $s         Sentence with term marked by {} brackets
     * @param string $regexword Regex pattern for word characters
     *
     * @return string Sentence with term characters replaced by bullets
     */
    public static function maskTermInSentence(string $s, string $regexword): string
    {
        $l = mb_strlen($s, 'utf-8');
        $r = '';
        $on = 0;

        for ($i = 0; $i < $l; $i++) {
            $c = mb_substr($s, $i, 1, 'UTF-8');
            if ($c == '}') {
                $on = 0;
            }
            if ($on) {
                // Empty regex means no characters match, so don't mask anything
                if ($regexword !== '' && preg_match('/[' . $regexword . ']/u', $c)) {
                    $r .= '•';
                } else {
                    $r .= $c;
                }
            } else {
                $r .= $c;
            }
            if ($c == '{') {
                $on = 1;
            }
        }

        return $r;
    }

    /**
     * Mask the term in a sentence by replacing it with "[...]".
     *
     * The entire content within {} brackets is replaced with "[...]".
     *
     * @param string $s Sentence with term marked by {} brackets
     *
     * @return string Sentence with term replaced by "[...]"
     */
    public static function maskTermInSentenceV2(string $s): string
    {
        $l = mb_strlen($s, 'utf-8');
        $r = '';
        $on = 0;

        for ($i = 0; $i < $l; $i++) {
            $c = mb_substr($s, $i, 1, 'UTF-8');
            if ($c == '}') {
                $on = 0;
                continue;
            }
            if ($c == '{') {
                $on = 1;
                $r .= '[...]';
                continue;
            }
            if ($on == 0) {
                $r .= $c;
            }
        }

        return $r;
    }

    // =========================================================================
    // HTTP Response Helpers
    // =========================================================================

    /**
     * Send content as a file download.
     *
     * @param string $content  File content
     * @param string $filename Download filename
     *
     * @return never
     */
    private function sendDownloadResponse(string $content, string $filename): never
    {
        header('Content-type: text/plain; charset=utf-8');
        header("Content-disposition: attachment; filename=" . $filename);
        echo $content;
        exit();
    }
}
