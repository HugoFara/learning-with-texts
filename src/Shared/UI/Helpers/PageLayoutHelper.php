<?php

/**
 * \file
 * \brief Helper for page layout generation (headers, footers, navigation).
 *
 * PHP version 8.2
 *
 * @category View
 * @package  Lwt
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Shared\UI\Helpers;

use Lwt\Shared\Infrastructure\Container\Container;
use Lwt\Shared\Infrastructure\Database\Settings;
use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Shared\Infrastructure\Globals;
use Lwt\Shared\Infrastructure\Language\CurrentLanguage;
use Lwt\Shared\Infrastructure\Http\UrlUtilities;
use Lwt\Shared\I18n\Translator;
use Lwt\Shared\UI\Assets\ViteHelper;
use Lwt\Shared\Infrastructure\Utilities\StringUtils;
use Lwt\Modules\User\Application\UserFacade;

/**
 * Helper class for generating page layout elements.
 *
 * Provides methods for generating page headers, footers,
 * navigation menus, and other layout components.
 *
 * @since 3.0.0
 */
class PageLayoutHelper
{
    /**
     * Build the data-theme attribute for the <html> element.
     *
     * When a user has explicitly selected a theme (not auto-detect),
     * returns a data-theme attribute with the theme's mode ("light" or "dark").
     * When auto-detect is active (empty theme dir or legacy default),
     * returns empty string so the CSS prefers-color-scheme media query applies.
     *
     * @return string HTML attribute string (e.g., ' data-theme="dark"') or empty
     */
    private static function buildDataThemeAttr(): string
    {
        $themeDir = Settings::getWithDefault('set-theme-dir');
        $isAutoTheme = ($themeDir === '' || $themeDir === 'themes/default/' || $themeDir === 'dist/themes/Default/');

        if ($isAutoTheme) {
            return '';
        }

        $themeMode = 'light';
        $jsonPath = $themeDir . 'theme.json';
        if (file_exists($jsonPath)) {
            $json = file_get_contents($jsonPath);
            if ($json !== false) {
                /** @var array<string, mixed>|null $meta */
                $meta = json_decode($json, true);
                if (is_array($meta) && isset($meta['mode']) && is_string($meta['mode'])) {
                    $themeMode = $meta['mode'];
                }
            }
        }

        return ' data-theme="' . htmlspecialchars($themeMode, ENT_QUOTES, 'UTF-8') . '"';
    }

    /**
     * Fetch language list for the navbar dropdown.
     *
     * @return array{languages: array<int, array{id: int, name: string}>, currentId: int}
     */
    private static function getLanguagesForNavbar(): array
    {
        try {
            $languages = QueryBuilder::table('languages')
                ->select(['LgID', 'LgName'])
                ->where('LgName', '<>', '')
                ->orderBy('LgName')
                ->getPrepared();

            $result = [];
            foreach ($languages as $row) {
                $result[] = [
                    'id' => (int) $row['LgID'],
                    'name' => (string) $row['LgName'],
                ];
            }

            // Resolve rather than read the raw setting: with 'currentlanguage'
            // unset, no option got marked selected and the browser silently
            // displayed the first one anyway, so the navbar showed a language
            // the rest of the app did not consider selected.
            $currentId = CurrentLanguage::resolveId();

            return ['languages' => $result, 'currentId' => $currentId];
        } catch (\Throwable $e) {
            return ['languages' => [], 'currentId' => 0];
        }
    }

