<?php

/**
 * Verdict on whether a parse produced anything learnable.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Text\Domain
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.5.0
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
 * Feed every token of the text through {@see self::add()} — words and the
 * punctuation and whitespace between them alike — then ask for the
 * {@see self::verdict()}. Callers hand over the material and never the
 * measurements, which is what stops each surface from measuring differently
 * (#289).
 *
 * @since 3.5.0
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
     * Smallest share of a text's letters that a working parse leaves in words.
     *
     * Coverage is judged in letters rather than characters, because letters are
     * the only thing a word can be made of: digits, punctuation, symbols and
     * the spaces between words are not evidence either way, and counting them
     * made the measure depend on how spaced-out a script happens to be.
     *
     * A correctly configured language leaves essentially every letter inside a
     * word, so healthy texts sit near 1.0 whatever their script or length —
     * Latin prose, character-split Chinese and MeCab-segmented Japanese all do.
     * The room below the floor is for legitimately mixed-script writing, such
     * as a French text quoting a Chinese sentence. Four letters in five falling
     * outside any word is not mixed script; it is a language that matched a few
     * stray tokens and missed the text.
     */
    private const COVERAGE_FLOOR = 0.2;

    /**
     * Letters anywhere in the text.
     */
    private int $letters = 0;

    /**
     * Letters that ended up inside a word.
     */
    private int $lettersInWords = 0;

    /**
     * Words the parse produced.
     */
    private int $words = 0;

    /**
     * Account for one token of the parsed text.
     *
     * Every token is passed, not only the words: the non-word runs are what
     * tell us how much of the text the parser could not use.
     *
     * @param string $text   The token's text
     * @param bool   $isWord Whether the parser made a word of it
     *
     * @return void
     */
    public function add(string $text, bool $isWord): void
    {
        $letters = preg_match_all('/\p{L}/u', $text);
        if ($letters === false) {
            $letters = 0;
        }

        $this->letters += $letters;
        if ($isWord) {
            $this->words++;
            $this->lettersInWords += $letters;
        }
    }

    /**
     * Judge the parse by what it produced.
     *
     * @return self::OK|self::NO_WORDS|self::ALMOST_NO_WORDS
     */
    public function verdict(): string
    {
        if ($this->letters === 0) {
            // A price list, a date, a numbers drill or a line of punctuation
            // holds nothing a word could be made of, so a parse that produced
            // no words got it right. Telling the reader their word-characters
            // setting is wrong would be a confident false diagnosis of a
            // correctly configured language (#289).
            return self::OK;
        }

        if ($this->words === 0) {
            // Letters are there and not one became a word: the word-characters
            // setting really does not match this text.
            return self::NO_WORDS;
        }

        if ($this->lettersInWords / $this->letters < self::COVERAGE_FLOOR) {
            return self::ALMOST_NO_WORDS;
        }

        return self::OK;
    }

    /**
     * Whether a verdict is worth telling the reader about.
     *
     * @param string $verdict A verdict from verdict()
     *
     * @return bool
     */
    public static function isWarning(string $verdict): bool
    {
        return $verdict !== self::OK;
    }
}
