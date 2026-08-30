/**
 * Feed Wizard Config - what the wizard page needs from PHP.
 *
 * One blob for the whole wizard, read by every step. Each step used to be a
 * page of its own with a config blob of its own, most of it re-derived from
 * the session on every hop (#262, #266).
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.5.1
 */

/**
 * A curated feed source entry.
 */
export interface CuratedSource {
  name: string;
  url: string;
  articleSectionTags: string;
  filterTags: string;
  options: string;
  category: string;
  level: string;
}

/**
 * A curated feed language group.
 */
export interface CuratedFeedGroup {
  language: string;
  languageName: string;
  sources: CuratedSource[];
}

/**
 * Everything PHP hands the wizard page.
 */
export interface FeedWizardPageConfig {
  /** Languages available in the last step's picker */
  languages: Array<{ id: number; name: string }>;
  /** The curated registry the first tab browses */
  curatedFeeds: CuratedFeedGroup[];
  /** The navbar's current language, used for curated adds */
  currentLanguageId: number;
  currentLanguageName: string;
  /** Feed being re-run through the wizard, or null when adding a new one */
  editFeedId: number | null;
}

/** The config used when the page carries none. */
const DEFAULTS: FeedWizardPageConfig = {
  languages: [],
  curatedFeeds: [],
  currentLanguageId: 0,
  currentLanguageName: '',
  editFeedId: null
};

/**
 * Read the wizard page's configuration.
 */
export function readWizardPageConfig(): FeedWizardPageConfig {
  const configEl = document.getElementById('feed-wizard-config');
  if (!configEl?.textContent) {
    return { ...DEFAULTS };
  }

  try {
    const parsed = JSON.parse(configEl.textContent) as Partial<FeedWizardPageConfig>;
    return { ...DEFAULTS, ...parsed };
  } catch {
    console.error('Failed to parse feed wizard config');
    return { ...DEFAULTS };
  }
}