    /**
     * Assemble the navbar's dynamic, per-user data.
     *
     * The navbar markup itself is now rendered client-side (see
     * `navbar_renderer.ts`) so the same chrome can render in the bundled,
     * shell-free client against a remote `/api/v1`. This method is the single
     * server-side source of the data that markup needs — language list, current
     * language, theme state and user/admin flags — and is exposed verbatim by
     * `GET /api/v1/navbar`.
     *
     * Labels are intentionally absent: they are resolved client-side via the
     * i18n bundle (`t('navbar.*')`), so this payload stays locale-agnostic.
     *
     * @return array{
     *     basePath: string,
     *     logoUrl: string,
     *     languages: array<int, array{id: int, name: string}>,
     *     currentLanguageId: int,
     *     isMultiUser: bool,
     *     showAdminItems: bool,
     *     theme: array{mode: string, counterpart: string, current: string, auto: bool}
     * }
     */
    public static function getNavbarData(): array
    {
        // Theme toggle: read current theme and determine mode/counterpart
        $themeDir = Settings::getWithDefault('set-theme-dir');
        $themeJsonPath = $themeDir . 'theme.json';
        $themeMode = 'light';
        $themeCounterpart = 'dist/themes/Dark/';
        if ($themeDir !== '' && file_exists($themeJsonPath)) {
            $json = file_get_contents($themeJsonPath);
            if ($json !== false) {
                /** @var array<string, mixed>|null $meta */
                $meta = json_decode($json, true);
                if (is_array($meta)) {
                    $themeMode = (isset($meta['mode']) && is_string($meta['mode']))
                        ? $meta['mode'] : 'light';
                    $themeCounterpart = (isset($meta['counterpart']) && is_string($meta['counterpart']))
                        ? $meta['counterpart'] : 'dist/themes/Dark/';
                }
            }
        }
        $isAutoTheme = ($themeDir === '' || $themeDir === 'themes/default/' || $themeDir === 'dist/themes/Default/');

        $isMultiUser = Globals::isMultiUserEnabled();
        $langData = self::getLanguagesForNavbar();

        return [
            'basePath' => UrlUtilities::getBasePath(),
            'logoUrl' => UrlUtilities::url('/assets/images/lwt_icon_48.png'),
            'languages' => $langData['languages'],
            'currentLanguageId' => $langData['currentId'],
            'isMultiUser' => $isMultiUser,
            'showAdminItems' => !$isMultiUser || Globals::isCurrentUserAdmin(),
            'theme' => [
                'mode' => $themeMode,
                'counterpart' => $themeCounterpart,
                'current' => $themeDir,
                'auto' => $isAutoTheme,
            ],
        ];
    }

    /**
     * Render the navbar mount point.
     *
     * The client (`mountNavbar()` in `navbar.ts`) fetches `GET /api/v1/navbar`,
     * builds the markup with `navbar_renderer.ts` and hydrates this element. The
     * `data-current-page` attribute carries the active-page hint that used to be
     * a PHP parameter, so highlighting still works without server-rendered HTML.
     *
     * @param string $currentPage Optional identifier for the current page to highlight
     *
     * @return string HTML placeholder element
     */
    public static function buildNavbarPlaceholder(string $currentPage = ''): string
    {
        return '<div id="navbar-root" data-navbar-root data-current-page="'
            . htmlspecialchars($currentPage, ENT_QUOTES, 'UTF-8') . '"></div>';
    }

    /**
     * Generate an action card with buttons (non-collapsible).
     *
     * Creates a Bulma card with action buttons for page-level actions.
     *
     * @param array<array{
     *     url: string, label: string, icon?: string, class?: string, target?: string, attrs?: string
     * }> $actions Array of actions
     *
     * @return string HTML for the action card
     */
    public static function buildActionCard(array $actions): string
    {
        $buttonsHtml = '';
        foreach ($actions as $action) {
            $url = htmlspecialchars(UrlUtilities::url($action['url']), ENT_QUOTES, 'UTF-8');
            $label = htmlspecialchars($action['label'], ENT_QUOTES, 'UTF-8');
            $icon = isset($action['icon']) ? IconHelper::render($action['icon'], ['alt' => $label]) : '';
            $class = isset($action['class']) ? ' ' . htmlspecialchars($action['class'], ENT_QUOTES, 'UTF-8') : '';
            $target = isset($action['target'])
                ? ' target="' . htmlspecialchars($action['target'], ENT_QUOTES, 'UTF-8') . '"'
                : '';
            // Allow custom attributes (e.g., Alpine.js directives) - not escaped to allow dynamic bindings
            $attrs = isset($action['attrs']) ? ' ' . $action['attrs'] : '';

            $buttonsHtml .= <<<HTML
                <a href="{$url}" class="button is-light{$class}"{$target}{$attrs}>
                    <span class="icon">{$icon}</span>
                    <span>{$label}</span>
                </a>
HTML;
        }

        return <<<HTML
<div class="card action-card mb-4">
    <div class="card-content">
        <div class="buttons is-centered">
            {$buttonsHtml}
        </div>
    </div>
</div>
HTML;
    }

