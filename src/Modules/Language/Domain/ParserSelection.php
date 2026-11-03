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
 * covers the spacing half; this covers parser choice, which `LgParserType`
 * holds. Until 3.8.0 a language naming no parser fell back to the marker in
 * `LgRegexpWordCharacters`; `20260831_120000_retire_mecab_magic_word.sql`
 * moved every such row onto `LgParserType`, and the fallback is gone (#288).
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
     * @param string|null $parserType `LgParserType`, null or '' when unset
     *
     * @return bool True when MeCab does the tokenizing
     */
    public static function tokenizesWithMecab(?string $parserType): bool
    {
        return strtolower(trim((string) $parserType)) === self::MECAB;
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

        return self::tokenizesWithMecab(is_string($parserType) ? $parserType : null);
    }
}
