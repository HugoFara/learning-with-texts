/**
 * Wizard Flow - the two ways into the picking steps.
 *
 * Either the user types a feed URL, or they reopen a feed they already saved.
 * Both end with the store holding a feed's articles and the wizard sitting on
 * step 2. The first used to be a form POST to `/feeds/wizard`, the second a
 * link to the same route carrying `edit_feed` (#262, #266).
 *
 * These are free functions rather than store methods so the step that starts
 * the flow can own its own loading and error state.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.5.1
 */

import { t } from '@shared/i18n/translator';
import type { FeedWizardStoreState, FeedOptions } from '../types/feed_wizard_types';
import { previewFeed } from '../api/wizard_api';
import { getFeed, parseOptionsString } from '../api/feeds_api';
import { parseSectionTags, splitTags } from '../utils/feed_selectors';
import { clearArticle } from './article_preview';

/**
 * Turn a stored options string into the shape the last step edits.
 *
 * @param optionsString The stored `NfOptions` value
 * @param languageId    The feed's language
 */
export function toStoreOptions(optionsString: string, languageId: number | null): FeedOptions {
  const parsed = parseOptionsString(optionsString);
  const autoUpdate = parsed.autoupdate ?? '';
  const unit = autoUpdate.slice(-1);

  return {
    languageId,
    editText: parsed.edit_text !== undefined,
    autoUpdate: {
      enabled: autoUpdate !== '',
      interval: autoUpdate === '' ? null : parseInt(autoUpdate.slice(0, -1), 10) || null,
      unit: unit === 'd' || unit === 'w' ? unit : 'h'
    },
    maxLinks: {
      enabled: parsed.max_links !== undefined,
      value: parsed.max_links ? parseInt(parsed.max_links, 10) : null
    },
    maxTexts: {
      enabled: parsed.max_texts !== undefined,
      value: parsed.max_texts ? parseInt(parsed.max_texts, 10) : null
    },
    charset: {
      enabled: parsed.charset !== undefined,
      value: parsed.charset ?? ''
    },
    tag: {
      enabled: parsed.tag !== undefined,
      value: parsed.tag ?? ''
    }
  };
}

/**
 * Read a feed and put the wizard on step 2 with its articles.
 *
 * @param store  The wizard store
 * @param rssUrl The feed URL
 * @returns Empty string on success, otherwise a message to show
 */
export async function openFeed(
  store: FeedWizardStoreState,
  rssUrl: string
): Promise<string> {
  clearArticle();

  const response = await previewFeed(rssUrl);
  const payload = response.data;

  if (response.error || !payload || payload.success !== true) {
    return response.error || payload?.error || t('feed.wizard.read_failed');
  }

  store.rssUrl = rssUrl;
  store.setFeedPreview({
    title: payload.title ?? '',
    articleSource: payload.articleSource ?? '',
    articleSources: payload.articleSources ?? [],
    items: payload.items ?? []
  });
  store.goToStep(2);

  return '';
}

/**
 * Reopen a saved feed in the wizard, with its settings in place.
 *
 * The feed's own name wins over the one the RSS document advertises, and its
 * saved selectors go back into the picking lists, so the user is editing what
 * they had rather than starting over.
 *
 * @param store  The wizard store
 * @param feedId The feed to reopen
 * @returns Empty string on success, otherwise a message to show
 */
export async function resumeFeed(
  store: FeedWizardStoreState,
  feedId: number
): Promise<string> {
  const response = await getFeed(feedId);
  const feed = response.data;

  if (response.error || !feed) {
    return response.error || t('feed.wizard.read_failed');
  }

  const error = await openFeed(store, feed.sourceUri);
  if (error !== '') {
    return error;
  }

  const section = parseSectionTags(feed.articleSectionTags);
  store.editFeedId = feedId;
  store.feedTitle = feed.name;
  store.redirect = section.redirect;
  store.feedOptions = toStoreOptions(feed.optionsString, feed.langId);
  store.configure({
    articleSelectors: section.selectors,
    filterSelectors: splitTags(feed.filterTags)
  });

  // A feed saved with an explicit article_source overrides what detection
  // picks this time round; the cached article belongs to the other source.
  const savedSource = feed.options.article_source;
  if (savedSource !== undefined && savedSource !== store.feedText) {
    store.feedText = savedSource;
    store.articleHtml = {};
  }

  return '';
}
