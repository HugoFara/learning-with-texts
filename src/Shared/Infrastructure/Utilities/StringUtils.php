<?php

/**
 * \file
 * \brief String manipulation utilities.
 *
 * Static methods for string encoding, escaping, and transformation.
 *
 * PHP version 8.2
 *
 * @category Core
 * @package  Lwt
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Shared\Infrastructure\Utilities;

use Lwt\Shared\Infrastructure\Database\Settings;

/**
 * String manipulation utilities.
 *
 * Provides static methods for encoding strings, creating CSS class names,
 * and handling translation separators.
 *
 * @since 3.0.0
 */
class StringUtils
{
    /**
     * Cached separators value (preg_quote'd).
     *
     * @var string|null
     */
    private static ?string $separators = null;

    /**
     * Cached first separator character.
     *
     * @var string|null
     */
    private static ?string $firstSeparator = null;

    /**
     * Convert a string to hexadecimal representation.
     *
     * @param string $string String to convert
     *
     * @return string Uppercase hexadecimal string
     */
    public static function toHex(string $string): string
    {
        $hex = '';
        for ($i = 0; $i < strlen($string); $i++) {
            $h = dechex(ord($string[$i]));
            if (strlen($h) == 1) {
                $hex .= "0" . $h;
            } else {
                $hex .= $h;
            }
        }
        return strtoupper($hex);
    }

    /**
     * Derive an opaque identity token for a term's lowercase text.
     *
     * Used in the reading view as the `data_hex` attribute so every occurrence of
     * the same term can be restyled in one pass when its status changes. The token
     * is computed per render and never stored or reversed back to text, so a short
     * one-way hash is sufficient. A pure `[0-9a-f]` hash is also selector-safe — no
     * `CSS.escape` needed — and is recomputed identically on both the PHP and JS
     * sides, so the API `hex` field keeps its exact role.
     *
     * @param string $string String to derive the token from (typically WoTextLC)
     *
     * @return string 16-character (64-bit) lowercase hex token
     */
    public static function toClassName(string $string): string
    {
        return substr(hash('sha256', $string), 0, 16);
    }

    /**
     * Get the translation separators pattern for regex use.
     *
     * Returns the separator characters from settings, escaped for use
     * in preg_split and similar functions.
     *
     * @return string Preg-quoted separator characters
     */
    public static function getSeparators(): string
    {
        if (self::$separators === null) {
            self::$separators = preg_quote(
                Settings::getWithDefault('set-term-translation-delimiters'),
                '/'
            );
        }
        return self::$separators;
    }

    /**
     * Get the first translation separator character.
     *
     * @return string First separator character
     */
    public static function getFirstSeparator(): string
    {
        if (self::$firstSeparator === null) {
            self::$firstSeparator = mb_substr(
                Settings::getWithDefault('set-term-translation-delimiters'),
                0,
                1,
                'UTF-8'
            );
        }
        return self::$firstSeparator;
    }

    /**
     * Reset cached separator values.
     *
     * Call this if settings change during runtime.
     *
     * @return void
     */
    public static function resetSeparatorCache(): void
    {
        self::$separators = null;
        self::$firstSeparator = null;
    }

    /**
     * Remove soft hyphens from a string.
     *
     * @param string $str Input string
     *
     * @return string String without soft hyphens
     */
    public static function removeSoftHyphens(string $str): string
    {
        // Soft hyphen is 0xC2 0xAD (­)
        return str_replace('­', '', $str);
    }

    /**
     * Create a counter string with total (e.g., "01/10").
     *
     * @param int $max Total count
     * @param int $num Current number
     *
     * @return string Formatted counter string
     */
    public static function makeCounterWithTotal(int $max, int $num): string
    {
        if ($max == 1) {
            return '';
        }
        if ($max < 10) {
            return $num . "/" . $max;
        }
        return substr(
            str_repeat("0", strlen((string)$max)) . $num,
            -strlen((string)$max)
        ) . "/" . $max;
    }

    /**
     * Encode a URI string matching JavaScript's encodeURI behavior.
     *
     * @param string $url URL to encode
     *
     * @return string Encoded URL
     */
    public static function encodeURI(string $url): string
    {
        $reserved = [
            '%2D' => '-', '%5F' => '_', '%2E' => '.', '%21' => '!',
            '%2A' => '*', '%27' => "'", '%28' => '(', '%29' => ')'
        ];
        $unescaped = [
            '%3B' => ';', '%2C' => ',', '%2F' => '/', '%3F' => '?', '%3A' => ':',
            '%40' => '@', '%26' => '&', '%3D' => '=', '%2B' => '+', '%24' => '$'
        ];
        $score = ['%23' => '#'];
        return strtr(rawurlencode($url), array_merge($reserved, $unescaped, $score));
    }

