<?php

/**
 * Whether a language writes spaces between its words.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Language\Domain
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.7.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Language\Domain;

/**
 * Whether a language separates its words with spaces.
 *
 * `LgRegexpWordCharacters` once could hold the literal marker `MECAB` instead
 * of a regex, and that marker said two unrelated things at once: *tokenize with
 * MeCab*, and *this language has no spaces between words*. Nothing else said
 * the second, so every site that needed to know about spacing asked about the
 * marker instead — six times in `SentenceService` alone, each spelling the
 * comparison out by hand (#288).
 *
 * This class is the home that fact was missing. 3.7.0 moved it onto
 * `LgRemoveSpaces` (`20260831_120000_retire_mecab_magic_word.sql`), and 3.8.0
 * stopped reading the marker altogether: the word-characters field now holds a
 * regex and nothing else.
 *
 * @since 3.7.0
 */
final class WordSpacing
{
    /**
     * Whether the language puts spaces between its words.
     *
     * False for a language written without spaces — Japanese, Chinese and
     * anything else whose words are found by a tokenizer rather than by looking
     * for the gaps. Those texts are held with zero-width markers between tokens
     * and are rendered by stripping the markers; a space-separated language has
     * its markers turned back into real spaces instead.
     *
     * @param bool $removeSpaces  `LgRemoveSpaces` — spaces are stripped
     * @param bool $splitEachChar `LgSplitEachChar` — every character a word
     *
     * @return bool True when words are separated by spaces
     */
    public static function separatesWordsWithSpaces(
        bool $removeSpaces,
        bool $splitEachChar
    ): bool {
        return !$removeSpaces && !$splitEachChar;
    }
}
