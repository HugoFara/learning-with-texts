/**
 * Feed Wizard API - read a feed, and read one of its articles.
 *
 * The wizard used to get both of these by navigating between four PHP pages,
 * each render re-deriving what the previous one had left in `$_SESSION`. They
 * are two reads, so the wizard asks for them and keeps the answers in the
 * browser (#262, #266).
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.5.1
 */

import { apiPost, ApiResponse } from '@shared/api/client';

/**
 * One article of a feed, as the picker lists it.
 */
export interface WizardFeedItem {
  index: number;
  title: string;
  link: string;
  host: string;
}

/**
 * What `POST /feeds/wizard/preview` answers with.
 */
export interface WizardFeedPreview {
  success: boolean;
  error?: string;
  title?: string;
  articleSource?: string;
  articleSources?: string[];
  items?: WizardFeedItem[];
}

/**
 * What `POST /feeds/wizard/article` answers with.
 */
export interface WizardArticlePreview {
  success: boolean;
  error?: string;
  html?: string;
}

/**
 * Which article to fetch, and how to read it.
 */
export interface WizardArticleRequest {
  rssUrl: string;
  index: number;
  /** Feed element carrying the body inline, or '' to fetch the linked page. */
  articleSource?: string;
  charset?: string;
  /** `redirect:<xpath>` selector, for feeds whose links go through a hop. */
  redirect?: string;
}

/**
 * Read a feed and list its articles.
 *
 * @param rssUrl The feed URL
 */
export async function previewFeed(rssUrl: string): Promise<ApiResponse<WizardFeedPreview>> {
  return apiPost<WizardFeedPreview>('/feeds/wizard/preview', { rss_url: rssUrl });
}

/**
 * Fetch one article of a feed as pickable HTML.
 *
 * The article is named by its position in the feed rather than by URL, so the
 * server only fetches links the feed itself advertises.
 *
 * @param request Which article, and how to read it
 */
export async function previewArticle(
  request: WizardArticleRequest
): Promise<ApiResponse<WizardArticlePreview>> {
  return apiPost<WizardArticlePreview>('/feeds/wizard/article', {
    rss_url: request.rssUrl,
    index: request.index,
    article_source: request.articleSource ?? '',
    charset: request.charset ?? '',
    redirect: request.redirect ?? ''
  });
}
