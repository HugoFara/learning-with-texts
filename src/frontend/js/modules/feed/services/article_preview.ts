/**
 * Article Preview - put the selected article on the page for picking in.
 *
 * The wizard's steps 2 and 3 work by clicking the article itself, so the
 * article has to be in the page. It used to arrive as raw HTML echoed after
 * the wizard's markup by the PHP that rendered the step, with the fetched
 * HTML cached in `$_SESSION` between page loads. It now arrives as data from
 * `POST /feeds/wizard/article` and is cached in the store (#262, #266).
 *
 * The HTML is third-party and goes in unescaped, exactly as it did when PHP
 * echoed it: the point of the preview is to reproduce the article's own
 * structure so the XPath the user picks matches what the extractor will later
 * see. The server strips script, style and frame elements before answering,
 * and the page's `script-src 'self'` keeps anything it missed from running.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.5.1
 */

import { t } from '@shared/i18n/translator';
import type { FeedWizardStoreState } from '../types/feed_wizard_types';
import { previewArticle } from '../api/wizard_api';

/** The element the article is rendered into. */
export const ARTICLE_CONTAINER_ID = 'lwt_article';

/**
 * Get the element the article is rendered into.
 */
function container(): HTMLElement | null {
  return document.getElementById(ARTICLE_CONTAINER_ID);
}

/**
 * Take the article off the page.
 */
export function clearArticle(): void {
  const host = container();
  if (host) host.innerHTML = '';
}

/**
 * Render the store's selected article, fetching it if it is not cached yet.
 *
 * @param store The wizard store
 * @returns Empty string once the article is on the page, otherwise a message
 */
export async function showSelectedArticle(store: FeedWizardStoreState): Promise<string> {
  const host = container();
  if (!host) {
    return t('feed.wizard.article.failed');
  }

  const index = store.selectedFeedIndex;
  const cached = store.articleHtml[index];
  if (cached !== undefined) {
    host.innerHTML = cached;
    return '';
  }

  const response = await previewArticle({
    rssUrl: store.rssUrl,
    index,
    articleSource: store.feedText,
    charset: store.feedOptions.charset.enabled ? store.feedOptions.charset.value : '',
    redirect: store.redirect
  });

  const payload = response.data;
  if (response.error || !payload || payload.success !== true) {
    host.innerHTML = '';
    return response.error || payload?.error || t('feed.wizard.article.failed');
  }

  const html = payload.html ?? '';
  store.setArticleHtml(index, html);
  host.innerHTML = html;
  return '';
}
