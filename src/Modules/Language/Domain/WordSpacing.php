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
 * Whether a language separates its words with spaces, and the one place that
 * still recognises the `MECAB` magic word.
 *
 * `LgRegexpWordCharacters` is the field that should hold a word-characters
 * regex. Historically it could instead hold the literal marker `MECAB`, which
 * said two unrelated things at once: *tokenize with MeCab*, and *this language
 * has no spaces between words*. `LgParserType` now says the first properly, but
 * nothing else said the second, so every site that needed to know about spacing
 * asked about the magic word instead — six times in `SentenceService` alone,
 * each spelling the comparison out by hand (#288).
 *
 * This class is that missing home. Callers ask whether a language separates its
 * words with spaces; they no longer ask what its word-characters field happens
 * to contain.
 *
 * Spelling the comparison out by hand also went wrong at least once:
 * `GetPhoneticReading` compared against lowercase `mecab` without normalising,
 * so a language whose field held `MECAB` in any other casing silently got no
 * phonetic reading. One predicate, used everywhere, cannot drift like that.
 *
 * @since 3.7.0
 */
final class WordSpacing
{
    /**
     * The literal that `LgRegexpWordCharacters` could hold instead of a regex.
     */
    private const MECAB_MAGIC_WORD = 'MECAB';

    /**
     * Field values already reported this request, used as a set.
     *
     * @var array<string, true>
     */
    private static array $reportedMarkers = [];

    /**
     * Whether a word-characters field holds the MeCab magic word.
     *
     * The magic word itself is what is deprecated, not this predicate: it is
     * the sanctioned way to ask while installs created before `LgParserType`
     * existed still carry it. Readers keep accepting it for one release (#288).
     * New code should ask the parser type instead of calling this, and when the
     * migration has cleared the last of them, deleting the magic word means
     * deleting this method and the one branch of the next one that calls it.
     *
     * Every caller reaches this only where the marker is load-bearing --
     * `separatesWordsWithSpaces()` short-circuits before it unless the two
     * flags are both off, and the parser-choice readers ask it only after
     * `LgParserType` came back empty -- so a notice here means a reader
     * genuinely fell back to the marker, not merely that a row still holds one.
     * That is the distinction the removal in step 5 turns on: a language
     * carrying the marker *and* a parser type loses nothing when the fallback
     * goes, and only the Server Data panel reports those.
     *
     * @param string $wordCharacters Raw `LgRegexpWordCharacters` value
     *
     * @return bool True when the field holds the marker rather than a regex
     */
    public static function usesMecabMagicWord(string $wordCharacters): bool
    {
        if (strtoupper(trim($wordCharacters)) !== self::MECAB_MAGIC_WORD) {
            return false;
        }

        self::noteDeprecatedUse($wordCharacters);

        return true;
    }

    /**
     * Log the first fallback onto the marker for a given field value.
     *
     * Deduplicated because the spacing readers run per sentence: an ordinary
     * page of Japanese would otherwise write one line per sentence, which
     * buries the notice in the noise it creates.
     *
     * @param string $wordCharacters The raw field value that held the marker
     *
     * @return void
     */
    private static function noteDeprecatedUse(string $wordCharacters): void
    {
        if (isset(self::$reportedMarkers[$wordCharacters])) {
            return;
        }
        self::$reportedMarkers[$wordCharacters] = true;

        error_log(
            'Deprecated: a language still stores the MECAB marker in '
            . 'LgRegexpWordCharacters instead of a word-characters regex, and a '
            . 'reader fell back to it. Set LgParserType to "mecab" and '
            . 'LgRemoveSpaces to 1, or re-save the language from the language '
            . 'form. Support for the marker is removed in a future release '
            . '(see issue #288). Value read: "' . $wordCharacters . '"'
        );
    }

    /**
     * Forget which markers have been reported.
     *
     * The deduplication above is per request, and a request is a process here.
     * Tests run many in one process, so they need to draw the line themselves.
     *
     * @return void
     */
    public static function forgetDeprecationNotices(): void
    {
        self::$reportedMarkers = [];
    }

    /**
     * Whether the language puts spaces between its words.
     *
     * False for a language written without spaces — Japanese, Chinese and
     * anything else whose words are found by a tokenizer rather than by looking
     * for the gaps. Those texts are held with zero-width markers between tokens
     * and are rendered by stripping the markers; a space-separated language has
     * its markers turned back into real spaces instead.
     *
     * @param bool   $removeSpaces   `LgRemoveSpaces` — spaces are stripped
     * @param bool   $splitEachChar  `LgSplitEachChar` — every character a word
     * @param string $wordCharacters Raw `LgRegexpWordCharacters` value
     *
     * @return bool True when words are separated by spaces
     */
    public static function separatesWordsWithSpaces(
        bool $removeSpaces,
        bool $splitEachChar,
        string $wordCharacters
    ): bool {
        return !$removeSpaces
            && !$splitEachChar
            && !self::usesMecabMagicWord($wordCharacters);
    }
}
