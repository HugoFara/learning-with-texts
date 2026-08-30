<?php

/**
 * Setting Definitions
 *
 * Contains all application setting definitions with defaults and validation rules.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Admin\Domain
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Admin\Domain;

/**
 * Application setting definitions.
 *
 * Provides default values and validation rules for all application settings.
 *
 * @since 3.0.0
 */
final class SettingDefinitions
{
    /**
     * Setting scope: admin-only (server-wide).
     */
    public const SCOPE_ADMIN = 'admin';

    /**
     * Setting scope: user preference (per-user in multi-user mode).
     */
    public const SCOPE_USER = 'user';

    /**
     * Setting definitions with defaults, validation rules, and scope.
     *
     * Each setting has:
     * - default: Default value (string). The legacy "dft" key is still
     *   accepted on read for backwards compatibility but is deprecated.
     * - numeric: Whether it holds a numeric value (bool). The legacy "num"
     *   key (int 0/1) is still accepted on read but is deprecated.
     * - min: Minimum value (for numeric settings)
     * - max: Maximum value (for numeric settings)
     * - scope: 'admin' or 'user' (defaults to 'user' if not set)
     *
     * @var array<string, array{default: string, numeric: bool, min?: int, max?: int, scope: string}>
     */
    private const DEFINITIONS = [
        // User preferences: reading
        'set-words-to-do-buttons' => [
            "default" => '1', "numeric" => false, "scope" => self::SCOPE_USER
        ],
        'set-tooltip-mode' => [
            "default" => '2', "numeric" => false, "scope" => self::SCOPE_USER
        ],
        'set-display-text-frame-term-translation' => [
            "default" => '1', "numeric" => false, "scope" => self::SCOPE_USER
        ],
        'set-text-frame-annotation-position' => [
            "default" => '2', "numeric" => false, "scope" => self::SCOPE_USER
        ],
        'set-text-visit-statuses-via-key' => [
            "default" => '', "numeric" => false, "scope" => self::SCOPE_USER
        ],
        'set-show-text-word-counts' => [
            "default" => '1', "numeric" => false, "scope" => self::SCOPE_USER
        ],

        // User preferences: review
        'set-test-main-frame-waiting-time' => [
            "default" => '0', "numeric" => true, "min" => 0, "max" => 9999, "scope" => self::SCOPE_USER
        ],
        'set-test-edit-frame-waiting-time' => [
            "default" => '500', "numeric" => true, "min" => 0, "max" => 99999999, "scope" => self::SCOPE_USER
        ],
        'set-test-sentence-count' => [
            "default" => '1', "numeric" => false, "scope" => self::SCOPE_USER
        ],
        'set-term-sentence-count' => [
            "default" => '1', "numeric" => false, "scope" => self::SCOPE_USER
        ],
        'set-similar-terms-count' => [
            "default" => '0', "numeric" => true, "min" => 0, "max" => 9, "scope" => self::SCOPE_USER
        ],
        'set-term-translation-delimiters' => [
            "default" => '/;|', "numeric" => false, "scope" => self::SCOPE_USER
        ],

        // User preferences: TTS
        'set-tts' => [
            "default" => '1', "numeric" => false, "scope" => self::SCOPE_USER
        ],
        'set-hts' => [
            "default" => '1', "numeric" => false, "scope" => self::SCOPE_USER
        ],

        // User preferences: pagination
        'set-archived_texts-per-page' => [
            "default" => '100', "numeric" => true, "min" => 1, "max" => 9999, "scope" => self::SCOPE_USER
        ],
        'set-texts-per-page' => [
            "default" => '10', "numeric" => true, "min" => 1, "max" => 9999, "scope" => self::SCOPE_USER
        ],
        'set-terms-per-page' => [
            "default" => '100', "numeric" => true, "min" => 1, "max" => 9999, "scope" => self::SCOPE_USER
        ],
        'set-tags-per-page' => [
            "default" => '100', "numeric" => true, "min" => 1, "max" => 9999, "scope" => self::SCOPE_USER
        ],
        'set-articles-per-page' => [
            "default" => '10', "numeric" => true, "min" => 1, "max" => 9999, "scope" => self::SCOPE_USER
        ],
        'set-feeds-per-page' => [
            "default" => '50', "numeric" => true, "min" => 1, "max" => 9999, "scope" => self::SCOPE_USER
        ],
        'set-ggl-translation-per-page' => [
            "default" => '100', "numeric" => true, "min" => 1, "max" => 9999, "scope" => self::SCOPE_USER
        ],
        'set-regex-mode' => [
            "default" => '', "numeric" => false, "scope" => self::SCOPE_USER
        ],

        // User preferences: reading layout
        'set-reader-width' => [
            "default" => '100', "numeric" => true, "min" => 40, "max" => 100,
            "scope" => self::SCOPE_USER
        ],
        'set-reader-text-size' => [
            "default" => '0', "numeric" => true, "min" => 0, "max" => 300,
            "scope" => self::SCOPE_USER
        ],

        // User preferences: appearance
        'set-theme-dir' => [
            "default" => '', "numeric" => false, "scope" => self::SCOPE_USER
        ],

        // User preferences: language/locale
        'app_language' => [
            "default" => 'en', "numeric" => false, "scope" => self::SCOPE_USER
        ],

        // Admin settings: feed limits
        'set-max-articles-with-text' => [
            "default" => '100', "numeric" => true, "min" => 1, "max" => 9999, "scope" => self::SCOPE_ADMIN
        ],
        'set-max-articles-without-text' => [
            "default" => '250', "numeric" => true, "min" => 1, "max" => 9999, "scope" => self::SCOPE_ADMIN
        ],
        'set-max-texts-per-feed' => [
            "default" => '20', "numeric" => true, "min" => 1, "max" => 9999, "scope" => self::SCOPE_ADMIN
        ],

        // Admin settings: multi-user
        'set-allow-registration' => [
            "default" => '1', "numeric" => false, "scope" => self::SCOPE_ADMIN
        ],

        // Admin settings: updates
        'set-check-for-updates' => [
            "default" => '1', "numeric" => false, "scope" => self::SCOPE_ADMIN
        ]
    ];

