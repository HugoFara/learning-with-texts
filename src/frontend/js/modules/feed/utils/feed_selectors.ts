/**
 * Feed Selectors - read and write the XPath lists a feed stores.
 *
 * `NfArticleSectionTags` and `NfFilterTags` hold pipe-separated XPath
 * expressions, historically with `!?!` standing in for the pipe. The article
 * list may lead with a `redirect:<xpath>` hop for feeds whose links point at
 * an interstitial rather than the article.
 *
 * The wizard used to round-trip these through `<li>` elements it parsed back
 * out of the page; they are parsed as strings here (#262, #266).
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.5.1
 */

/**
 * An article section list, split into its hop and its selectors.
 */
export interface SectionTags {
  /** `redirect:<xpath>` hop, or '' when the links are direct */
  redirect: string;
  /** XPath expressions naming the article body */
  selectors: string[];
}

/**
 * Split a stored tag list into its individual expressions.
 *
 * @param raw The stored `NfArticleSectionTags` or `NfFilterTags` value
 */
export function splitTags(raw: string): string[] {
  return raw
    .replace(/!\?!/g, '|')
    .split('|')
    .map(tag => tag.trim())
    .filter(tag => tag !== '');
}

/**
 * Split an article section list into its redirect hop and its selectors.
 *
 * @param raw The stored `NfArticleSectionTags` value
 */
export function parseSectionTags(raw: string): SectionTags {
  const result: SectionTags = { redirect: '', selectors: [] };

  for (const tag of splitTags(raw)) {
    if (tag.startsWith('redirect')) {
      result.redirect = tag;
    } else {
      result.selectors.push(tag);
    }
  }

  return result;
}

/**
 * Join a redirect hop and its selectors back into a storable list.
 *
 * @param redirect  The hop, or '' when the links are direct
 * @param selectors XPath expressions naming the article body
 */
export function buildSectionTags(redirect: string, selectors: string[]): string {
  const parts = redirect === '' ? [] : [redirect];
  return [...parts, ...selectors.filter(s => s.trim() !== '')].join(' | ');
}
