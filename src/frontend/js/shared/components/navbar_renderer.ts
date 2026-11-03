/**
 * Global navigation bar renderer.
 *
 * The navbar used to be baked server-side by PageLayoutHelper::buildNavbar().
 * It is now built here from GET /api/v1/navbar so the same chrome renders
 * identically on the PHP web app and in the bundled, shell-free client (Lukaisu)
 * talking to a remote /api/v1. Returns an HTML string for innerHTML injection
 * (the same pattern the reader's book-nav uses), which keeps it CSP-safe — the
 * Alpine directives below are simple property reads / method calls supported by
 * @alpinejs/csp, and the markup is hydrated via Alpine.initTree after inject.
 *
 * Labels come from the i18n bundle (t('navbar.*')); only the data — language
 * list, current language, theme state, user/admin flags — comes from the API.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.1.0
 */

import { escapeHtml } from '@shared/utils/html_utils';
import { t } from '@shared/i18n/translator';

/** A language option for the navbar's language switcher. */
export interface NavbarLanguage {
  id: number;
  name: string;
}

/** Theme-toggle state mirrored from the server's active theme. */
export interface NavbarTheme {
  mode: string;
  counterpart: string;
  current: string;
  auto: boolean;
}

/** Payload shape of GET /api/v1/navbar (see PageLayoutHelper::getNavbarData). */
export interface NavbarData {
  basePath: string;
  logoUrl: string;
  languages: NavbarLanguage[];
  currentLanguageId: number;
  isMultiUser: boolean;
  showAdminItems: boolean;
  theme: NavbarTheme;
}

/** Page identifiers that light up each top-level nav section (mirrors buildNavbar). */
const TEXTS_PAGES = ['texts', 'archived', 'text-tags', 'text-check', 'long-import', 'feeds'];
const TERMS_PAGES = ['terms', 'term-tags', 'term-import'];
const LANGUAGES_PAGES = ['languages', 'language-new', 'language-edit'];
const ADMIN_PAGES = ['backup', 'settings', 'tts', 'users'];
const USER_PAGES = ['preferences', 'profile'];

/** A lucide icon placeholder (hydrated by initIconsIn after inject). */
function icon(name: string, size = 16): string {
  return `<i data-lucide="${name}" class="icon" style="width:${size}px;height:${size}px"></i>`;
}

/** The three primary nav buttons (texts / vocabulary / languages), each with a "+" sibling. */
function primaryButtons(base: string, currentPage: string): string {
  const activeTexts = TEXTS_PAGES.includes(currentPage) ? ' is-active' : '';
  const activeTerms = TERMS_PAGES.includes(currentPage) ? ' is-active' : '';
  const activeLangs = LANGUAGES_PAGES.includes(currentPage) ? ' is-active' : '';

  const group = (
    href: string,
    activeClass: string,
    iconName: string,
    label: string,
    newHref: string,
    newTitle: string
  ): string =>
    '<div class="navbar-item"><div class="buttons has-addons mb-0">'
    + `<a class="button is-small${activeClass}" href="${href}">`
    + `<span class="icon is-small">${icon(iconName)}</span><span>${label}</span></a>`
    + `<a class="button is-small" href="${newHref}" title="${escapeHtml(newTitle)}">`
    + `<span class="icon is-small">${icon('plus')}</span></a>`
    + '</div></div>';

  return (
    group(`${base}/texts`, activeTexts, 'book-text', t('navbar.texts'),
      `${base}/texts/new`, t('navbar.new_text_title'))
    + group(`${base}/words`, activeTerms, 'spell-check', t('navbar.vocabulary'),
      `${base}/words/new`, t('navbar.new_term_title'))
    + group(`${base}/languages`, activeLangs, 'languages', t('navbar.languages'),
      `${base}/languages/new`, t('navbar.new_language_title'))
  );
}

/** The language switcher (omitted when the user has no languages yet). */
function languageSelector(data: NavbarData): string {
  if (data.languages.length === 0) {
    return '';
  }
  const options = data.languages
    .map((lang) => {
      const selected = lang.id === data.currentLanguageId ? ' selected' : '';
      return `<option value="${lang.id}"${selected}>${escapeHtml(lang.name)}</option>`;
    })
    .join('');
  return (
    '<div class="navbar-item"><div class="field has-addons mb-0"><div class="control">'
    + '<div class="select is-small">'
    + `<select @change="switchLanguage($event)" data-current-lang="${data.currentLanguageId}">${options}</select>`
    + '</div></div></div></div>'
  );
}