    /**
     * Get the path of a file using the theme directory.
     *
     * Maps legacy paths to new asset locations:
     * - css/* -> dist/css/*
     * - icn/* -> assets/icons/*
     * - img/* -> assets/images/*
     * - js/* -> dist/js/*
     *
     * @param string $filename Filename
     *
     * @return string File path if it exists, otherwise the filename
     */
    public static function getFilePath(string $filename): string
    {
        // Legacy path mappings
        $mappings = [
            'css/' => 'dist/css/',
            'icn/' => 'assets/icons/',
            'img/' => 'assets/images/',
            'js/' => 'dist/js/',
            'sounds/' => 'assets/sounds/',
        ];

        // Normalize the path (remove leading slash if present)
        $normalizedPath = ltrim($filename, '/');

        // Apply legacy path mappings
        foreach ($mappings as $oldPrefix => $newPrefix) {
            if (str_starts_with($normalizedPath, $oldPrefix)) {
                $normalizedPath = $newPrefix . substr($normalizedPath, strlen($oldPrefix));
                break;
            }
        }

        // Check if theme has an override for this file (for CSS/icons)
        $themeDir = Settings::getWithDefault('set-theme-dir');
        if ($themeDir) {
            $basename = basename($normalizedPath);
            $themePath = $themeDir . $basename;
            if (file_exists($themePath)) {
                // Return absolute path for clean URL compatibility
                return '/' . $themePath;
            }
        }

        // Check if the file exists at the normalized path
        if (file_exists($normalizedPath)) {
            return '/' . $normalizedPath;
        }

        // Return the normalized path even if file doesn't exist
        // (let the browser/router handle 404)
        return '/' . $normalizedPath;
    }

    /**
     * Echo the path of a file using the theme directory.
     *
     * @param string $filename Filename
     *
     * @return void
     */
    public static function printFilePath(string $filename): void
    {
        echo self::getFilePath($filename);
    }

    /**
     * Remove all spaces from a string.
     *
     * @param string          $s      Input string
     * @param string|bool|int $remove Do not do anything if empty, false, or 0
     *
     * @return string String without spaces if requested
     */
    public static function removeSpaces(string $s, string|bool|int $remove): string
    {
        if ($remove === false || $remove === 0 || $remove === '' || $remove === '0') {
            return $s;
        }
        // '' contains &#x200B; (zero-width space)
        return str_replace(' ', '', $s);
    }

    /**
     * Replace the first occurrence of $needle in $haystack with $replace.
     *
     * @param string $needle   Text to replace
     * @param string $replace  Replacement text
     * @param string $haystack Input string
     *
     * @return string String with first occurrence replaced
     */
    public static function replaceFirst(string $needle, string $replace, string $haystack): string
    {
        if ($needle === '') {
            return $haystack;
        }
        $pos = strpos($haystack, $needle);
        if ($pos !== false) {
            return substr_replace($haystack, $replace, $pos, strlen($needle));
        }
        return $haystack;
    }

    /**
     * Parse inline Markdown to HTML.
     *
     * Supports: **bold**, *italic*, [links](url), ~~strikethrough~~
     *
     * Security:
     * - HTML is escaped before parsing (XSS prevention)
     * - Only http/https/relative URLs allowed in links
     * - Generated tags: <strong>, <em>, <del>, <a>
     *
     * @param string $text Input text with Markdown
     *
     * @return string HTML string
     */
    public static function parseInlineMarkdown(string $text): string
    {
        if (empty($text)) {
            return '';
        }

        // Step 1: Escape HTML first (security)
        $result = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        // Step 2: Links [text](url) - sanitize URLs
        $result = preg_replace_callback(
            '/\[([^\]]+)\]\(([^)]+)\)/',
            function (array $matches): string {
                $linkText = $matches[1];
                $url = trim($matches[2]);

                // Only allow http, https, and relative URLs
                if (preg_match('#^(https?://|/|\./|\.\./.)#i', $url)) {
                    $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
                    return '<a href="' . $safeUrl . '" target="_blank" rel="noopener noreferrer">' . $linkText . '</a>';
                }

                // Block dangerous protocols (javascript:, data:, etc.)
                return $linkText;
            },
            $result
        ) ?? $result;

        // Step 3: Bold **text**
        $result = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $result) ?? $result;

        // Step 4: Italic *text* (not preceded/followed by asterisk)
        $result = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $result) ?? $result;

        // Step 5: Strikethrough ~~text~~
        $result = preg_replace('/~~([^~]+)~~/', '<del>$1</del>', $result) ?? $result;

        return $result;
    }
}
