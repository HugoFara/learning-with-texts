<?php

/**
 * Text Parsing Service - Text parsing utilities.
 *
 * Functions for parsing text, including MeCab integration for Japanese
 * and sentence boundary detection for Latin scripts.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Language\Application\Services
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0 Migrated from Core/Text/text_parsing.php
 */

declare(strict_types=1);

namespace Lwt\Modules\Language\Application\Services;

/**
 * Service class for text parsing operations.
 *
 * @category Lwt
 * @package  Lwt\Modules\Language\Application\Services
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */
class TextParsingService
{
    /**
     * Returns path to the MeCab application.
     * MeCab can split Japanese text word by word
     *
     * @param string $mecabArgs Arguments to add
     *
     * @return string OS-compatible command
     *
     * @since 2.3.1-fork Much more verifications added
     * @since 3.0.0 Support for Mac OS added
     */
    public function getMecabPath(string $mecabArgs = ''): string
    {
        $os = strtoupper(PHP_OS);
        $mecabArgs = escapeshellcmd($mecabArgs);
        if (str_starts_with($os, 'LIN') || str_starts_with($os, 'DAR')) {
            /** @psalm-suppress ForbiddenCode - Necessary for MeCab detection */
            if (shell_exec("command -v mecab") !== null) {
                return 'mecab' . $mecabArgs;
            }
            throw new \RuntimeException(
                "MeCab not detected on Linux/macOS. " .
                "Please install MeCab or add it to your PATH. " .
                "See: https://hugofara.github.io/lwt/guide/installation"
            );
        }
        if (str_starts_with($os, 'WIN')) {
            /** @psalm-suppress ForbiddenCode - Necessary for MeCab detection */
            if (shell_exec('where /R "%ProgramFiles%\\MeCab\\bin" mecab.exe') !== null) {
                return '"%ProgramFiles%\\MeCab\\bin\\mecab.exe"' . $mecabArgs;
            }
            /** @psalm-suppress ForbiddenCode - Necessary for MeCab detection */
            if (shell_exec('where /R "%ProgramFiles(x86)%\\MeCab\\bin" mecab.exe') !== null) {
                return '"%ProgramFiles(x86)%\\MeCab\\bin\\mecab.exe"' . $mecabArgs;
            }
            /** @psalm-suppress ForbiddenCode - Necessary for MeCab detection */
            if (shell_exec('where mecab.exe') !== null) {
                return 'mecab.exe' . $mecabArgs;
            }
            throw new \RuntimeException(
                "MeCab not detected on Windows. " .
                "Please install MeCab or add it to your PATH. " .
                "See: https://hugofara.github.io/lwt/guide/installation"
            );
        }
        throw new \RuntimeException(
            "Unsupported operating system '$os' for MeCab integration. " .
            "MeCab is only supported on Linux, macOS, and Windows."
        );
    }

    /**
     * Find end-of-sentence characters in a sentence using latin alphabet.
     *
     * @param string[] $matches       All the matches from a capturing regex
     * @param string   $noSentenceEnd If different from '', can declare that a string is not the end of a sentence.
     *
     * @return string $matches[0] with ends of sentences marked with \t and \r.
     */
    public function findLatinSentenceEnd(array $matches, string $noSentenceEnd): string
    {
        // Handle potentially null values in $matches array
        $match6 = $matches[6] ?? '';
        $match7 = $matches[7] ?? '';

        if (!strlen($match6) && strlen($match7) && preg_match('/[a-zA-Z0-9]/', substr($matches[1], -1))) {
            $result = preg_replace("/[.]/", ".\t", $matches[0]);
            return $result ?? $matches[0];
        }
        if (is_numeric($matches[1])) {
            if (strlen($matches[1]) < 3) {
                return $matches[0];
            }
        } elseif (
            $matches[3] && (
                preg_match('/^[B-DF-HJ-NP-TV-XZb-df-hj-np-tv-xz][b-df-hj-np-tv-xzñ]*$/u', $matches[1])
                || preg_match('/^[AEIOUY]$/', $matches[1])
            )
        ) {
            return $matches[0];
        }
        if (preg_match('/[.:]/', $matches[2]) && preg_match('/^[a-z]/', $match7)) {
            return $matches[0];
        }
        if ($noSentenceEnd != '' && preg_match("/^($noSentenceEnd)$/", $matches[0])) {
            return $matches[0];
        }
        return $matches[0] . "\r";
    }
}