/** The theme-toggle anchor (hands its data-* config to the themeToggle component). */
function themeToggle(data: NavbarData): string {
  const dark = data.theme.mode === 'dark';
  const title = dark ? t('navbar.switch_to_light_mode') : t('navbar.switch_to_dark_mode');
  return (
    `<a class="navbar-item" href="#" title="${escapeHtml(title)}" x-data="themeToggle"`
    + ` data-theme-mode="${escapeHtml(data.theme.mode)}"`
    + ` data-theme-counterpart="${escapeHtml(data.theme.counterpart)}"`
    + ` data-current-theme="${escapeHtml(data.theme.current)}"`
    + ` data-auto-theme="${data.theme.auto ? 'true' : 'false'}">`
    + icon(dark ? 'sun' : 'moon')
    + '</a>'
  );
}

/** The user dropdown (preferences, optional profile/admin items, help, optional logout). */
function userDropdown(data: NavbarData, currentPage: string): string {
  const base = data.basePath;
  const userActive = USER_PAGES.includes(currentPage) || ADMIN_PAGES.includes(currentPage)
    ? ' is-active'
    : '';

  const profile = data.isMultiUser
    ? `<a class="navbar-item" href="${base}/profile">${t('navbar.profile')}</a>`
    : '';

  const adminItems = data.showAdminItems
    ? '<hr class="navbar-divider">'
      + `<a class="navbar-item" href="${base}/admin/backup">${t('navbar.database_operations')}</a>`
      + `<a class="navbar-item" href="${base}/admin/settings">${t('navbar.admin_settings')}</a>`
      + `<a class="navbar-item" href="${base}/admin/users">${t('navbar.users')}</a>`
      + `<a class="navbar-item" href="${base}/admin/server-data">${t('navbar.server_data')}</a>`
    : '';

  // Logout POSTs with a CSRF token via the navbar component's logout() method;
  // the href is decorative, the click handler does the work.
  const logout = data.isMultiUser
    ? '<hr class="navbar-divider">'
      + `<a class="navbar-item" href="${base}/logout" @click.prevent="logout()">${t('navbar.logout')}</a>`
    : '';

  return (
    `<div class="navbar-item has-dropdown${userActive}" :class="{ 'is-active': activeDropdown === 'user' }">`
    + '<a class="navbar-link" @click.prevent="toggleDropdown(\'user\')">'
    + `${icon('user')}<span class="ms-1">${t('navbar.user')}</span></a>`
    + '<div class="navbar-dropdown is-right">'
    + `<a class="navbar-item" href="${base}/profile/preferences">${t('navbar.preferences')}</a>`
    + profile
    + adminItems
    + '<hr class="navbar-divider">'
    + `<a class="navbar-item" href="${base}/docs/info.html" target="_blank">${t('navbar.help')}</a>`
    + logout
    + '</div></div>'
  );
}

/**
 * Render the global navigation bar.
 *
 * @param data        - chrome data from GET /api/v1/navbar
 * @param currentPage - active-page hint (from the placeholder's data-current-page)
 * @returns HTML string for innerHTML injection (hydrate with Alpine.initTree + initIconsIn)
 */
export function renderNavbar(data: NavbarData, currentPage = ''): string {
  const base = data.basePath;
  return (
    `<nav class="navbar is-light" role="navigation" aria-label="${escapeHtml(t('navbar.main_navigation'))}"`
    + ' x-data="navbar()">'
    + '<div class="navbar-brand">'
    + `<a class="navbar-item" href="${base}/">`
    + `<img src="${escapeHtml(data.logoUrl)}" alt="LWT" width="28" height="28">`
    + '<span class="ms-2 has-text-weight-semibold">LWT</span></a>'
    + `<a role="button" class="navbar-burger" aria-label="${escapeHtml(t('navbar.menu'))}"`
    + ' aria-expanded="false" :class="{ \'is-active\': isOpen }" @click="toggle()">'
    + '<span aria-hidden="true"></span><span aria-hidden="true"></span>'
    + '<span aria-hidden="true"></span><span aria-hidden="true"></span></a>'
    + '</div>'
    + '<div class="navbar-menu" :class="{ \'is-active\': isOpen }">'
    + '<div class="navbar-start">'
    + primaryButtons(base, currentPage)
    + languageSelector(data)
    + `<a class="navbar-item" href="${base}/profile/statistics" title="${escapeHtml(t('navbar.statistics_title'))}"`
    + ' x-data="navbarStreak">'
    + '<span class="icon has-text-warning"><i data-lucide="flame"></i></span>'
    + '<span class="is-size-7 has-text-weight-semibold" x-show="streak > 0" x-text="streak" x-cloak></span></a>'
    + '</div>'
    + '<div class="navbar-end">'
    + themeToggle(data)
    + userDropdown(data, currentPage)
    + '</div></div>'
    // Dimmed overlay behind the mobile left drawer; tapping it closes the menu.
    // Kept inside <nav> so the click-outside handler treats it as "inside".
    + '<div class="navbar-overlay" :class="{ \'is-active\': isOpen }" @click="close()"></div>'
    + '</nav>'
  );
}
