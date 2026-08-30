<?php

/**
 * \file
 * \brief Application settings management.
 *
 * PHP version 8.2
 *
 * @category Database
 * @package  Lwt
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Shared\Infrastructure\Database;

use Lwt\Shared\Infrastructure\Globals;
use Lwt\Shared\Infrastructure\Utilities\ErrorHandler;
use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Modules\Admin\Domain\SettingDefinitions;

/**
 * Application settings management.
 *
 * Provides methods for reading, writing, and managing application settings
 * stored in the database, as well as LWT general table operations.
 *
 * @since 3.0.0
 */
class Settings
{
    /**
     * Convert a setting to 0 or 1.
     *
     * @param string     $key The setting key
     * @param string|int $dft Default value to use, should be convertible to string
     *
     * @return int
     *
     * @psalm-return 0|1
     */
    public static function getZeroOrOne(string $key, string|int $dft): int
    {
        $r = self::get($key);
        if ($r === '') {
            return (int)$dft !== 0 ? 1 : 0;
        }
        return (int)$r !== 0 ? 1 : 0;
    }

    /**
     * Get a setting from the database. It can also check for its validity.
     *
     * @param string $key Setting key. If $key is 'currentlanguage' or
     *                    'currenttext', we validate language/text.
     *
     * @return string Value in the database if found, or an empty string
     */
    public static function get(string $key): string
    {
        if ($key === '') {
            return '';
        }
        $val = QueryBuilder::table('settings')
            ->where('StKey', '=', $key)
            ->valuePrepared('StValue');
        if (isset($val)) {
            $val = trim((string) $val);
            if ($key == 'currentlanguage') {
                $val = Validation::language($val);
            }
            if ($key == 'currenttext') {
                $val = Validation::text($val);
            }
            return $val;
        }
        return '';
    }

    /**
     * Get the settings value for a specific key. Return a default value when possible.
     *
     * In multi-user mode, user-scoped settings are checked for the current user
     * first, then fall through to the hardcoded default — never to the global
     * `StUsID=0` row, since that would leak the prior user's choice into a fresh
     * account. Admin-scoped settings still fall back to `StUsID=0` (that row is
     * how admins set system-wide values), and single-user mode (no current
     * user) keeps the original `StUsID=0` → hardcoded-default chain.
     *
     * @param string $key Settings key
     *
     * @return string Requested setting, or default value, or ''
     */
    public static function getWithDefault(string $key): string
    {
        if ($key === '') {
            return '';
        }

        $userId = Globals::getCurrentUserId();
        $isUserScope = SettingDefinitions::getScope($key) === SettingDefinitions::SCOPE_USER;

        // For user-scoped settings in multi-user mode, try user-specific row first
        if ($userId !== null && $isUserScope) {
            try {
                $val = (string) Connection::preparedFetchValue(
                    "SELECT StValue FROM settings WHERE StKey = ? AND StUsID = ?",
                    [$key, $userId],
                    'StValue'
                );
                if ($val !== '') {
                    return trim($val);
                }
            } catch (\RuntimeException $e) {
                // DB not available — fall through
            }

            // Skip the StUsID=0 fallback for SCOPE_USER keys when a user is
            // logged in: that row is the previous user's saved value, not a
            // legitimate default for this account.
            return SettingDefinitions::getDefault($key) ?? '';
        }

        // Fall back to global row (StUsID=0)
        try {
            $val = (string) QueryBuilder::table('settings')
                ->where('StKey', '=', $key)
                ->where('StUsID', '=', 0)
                ->valuePrepared('StValue');
            if ($val != '') {
                return trim($val);
            }
        } catch (\RuntimeException $e) {
            // DB not available — fall through to default
        }
        return SettingDefinitions::getDefault($key) ?? '';
    }

