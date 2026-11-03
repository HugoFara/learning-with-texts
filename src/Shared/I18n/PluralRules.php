<?php

/**
 * Plural categories for the interface locales.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Shared\I18n
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://github.com/HugoFara/lwt
 */

declare(strict_types=1);

namespace Lwt\Shared\I18n;

/**
 * Maps a whole-number count to its CLDR plural category (#319).
 *
 * The categories are CLDR's: zero, one, two, few, many, other. A translation
 * file supplies "key_<category>" for the categories its language uses and
 * always "key_other" as the fallback.
 *
 * This is a hand-written subset of the CLDR rules, for whole numbers only,
 * because ext-intl is not a dependency. The frontend has a mirror in
 * shared/i18n/plural_rules.ts; both are pinned by the same fixture,
 * tests/fixtures/plural_rules.json, so they cannot drift.
 */
final class PluralRules
{
    /**
     * Languages whose nouns do not inflect for number.
     */
    private const NO_PLURAL = ['id', 'ja', 'ko', 'ms', 'th', 'vi', 'zh'];

    /**
     * Languages where 0 and 1 both take the singular.
     */
    private const ZERO_IS_ONE = ['fa', 'fr', 'hi', 'pt'];

    /**
     * Languages that use "many" for exact multiples of a million
     * ("un million de termes").
     */
    private const MILLION_IS_MANY = ['ca', 'es', 'fr', 'it', 'pt'];

    /**
     * Languages with the East Slavic one/few/many pattern.
     */
    private const EAST_SLAVIC = ['be', 'ru', 'uk'];

    /**
     * Return the plural category of $count in $locale.
     *
     * @param string $locale Locale code; only the primary subtag is used
     * @param int    $count  The number being counted
     *
     * @return 'zero'|'one'|'two'|'few'|'many'|'other'
     */
    public static function category(string $locale, int $count): string
    {
        $lang = strtolower(explode('-', str_replace('_', '-', $locale))[0]);
        $n = abs($count);
        $mod10 = $n % 10;
        $mod100 = $n % 100;

        if (in_array($lang, self::NO_PLURAL, true)) {
            return 'other';
        }
        if ($lang === 'ar') {
            return match (true) {
                $n === 0 => 'zero',
                $n === 1 => 'one',
                $n === 2 => 'two',
                $mod100 >= 3 && $mod100 <= 10 => 'few',
                $mod100 >= 11 => 'many',
                default => 'other',
            };
        }
        if (in_array($lang, self::EAST_SLAVIC, true)) {
            if ($mod10 === 1 && $mod100 !== 11) {
                return 'one';
            }
            if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
                return 'few';
            }
            return 'many';
        }
        if ($n === 1 || ($n === 0 && in_array($lang, self::ZERO_IS_ONE, true))) {
            return 'one';
        }
        if ($n !== 0 && $n % 1000000 === 0 && in_array($lang, self::MILLION_IS_MANY, true)) {
            return 'many';
        }
        return 'other';
    }
}
