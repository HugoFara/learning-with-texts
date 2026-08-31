<?php

/**
 * Which tokenizer a language asked for.
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
 * Whether a language tokenizes with MeCab, asked of the setting that means it.
 *
 * The other half of what the `MECAB` magic word used to say. `WordSpacing`
 * covers the spacing half; this covers parser choice, which `LgParserType` now
 * holds properly.
 *
 * The two must be separated before the magic word can be cleared out of
 * `LgRegexpWordCharacters`, because a site that asks the field directly stops
 * recognising the language the moment a migration puts a real regex back — it
 * would keep parsing, silently, with the wrong tokenizer. Every parser-choice
 * reader therefore asks here, and here prefers the explicit setting and falls
 * back to the marker only for a row a migration has not reached yet (#288).
 *
 * @since 3.7.0
 */
final class ParserSelection
{
    /**
     * The parser type identifying MeCab.
     */
    public const MECAB = 'mecab';

    /**
     * Whether the language tokenizes with MeCab.
     *
     * An explicit `LgParserType` settles it: it is what the user chose, so a
     * language that says `regex` means regex even if its word-characters field
     * still holds the old marker. Only a language that names no parser at all
     * falls back to the marker.
     *
     * @param string|null $parserType     `LgParserType`, null or '' when unset
     * @param string      $wordCharacters Raw `LgRegexpWordCharacters` value
     *
     * @return bool True when MeCab does the tokenizing
     */
    public static function tokenizesWithMecab(?string $parserType, string $wordCharacters): bool
    {
        $chosen = strtolower(trim((string) $parserType));
        if ($chosen !== '') {
            return $chosen === self::MECAB;
        }

        return WordSpacing::usesMecabMagicWord($wordCharacters);
    }

    /**
     * Whether a language row tokenizes with MeCab.
     *
     * @param array<string, mixed> $row A `languages` row with Lg* columns
     *
     * @return bool True when MeCab does the tokenizing
     */
    public static function rowTokenizesWithMecab(array $row): bool
    {
        /** @var mixed $parserType */
        $parserType = $row['LgParserType'] ?? null;

        return self::tokenizesWithMecab(
            is_string($parserType) ? $parserType : null,
            (string) ($row['LgRegexpWordCharacters'] ?? '')
        );
    }
}
