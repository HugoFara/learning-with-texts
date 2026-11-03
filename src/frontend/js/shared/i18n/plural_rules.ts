/**
 * Plural categories for the interface locales (#319).
 *
 * Mirror of src/Shared/I18n/PluralRules.php. Both sides are pinned by the
 * same fixture, tests/fixtures/plural_rules.json, so the server and the
 * browser always pick the same form. Intl.PluralRules is deliberately not
 * used: its CLDR data varies with the browser version and would drift from
 * the PHP table.
 *
 * Whole numbers only, which is all the interface ever counts.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.8.0
 */

export type PluralCategory = 'zero' | 'one' | 'two' | 'few' | 'many' | 'other';

/** Languages whose nouns do not inflect for number. */
const NO_PLURAL = ['id', 'ja', 'ko', 'ms', 'th', 'vi', 'zh'];

/** Languages where 0 and 1 both take the singular. */
const ZERO_IS_ONE = ['fa', 'fr', 'hi', 'pt'];

/** Languages that use "many" for exact multiples of a million. */
const MILLION_IS_MANY = ['ca', 'es', 'fr', 'it', 'pt'];

/** Languages with the East Slavic one/few/many pattern. */
const EAST_SLAVIC = ['be', 'ru', 'uk'];

/**
 * Return the plural category of `count` in `locale`.
 *
 * @param locale - Locale code; only the primary subtag is used
 * @param count  - The number being counted
 */
export function pluralCategory(locale: string, count: number): PluralCategory {
  const lang = locale.replace('_', '-').split('-')[0].toLowerCase();
  const n = Math.abs(Math.trunc(count));
  const mod10 = n % 10;
  const mod100 = n % 100;

  if (NO_PLURAL.includes(lang)) return 'other';

  if (lang === 'ar') {
    if (n === 0) return 'zero';
    if (n === 1) return 'one';
    if (n === 2) return 'two';
    if (mod100 >= 3 && mod100 <= 10) return 'few';
    if (mod100 >= 11) return 'many';
    return 'other';
  }

  if (EAST_SLAVIC.includes(lang)) {
    if (mod10 === 1 && mod100 !== 11) return 'one';
    if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) return 'few';
    return 'many';
  }

  if (n === 1 || (n === 0 && ZERO_IS_ONE.includes(lang))) return 'one';
  if (n !== 0 && n % 1000000 === 0 && MILLION_IS_MANY.includes(lang)) return 'many';
  return 'other';
}
