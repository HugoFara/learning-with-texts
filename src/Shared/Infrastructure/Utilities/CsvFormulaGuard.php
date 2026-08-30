<?php

/**
 * CSV Formula Injection Guard
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Shared\Infrastructure\Utilities
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Shared\Infrastructure\Utilities;

/**
 * Escapes cells before they land in a CSV/TSV export.
 *
 * Excel, LibreOffice Calc, and Google Sheets treat any cell whose
 * first character is one of `=`, `+`, `-`, `@`, TAB (\t), or CR (\r)
 * as a formula expression — opening a hostile export evaluates that
 * formula in the user's spreadsheet (CVE-class issue often called
 * "CSV injection" or "formula injection"). User-supplied dictionary
 * entries or term translations starting with those characters would
 * trigger arbitrary formula execution.
 *
 * Mitigation: prepend a single quote (`'`) to any cell that starts
 * with one of those characters. The quote is consumed by Excel as a
 * "treat as text" marker and is invisible in the displayed cell; it
 * does show up in plain-text consumers but that is the lesser evil
 * versus formula execution.
 *
 * @since 3.0.0
 */
final class CsvFormulaGuard
{
    /**
     * Characters that, when leading, cause Excel/Calc/Sheets to treat
     * the cell as a formula. \x09 = TAB, \x0D = CR.
     */
    private const DANGEROUS_LEADING = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Escape a single cell value before writing it to a CSV/TSV row.
     *
     * Empty strings are returned untouched. The escape is one byte —
     * `'` is prepended — which spreadsheet apps recognize as a
     * "force text" marker. Safe to call on already-escaped values
     * (the leading `'` itself isn't in the dangerous set).
     *
     * @param string $cell Raw cell value
     *
     * @return string Cell with leading single quote if it would
     *                otherwise trigger a formula
     */
    public static function escapeCell(string $cell): string
    {
        if ($cell === '') {
            return $cell;
        }
        if (in_array($cell[0], self::DANGEROUS_LEADING, true)) {
            return "'" . $cell;
        }
        return $cell;
    }
}
