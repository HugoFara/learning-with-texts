<?php

/**
 * Verdict on whether a parse produced anything learnable.
 *
 * PHP version 8.1
 *
 * @category Lwt
 * @package  Lwt\Modules\Text\Domain
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.4.3
 */

declare(strict_types=1);

namespace Lwt\Modules\Text\Domain;

/**
 * How much of a text the parser managed to turn into words.
 *
 * A language whose word characters do not match the script of its texts does
 * not fail: it parses successfully into nothing. The text saves, opens and
 * renders — every character is there — but not one of them can be clicked,
 * looked up or learned, and nothing anywhere says why. That is what a reader
 * meets when a Chinese language is left on the Latin defaults, and it is
 * indistinguishable from a text that has simply gone inert.
 *
 * This is the one place that decides a parse came out empty, so that the
 * reading view, the check-text page and the API all agree on when to say so.
 *
 * @since 3.4.3
 */
final class ParseCoverage
{
    /**
     * The parse produced words; nothing to report.
     */
    public const OK = 'ok';

    /**
     * Not one word came out of the text.
     */
    public const NO_WORDS = 'no_words';

    /**
     * Words came out, but far too few for the text to be readable.
     */
    public const ALMOST_NO_WORDS = 'almost_no_words';

    /**
     * Shortest text worth judging on its word density.
     *
     * A handful of characters can legitimately hold a single word, so the
     * density test only applies once there is enough text to be sure.
     */
    private const DENSITY_MIN_CHARACTERS = 200;

    /**
     * Fewest words per character a real language ever produces.
     *
     * One word per fifty characters. Prose in a Latin script runs nearer one
     * per six, and a character-split script nearer one per one, so anything
     * under this is a language that matched a few stray tokens — digits, or a
     * Latin fragment in a non-Latin text — and missed the rest.
     */
    private const DENSITY_FLOOR = 0.02;

    /**
     * Judge a parse by what it produced.
     *
     * @param int $wordCount      Words the parse produced
     * @param int $characterCount Characters in the text that was parsed
     *
     * @return self::OK|self::NO_WORDS|self::ALMOST_NO_WORDS
     */
    public static function assess(int $wordCount, int $characterCount): string
    {
        if ($characterCount <= 0) {
            // An empty text is empty; that is not a parsing problem
            return self::OK;
        }

        if ($wordCount <= 0) {
            return self::NO_WORDS;
        }

        if (
            $characterCount >= self::DENSITY_MIN_CHARACTERS
            && $wordCount / $characterCount < self::DENSITY_FLOOR
        ) {
            return self::ALMOST_NO_WORDS;
        }

        return self::OK;
    }

    /**
     * Whether a verdict is worth telling the reader about.
     *
     * @param string $verdict A verdict from assess()
     *
     * @return bool
     */
    public static function isWarning(string $verdict): bool
    {
        return $verdict !== self::OK;
    }
}