    /**
     * Get all setting definitions.
     *
     * @return array<string, array{default: string, numeric: bool, min?: int, max?: int, scope: string}>
     */
    public static function getAll(): array
    {
        return self::DEFINITIONS;
    }

    /**
     * Get a specific setting definition.
     *
     * @param string $key Setting key
     *
     * @return array{default: string, numeric: bool, min?: int, max?: int, scope: string}|null
     */
    public static function get(string $key): ?array
    {
        return self::DEFINITIONS[$key] ?? null;
    }

    /**
     * Get the default value for a setting.
     *
     * Reads the "default" field, falling back to the deprecated "dft"
     * field if a definition still uses the legacy key.
     *
     * @param string $key Setting key
     *
     * @return string|null Default value or null if not defined
     */
    public static function getDefault(string $key): ?string
    {
        $def = self::DEFINITIONS[$key] ?? null;
        if ($def === null) {
            return null;
        }
        if (isset($def['default'])) {
            return $def['default'];
        }
        // Back-compat: accept the legacy "dft" key from any third-party
        // setting definitions still using the old field name.
        /** @var array<string, mixed> $def */
        if (isset($def['dft']) && is_string($def['dft'])) {
            return $def['dft'];
        }
        return null;
    }

    /**
     * Check if a setting is numeric.
     *
     * Reads the "numeric" field, falling back to the deprecated "num"
     * field if a definition still uses the legacy key.
     *
     * @param string $key Setting key
     *
     * @return bool True if the setting holds a numeric value
     */
    public static function isNumeric(string $key): bool
    {
        $def = self::DEFINITIONS[$key] ?? null;
        if ($def === null) {
            return false;
        }
        if (isset($def['numeric'])) {
            return $def['numeric'];
        }
        /** @var array<string, mixed> $def */
        if (isset($def['num'])) {
            return (bool)$def['num'];
        }
        return false;
    }

    /**
     * Check if a setting is defined.
     *
     * @param string $key Setting key
     *
     * @return bool
     */
    public static function has(string $key): bool
    {
        return isset(self::DEFINITIONS[$key]);
    }

    /**
     * Get all setting keys.
     *
     * @return string[]
     */
    public static function getKeys(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    /**
     * Get the scope of a setting.
     *
     * @param string $key Setting key
     *
     * @return string Scope ('admin' or 'user')
     */
    public static function getScope(string $key): string
    {
        return self::DEFINITIONS[$key]['scope'] ?? self::SCOPE_USER;
    }

    /**
     * Get all admin-scoped setting keys.
     *
     * @return string[]
     */
    public static function getAdminKeys(): array
    {
        return array_keys(
            array_filter(
                self::DEFINITIONS,
                static fn(array $def): bool => ($def['scope'] ?? self::SCOPE_USER) === self::SCOPE_ADMIN
            )
        );
    }

    /**
     * Get all user-scoped setting keys.
     *
     * @return string[]
     */
    public static function getUserKeys(): array
    {
        return array_keys(
            array_filter(
                self::DEFINITIONS,
                static fn(array $def): bool => ($def['scope'] ?? self::SCOPE_USER) === self::SCOPE_USER
            )
        );
    }
}