    /**
     * Save the setting identified by a key with a specific value.
     *
     * @param string $k Setting key
     * @param mixed  $v Setting value, will get converted to string
     *
     * @return void
     *
     * @throws \InvalidArgumentException If value is not set or is empty
     */
    public static function save(string $k, mixed $v): void
    {
        $defs = SettingDefinitions::getAll();
        if (!isset($v)) {
            throw new \InvalidArgumentException('Value is not set');
        }
        if (SettingDefinitions::isNumeric($k)) {
            $v = (int)$v;
            $default = SettingDefinitions::getDefault($k) ?? '';
            if (isset($defs[$k]['min']) && $v < $defs[$k]['min']) {
                $v = $default;
            }
            if (isset($defs[$k]['max']) && $v > $defs[$k]['max']) {
                $v = $default;
            }
        }
        // Use INSERT ... ON DUPLICATE KEY UPDATE for atomic upsert
        // Settings table has composite primary key (StKey, StUsID)
        // StUsID defaults to 0 for single-user mode
        Connection::preparedExecute(
            "INSERT INTO settings (StKey, StUsID, StValue) VALUES (?, 0, ?)
             ON DUPLICATE KEY UPDATE StValue = ?",
            [$k, (string)$v, (string)$v]
        );
    }

    /**
     * Save a setting under the current user's scope when multi-user mode is
     * enabled, or globally otherwise.
     *
     * `Settings::get()` already reads through QueryBuilder, which auto-scopes
     * the `settings` table; in multi-user mode that means a `Settings::save`
     * to `StUsID=0` is invisible to the reader and the value is silently lost
     * (or worse, overwrites the global default seen by users with no
     * per-user row). This helper picks the matching write path so reads find
     * the value the current request just stored.
     *
     * @param string $k Setting key
     * @param mixed  $v Setting value, will get converted to string
     */
    public static function savePerUser(string $k, mixed $v): void
    {
        $userId = Globals::isMultiUserEnabled() ? Globals::getCurrentUserId() : null;
        if ($userId !== null) {
            self::saveForUser($k, $v, $userId);
        } else {
            self::save($k, $v);
        }
    }

    /**
     * Save a user-scoped setting for a specific user.
     *
     * Uses StUsID = $userId for per-user storage.
     *
     * @param string $k      Setting key
     * @param mixed  $v      Setting value
     * @param int    $userId User ID
     *
     * @return void
     */
    public static function saveForUser(string $k, mixed $v, int $userId): void
    {
        $defs = SettingDefinitions::getAll();
        if (!isset($v)) {
            throw new \InvalidArgumentException('Value is not set');
        }
        if (SettingDefinitions::isNumeric($k)) {
            $v = (int)$v;
            $default = SettingDefinitions::getDefault($k) ?? '';
            if (isset($defs[$k]['min']) && $v < $defs[$k]['min']) {
                $v = $default;
            }
            if (isset($defs[$k]['max']) && $v > $defs[$k]['max']) {
                $v = $default;
            }
        }
        Connection::preparedExecute(
            "INSERT INTO settings (StKey, StUsID, StValue) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE StValue = ?",
            [$k, $userId, (string)$v, (string)$v]
        );
    }

    /**
     * Check if the _lwtgeneral table exists, create it if not.
     *
     * @return void
     */
    public static function lwtTableCheck(): void
    {
        $tables = Connection::fetchAll("SHOW TABLES LIKE '\\_lwtgeneral'");
        if (empty($tables)) {
            Connection::execute(
                "CREATE TABLE IF NOT EXISTS _lwtgeneral (
                    LWTKey varchar(40) NOT NULL,
                    LWTValue varchar(40) DEFAULT NULL,
                    PRIMARY KEY (LWTKey)
                ) ENGINE=MyISAM DEFAULT CHARSET=utf8"
            );
            $tables2 = Connection::fetchAll("SHOW TABLES LIKE '\\_lwtgeneral'");
            if (empty($tables2)) {
                ErrorHandler::die("Unable to create table '_lwtgeneral'!");
            }
        }
    }

    /**
     * Set a value in the _lwtgeneral table.
     *
     * @param string $key Key to set
     * @param string $val Value to store
     *
     * @return void
     */
    public static function lwtTableSet(string $key, string $val): void
    {
        self::lwtTableCheck();
        Connection::preparedExecute(
            "INSERT INTO _lwtgeneral (LWTKey, LWTValue) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE LWTValue = ?",
            [$key, $val, $val]
        );
    }

    /**
     * Get a value from the _lwtgeneral table.
     *
     * @param string $key Key to retrieve
     *
     * @return string Value or empty string if not found
     */
    public static function lwtTableGet(string $key): string
    {
        self::lwtTableCheck();
        return (string)Connection::preparedFetchValue(
            "SELECT LWTValue as value
            FROM _lwtgeneral
            WHERE LWTKey = ?",
            [$key]
        );
    }
}
