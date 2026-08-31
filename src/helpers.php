<?php

/**
 * Global helper functions for LWT.
 *
 * These functions are available throughout the application and provide
 * convenient shortcuts for common operations.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

use Lwt\Shared\Infrastructure\Http\UrlUtilities;
use Lwt\Shared\Infrastructure\Container\Container;
use Lwt\Shared\I18n\Translator;

if (!function_exists('url')) {
    /**
     * Generate a URL with the application base path prepended.
     *
     * Use this for all internal links to ensure they work correctly
     * when the application is installed in a subdirectory.
     *
     * @param string $path The path to generate URL for (should start with /)
     *
     * @return string The full URL path with base path prepended
     *
     * @example url('/login') returns '/lwt/login' if APP_BASE_PATH=/lwt
     * @example url('/') returns '/lwt' if APP_BASE_PATH=/lwt
     * @example url('/assets/css/main.css') returns '/lwt/assets/css/main.css'
     */
    function url(string $path = '/'): string
    {
        return UrlUtilities::url($path);
    }
}

if (!function_exists('__')) {
    /**
     * Translate a key using the i18n translator.
     *
     * @param string                    $key    Dot-notated translation key (e.g. "common.save")
     * @param array<string, string|int> $params Interpolation parameters
     *
     * @return string Translated string, or the raw key if translator is unavailable
     */
    function __(string $key, array $params = []): string
    {
        $container = Container::getInstance();
        try {
            if ($container->has(Translator::class)) {
                return $container->getTyped(Translator::class)->translate($key, $params);
            }
        } catch (\Throwable $e) {
            // Translator unavailable (e.g. in unit tests with no locale path bound)
        }
        return $key;
    }
}

if (!function_exists('__e')) {
    /**
     * Translate a key and HTML-escape the result for safe output in templates.
     *
     * @param string                    $key    Dot-notated translation key
     * @param array<string, string|int> $params Interpolation parameters
     *
     * @return string HTML-escaped translated string
     */
    function __e(string $key, array $params = []): string
    {
        return htmlspecialchars(__($key, $params), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('base_path')) {
    /**
     * Get the configured application base path.
     *
     * @return string The base path (e.g., '/lwt') or empty string for root
     */
    function base_path(): string
    {
        return UrlUtilities::getBasePath();
    }
}