    /**
     * Generate the LWT logo HTML.
     *
     * @param string $imagePath Path to the logo image
     *
     * @return string HTML img element for the logo
     */
    public static function buildLogo(string $imagePath = 'assets/images/lwt_icon_48.png'): string
    {
        $path = StringUtils::getFilePath($imagePath);
        $url = UrlUtilities::url($path);
        return '<img class="lwtlogo" src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8')
            . '" title="LWT" alt="LWT logo" />';
    }

    /**
     * Generate pagination controls HTML.
     *
     * @param int                  $currentPage    Current page number
     * @param int                  $totalPages     Total number of pages
     * @param string               $scriptUrl      Base URL for pagination links
     * @param string               $formName       Form name for JavaScript reference (unused, kept for BC)
     * @param array<string, mixed> $preserveParams Query parameters to preserve in pagination links
     *
     * @return string HTML pagination controls
     */
    public static function buildPager(
        int $currentPage,
        int $totalPages,
        string $scriptUrl,
        string $formName,
        array $preserveParams = []
    ): string {
        $result = '';
        $margerStyle = 'style="margin-left: 4px; margin-right: 4px;"';
        $escapedUrl = htmlspecialchars($scriptUrl, ENT_QUOTES, 'UTF-8');

        // Build query string from preserved params (excluding page)
        unset($preserveParams['page']);
        $baseQuery = '';
        if (!empty($preserveParams)) {
            // Filter out empty values
            $filtered = array_filter($preserveParams, fn($v) => $v !== '' && $v !== null);
            if (!empty($filtered)) {
                $baseQuery = http_build_query($filtered) . '&';
            }
        }

        // Helper to build page URL
        $pageUrl = fn(int $page): string =>
            $escapedUrl . '?' . $baseQuery . 'page=' . $page;

        // Previous page controls
        if ($currentPage > 1) {
            $result .= '<a href="' . $pageUrl(1) . '" ' . $margerStyle . '>';
            $result .= IconHelper::render('chevrons-left', ['title' => 'First Page', 'alt' => 'First Page']);
            $result .= '</a>';
            $result .= '<a href="' . $pageUrl($currentPage - 1) . '" ' . $margerStyle . '>';
            $result .= IconHelper::render('chevron-left', ['title' => 'Previous Page', 'alt' => 'Previous Page']);
            $result .= '</a>';
        }

        // Page indicator
        $result .= 'Page ';
        if ($totalPages == 1) {
            $result .= '1';
        } else {
            // Pass preserved params as data attribute for JS navigation
            $jsonParams = json_encode($preserveParams);
            $dataParams = !empty($preserveParams) && $jsonParams !== false
                ? ' data-preserve-params="' . htmlspecialchars($jsonParams, ENT_QUOTES, 'UTF-8') . '"'
                : '';
            $result .= '<select name="page" data-action="pager-navigate" ' .
                'data-base-url="' . $escapedUrl . '"' . $dataParams . '>';
            $result .= SelectOptionsBuilder::forPagination($currentPage, $totalPages);
            $result .= '</select>';
        }
        $result .= ' of ' . $totalPages . ' ';

        // Next page controls
        if ($currentPage < $totalPages) {
            $result .= '<a href="' . $pageUrl($currentPage + 1) . '" ' . $margerStyle . '>';
            $result .= IconHelper::render('chevron-right', ['title' => 'Next Page', 'alt' => 'Next Page']);
            $result .= '</a>';
            $result .= '<a href="' . $pageUrl($totalPages) . '" ' . $margerStyle . '>';
            $result .= IconHelper::render('chevrons-right', ['title' => 'Last Page', 'alt' => 'Last Page']);
            $result .= '</a>';
        }

        return $result;
    }

    /**
     * Generate HTTP cache prevention headers.
     *
     * @return void
     */
    public static function sendNoCacheHeaders(): void
    {
        @header('Expires: Wed, 11 Jan 1984 05:00:00 GMT');
        @header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
        @header('Cache-Control: no-cache, must-revalidate, max-age=0');
        @header('Pragma: no-cache');
    }

    /**
     * Build the HTML head meta tags.
     *
     * @return string HTML meta tags
     */
    public static function buildMetaTags(): string
    {
        $basePath = UrlUtilities::getBasePath();
        $favicon = UrlUtilities::url('/favicon.ico');
        $icon180 = UrlUtilities::url('/assets/images/lwt_icon_180.png');
        $manifest = UrlUtilities::url('/assets/manifest.json');
        $csrfToken = htmlspecialchars(
            \Lwt\Shared\UI\Helpers\FormHelper::csrfToken(),
            ENT_QUOTES,
            'UTF-8'
        );

        return <<<HTML
<meta http-equiv="content-type" content="text/html; charset=utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="lwt-base-path" content="{$basePath}" />
<meta name="csrf-token" content="{$csrfToken}" />
<meta name="theme-color" content="#3273dc" />
<link rel="shortcut icon" href="{$favicon}" type="image/x-icon"/>
<link rel="apple-touch-icon" href="{$icon180}" />
<link rel="manifest" href="{$manifest}" />
<meta name="apple-mobile-web-app-capable" content="yes" />
HTML;
    }

    /**
     * Build the page title HTML element.
     *
     * @param string $title Page title
     *
     * @return string HTML h1 element with title
     */
    public static function buildPageTitle(string $title): string
    {
        $escapedTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        return '<h1>' . $escapedTitle . '</h1>';
    }

    /**
     * Build the document title tag content.
     *
     * @param string $title Page title
     *
     * @return string HTML title element
     */
    public static function buildDocumentTitle(string $title): string
    {
        return '<title>LWT :: ' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>';
    }

    /**
     * Build the execution time display.
     *
     * @param float $executionTime Execution time in seconds
     *
     * @return string HTML paragraph with execution time
     */
    public static function buildExecutionTime(float $executionTime): string
    {
        return '<p class="has-text-grey is-size-7">' . round($executionTime, 5) . ' secs</p>';
    }

    // =========================================================================
    // Methods migrated from Core/UI/ui_helpers.php
    // =========================================================================

    /**
     * Determine which frontend feature modules the current page needs.
     *
     * Maps the request URI to the set of dynamically-loaded JS modules
     * that should be imported.
     *
     * @return string[] Module names (e.g. ['vocabulary', 'text'])
     */
    private static function getRequiredModules(): array
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';

        // Strip query string and base path
        $parsed = parse_url($uri, PHP_URL_PATH);
        $path = is_string($parsed) ? $parsed : '/';
        $basePath = UrlUtilities::getBasePath();
        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }
        $path = '/' . ltrim($path, '/');

        // Text reading page needs vocabulary + text + review + language
        if (preg_match('#^/text/\d+/read#', $path)) {
            return ['vocabulary', 'text', 'review', 'language'];
        }

        // Review pages
        if (str_starts_with($path, '/review')) {
            return ['review', 'vocabulary', 'text', 'language'];
        }

        // Text management pages
        if (
            str_starts_with($path, '/text')
            || str_starts_with($path, '/archived')
            || str_starts_with($path, '/texts')
        ) {
            return ['text', 'vocabulary', 'book'];
        }

        // Vocabulary/word pages
        if (str_starts_with($path, '/word') || str_starts_with($path, '/terms')) {
            return ['vocabulary', 'language'];
        }

        // Feed pages
        if (str_starts_with($path, '/feed')) {
            return ['feed'];
        }

        // Starter vocab page (under /languages but needs vocabulary module)
        if (preg_match('#^/languages/\d+/starter-vocab#', $path)) {
            return ['vocabulary', 'language'];
        }

        // Dictionary pages (under /languages/.../dictionaries)
        if (preg_match('#^/languages/\d+/dictionaries#', $path)) {
            return ['dictionary', 'language'];
        }

        // Language pages
        if (str_starts_with($path, '/language')) {
            return ['language'];
        }

        // Tag pages
        if (str_starts_with($path, '/tags')) {
            return ['tags'];
        }

        // Admin pages
        if (str_starts_with($path, '/admin')) {
            return ['admin'];
        }

        // User preferences: the settings form is the user module; the theme
        // selector component still lives in admin.
        if (str_starts_with($path, '/profile/preferences')) {
            return ['user', 'admin'];
        }

        // Statistics lives under /profile but is charts, not the profile form.
        // The admin bundle owns statisticsApp and pulls in @modules/activity
        // (streakDisplay, calendarHeatmap) itself.
        if (str_starts_with($path, '/profile/statistics')) {
            return ['admin'];
        }

        // Profile page
        if (str_starts_with($path, '/profile')) {
            return ['user'];
        }

        // Auth pages
        if (
            str_starts_with($path, '/login')
            || str_starts_with($path, '/register')
            || str_starts_with($path, '/reset-password')
            || str_starts_with($path, '/connect')
        ) {
            return ['auth'];
        }

        // Home page
        if ($path === '/') {
            return ['home'];
        }

        // Unknown routes — load all modules as safe fallback
        return ['vocabulary', 'text', 'review', 'feed', 'language', 'admin', 'book'];
    }

    /**
     * Determine additional i18n namespaces to inject for the current page.
     *
     * Some namespaces (like "preferences") don't have a matching JS module
     * but still need their translations injected for client-side usage.
     *
     * @return string[] Extra namespace names to inject
     */
    private static function getExtraI18nNamespaces(): array
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $parsed = parse_url($uri, PHP_URL_PATH);
        $path = is_string($parsed) ? $parsed : '/';
        $basePath = UrlUtilities::getBasePath();
        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }
        $path = '/' . ltrim($path, '/');

        if (str_starts_with($path, '/profile/preferences')) {
            return ['preferences'];
        }

        if (
            str_starts_with($path, '/login')
            || str_starts_with($path, '/register')
            || str_starts_with($path, '/password/forgot')
            || str_starts_with($path, '/password/reset')
            || str_starts_with($path, '/profile')
            || str_starts_with($path, '/google/link-confirm')
            || str_starts_with($path, '/microsoft/link-confirm')
        ) {
            return ['user'];
        }

        if (str_starts_with($path, '/book')) {
            return ['book'];
        }

        if (str_starts_with($path, '/dictionar')) {
            return ['dictionary'];
        }

        return [];
    }

    /**
     * Render a minimal page header (kernel, no database).
     *
     * Outputs directly to browser. Sets cache control headers,
     * renders HTML5 doctype, head, and opening body.
     *
     * @param string $title Page title
     *
     * @return void
     */
    public static function renderPageStartKernelNobody(string $title): void
    {
        self::sendNoCacheHeaders();

        $favicon = UrlUtilities::url('/favicon.ico');
        $icon180 = UrlUtilities::url('/assets/images/lwt_icon_180.png');

        echo '<!DOCTYPE html>';
        echo '<html lang="en"' . self::buildDataThemeAttr() . '>';
        echo '<head>';
        echo '<meta http-equiv="content-type" content="text/html; charset=utf-8" />';
        echo '<!--' . "\n";
        echo file_get_contents("LICENSE");
        echo '-->';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1" />';
        echo '<meta name="csrf-token" content="'
            . htmlspecialchars(FormHelper::csrfToken(), ENT_QUOTES, 'UTF-8') . '" />';
        echo '<link rel="shortcut icon" href="' . $favicon . '" type="image/x-icon"/>';
        echo '<link rel="apple-touch-icon" href="' . $icon180 . '" />';
        echo '<link rel="manifest" href="' . UrlUtilities::url('/assets/manifest.json') . '" />';
        echo '<meta name="theme-color" content="#3273dc" />';
        echo '<meta name="apple-mobile-web-app-capable" content="yes" />';

        if (ViteHelper::shouldUse()) {
            echo '<!-- Critical CSS for fast first paint -->';
            echo ViteHelper::criticalCss();
            echo '<!-- Vite assets (async CSS) -->';
            echo ViteHelper::assets('js/main.ts');
        } else {
            echo '<!-- Legacy assets -->';
            echo '<link rel="stylesheet" type="text/css" href="' . UrlUtilities::url('/dist/css/styles.css') . '" />';
        }

        echo '<!-- URLBASE : "' . htmlspecialchars(UrlUtilities::urlBase(), ENT_QUOTES, 'UTF-8') . '" -->';
        echo '<title>LWT :: ' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>';
        echo '</head>';
        echo '<body>';

        flush();
    }

    /**
     * Render a full page header (no database).
     *
     * Outputs directly to browser. Sets cache control headers,
     * renders HTML5 doctype, full head with assets, and opening body.
     *
     * @param string $title     Page title
     * @param string $bodyClass Optional CSS class for body element
     *
     * @return void
     */
    public static function renderPageStartNobody(
        string $title,
        string $bodyClass = ''
    ): void {
        self::sendNoCacheHeaders();

        $favicon = UrlUtilities::url('/favicon.ico');
        $icon180 = UrlUtilities::url('/assets/images/lwt_icon_180.png');

        $htmlLang = self::getActiveLocale();

        echo '<!DOCTYPE html>';
        echo '<html lang="' . htmlspecialchars($htmlLang, ENT_QUOTES, 'UTF-8')
            . '"' . self::buildDataThemeAttr() . '>';
        echo '<head>';
        echo '<meta http-equiv="content-type" content="text/html; charset=utf-8" />';
        echo '<!--' . "\n";
        echo file_get_contents("LICENSE");
        echo '-->';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        $basePath = htmlspecialchars(UrlUtilities::getBasePath(), ENT_QUOTES, 'UTF-8');
        echo '<meta name="lwt-base-path" content="' . $basePath . '" />';
        echo '<meta name="csrf-token" content="'
            . htmlspecialchars(FormHelper::csrfToken(), ENT_QUOTES, 'UTF-8') . '" />';
        echo '<link rel="shortcut icon" href="' . $favicon . '" type="image/x-icon"/>';
        echo '<link rel="apple-touch-icon" href="' . $icon180 . '" />';
        echo '<link rel="manifest" href="' . UrlUtilities::url('/assets/manifest.json') . '" />';
        echo '<meta name="theme-color" content="#3273dc" />';
        echo '<meta name="apple-mobile-web-app-capable" content="yes" />';

        $modules = self::getRequiredModules();
        if (!empty($modules)) {
            echo '<meta name="lwt-modules" content="'
                . htmlspecialchars(implode(',', $modules), ENT_QUOTES, 'UTF-8')
                . '">';
        }

        echo self::buildI18nScript($modules);

        if (ViteHelper::shouldUse()) {
            echo '<!-- Critical CSS for fast first paint -->';
            echo ViteHelper::criticalCss();
            echo '<!-- Vite assets (async CSS) -->';
            echo ViteHelper::assets('js/main.ts');
        } else {
            echo '<!-- Legacy assets -->';
            echo '<link rel="stylesheet" type="text/css" href="' . UrlUtilities::url('/dist/css/styles.css') . '" />';
            $jsUrl = UrlUtilities::url('/dist/js/pgm.js');
            echo '<script type="text/javascript" src="' . $jsUrl . '" charset="utf-8"></script>';
        }

        echo '<!-- URLBASE : "' . htmlspecialchars(UrlUtilities::urlBase(), ENT_QUOTES, 'UTF-8') . '" -->';
        echo '<title>LWT :: ' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>';
        echo '</head>';
        $bodyAttr = $bodyClass !== ''
            ? ' class="' . htmlspecialchars($bodyClass, ENT_QUOTES, 'UTF-8') . '"'
            : '';
        echo '<body' . $bodyAttr . '>';
        echo '<a href="#main-content" class="sr-only sr-only-focusable" '
            . 'style="position:absolute;z-index:10000;background:#485fc7;color:#fff;padding:0.5rem 1rem;">'
            . 'Skip to main content</a>';

        flush();
    }

    /**
     * Render a standard page header with navigation.
     *
     * Calls renderPageStartNobody then adds navbar and page title.
     *
     * @param string $title       Page title
     * @param bool   $close       Whether to show full navigation (true) or minimal header (false)
     * @param string $currentPage Optional identifier for the current page to highlight in navbar
     *
     * @return void
     */
    public static function renderPageStart(string $title, bool $close, string $currentPage = ''): void
    {
        self::renderPageStartNobody($title);
        if ($close) {
            echo self::buildNavbarPlaceholder($currentPage);
        } else {
            echo '<div>';
            echo self::buildLogo();
            echo '</div>';
        }
        echo '<main id="main-content">';
        self::renderEmailVerificationBanner();
        echo self::buildPageTitle($title);
    }

    /**
     * Build a compact UI-language switcher for the pre-login pages.
     *
     * A no-JS GET form (CSP-safe): a native-name `<select name="lang">` plus a
     * submit button. Existing query parameters (e.g. a reset token) are carried
     * across as hidden inputs so switching language never drops them. The
     * `?lang=` is applied and persisted by TranslatorServiceProvider. Returns
     * an empty string when only one locale is installed.
     *
     * @return string HTML for the switcher, or '' if not applicable.
     */
    public static function languageSwitcher(): string
    {
        $container = Container::getInstance();
        if (!$container->has(Translator::class)) {
            return '';
        }
        $translator = $container->getTyped(Translator::class);
        $locales = $translator->getAvailableLocales();
        if (count($locales) < 2) {
            return '';
        }
        $options = SelectOptionsBuilder::forAppLanguages($locales, $translator->getLocale());

        // Post back to the current path; carry other query params as hidden
        // inputs (a GET form otherwise replaces the whole query string).
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $parsedPath = parse_url($uri, PHP_URL_PATH);
        $path = is_string($parsedPath) && $parsedPath !== '' ? $parsedPath : '/';
        $hidden = '';
        foreach ($_GET as $key => $value) {
            if ($key === 'lang' || !is_string($value)) {
                continue;
            }
            $hidden .= '<input type="hidden" name="'
                . htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8')
                . '" value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '">';
        }

        return '<form method="get" action="' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . '"'
            . ' class="field has-addons mb-4" style="justify-content: flex-end;">'
            . $hidden
            . '<div class="control"><div class="select is-small">'
            . '<select name="lang" aria-label="Change language">' . $options . '</select>'
            . '</div></div>'
            . '<div class="control">'
            . '<button type="submit" class="button is-small" aria-label="Change language">'
            . '<span class="icon"><i data-lucide="globe"></i></span></button>'
            . '</div></form>';
    }

    /**
     * Get the active locale code from the Translator service.
     *
     * @return string Locale code (e.g. "en", "es")
     */
    private static function getActiveLocale(): string
    {
        $container = Container::getInstance();
        if ($container->has(Translator::class)) {
            return $container->getTyped(Translator::class)->getLocale();
        }
        return 'en';
    }

    /**
     * Build a JSON script tag containing translations for the frontend.
     *
     * Collects translations for the "common" namespace plus any
     * namespaces matching the page's required modules.
     *
     * @param string[] $modules Module names from getRequiredModules()
     *
     * @return string HTML script tag, or empty string if translator unavailable
     */
    private static function buildI18nScript(array $modules): string
    {
        $container = Container::getInstance();
        if (!$container->has(Translator::class)) {
            return '';
        }

        $translator = $container->getTyped(Translator::class);

        // Namespaces to inject: "common" and "navbar" are always loaded,
        // page-specific namespaces (matching JS module names) come from $modules,
        // and any additional namespaces from getExtraI18nNamespaces().
        $namespaces = array_unique(array_merge(
            ['common', 'navbar'],
            $modules,
            self::getExtraI18nNamespaces()
        ));

        $prefixed = [];
        foreach ($namespaces as $ns) {
            foreach ($translator->getNamespaceTranslations($ns) as $k => $v) {
                $prefixed[$ns . '.' . $k] = $v;
            }
        }

        if ($prefixed === []) {
            return '';
        }

        return '<script type="application/json" id="lwt-i18n">'
            . json_encode($prefixed, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG)
            . '</script>';
    }

    /**
     * Render the page footer (closing body and html tags).
     *
     * @return void
     */
    public static function renderPageEnd(): void
    {
        echo '</main></body></html>';
    }

    /**
     * Render a warning banner when the current user's email is not verified.
     *
     * Only shows in multi-user mode when the user is authenticated
     * but has not yet verified their email address.
     *
     * @return void
     */
    public static function renderEmailVerificationBanner(): void
    {
        if (!Globals::isMultiUserEnabled() || !Globals::isAuthenticated()) {
            return;
        }

        try {
            /** @var UserFacade $facade */
            $facade = Container::getInstance()->get(UserFacade::class);
            $user = $facade->getCurrentUser();
        } catch (\Throwable $e) {
            return;
        }

        if ($user === null || $user->isEmailVerified()) {
            return;
        }

        echo '<div class="notification is-warning" role="alert">'
            . '<strong>Email not verified.</strong> '
            . 'Please check your inbox for a verification link. '
            . '<form method="post" action="/email/resend-verification" style="display:inline">'
            . FormHelper::csrfField()
            . '<button type="submit" class="button is-small is-warning is-outlined ml-2">'
            . 'Resend verification email</button>'
            . '</form>'
            . '</div>';
    }

    /**
     * Display a message (success/error) to the user.
     *
     * Renders a Bulma notification with appropriate styling. Error messages
     * (starting with "Error") are shown as danger notifications with a back button.
     * Success messages are shown as success notifications and auto-hide.
     *
     * @param string $message  The message to display
     * @param bool   $autoHide Whether to auto-hide the message (default: true)
     *
     * @return void
     */
    public static function renderMessage(string $message, bool $autoHide = true): void
    {
        if (trim($message) === '') {
            return;
        }

        $escapedMessage = \htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $isError = str_starts_with($message, 'Error');

        if ($isError) {
            $backButton = $autoHide
                ? ''
                : '<button class="button is-small mt-2" data-action="go-back">' .
                  IconHelper::render('arrow-left', ['alt' => 'Go back']) .
                  '<span class="ml-1">Go back and correct</span></button>';

            echo '<div class="notification is-danger" role="alert">' .
                '<button class="delete" aria-label="close"></button>' .
                '<strong>Error:</strong> ' . $escapedMessage .
                $backButton .
                '</div>';
        } else {
            $autoHideAttr = $autoHide ? ' data-auto-hide="true"' : '';
            echo '<div class="notification is-success" role="status"' . $autoHideAttr . '>' .
                '<button class="delete" aria-label="close"></button>' .
                $escapedMessage .
                '</div>';
        }
    }
}
