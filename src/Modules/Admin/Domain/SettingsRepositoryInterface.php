<?php

/**
 * Settings Repository Interface
 *
 * Domain port for settings persistence operations.
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
 * Repository interface for settings operations.
 *
 * This is a domain port defining the contract for settings persistence.
 * Infrastructure implementations provide the actual database access.
 *
 * @since 3.0.0
 */
interface SettingsRepositoryInterface
{
    /**
     * Get a setting value by key.
     *
     * @param string $key     Setting key
     * @param string $default Default value if not found
     *
     * @return string Setting value
     */
    public function get(string $key, string $default = ''): string;

    /**
     * Save a setting value.
     *
     * @param string $key   Setting key
     * @param string $value Setting value
     *
     * @return void
     */
    public function save(string $key, string $value): void;

    /**
     * Delete settings matching a pattern.
     *
     * @param string $pattern LIKE pattern for keys to delete
     *
     * @return int Number of deleted settings
     */
    public function deleteByPattern(string $pattern): int;

    /**
     * Get all settings as key-value pairs.
     *
     * @return array<string, string> All settings
     */
    public function getAll(): array;

    /**
     * Check if a setting exists.
     *
     * @param string $key Setting key
     *
     * @return bool True if exists
     */
    public function exists(string $key): bool;
}
