<?php

/**
 * \file
 * \brief Request-scoped application context for LWT.
 *
 * Holds the state that is genuinely per-request and needed almost
 * everywhere: which database is in use, and who is asking. It replaces
 * the scattered `global $var` declarations this codebase used to carry.
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

namespace Lwt\Shared\Infrastructure;

use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Exception\AuthException;

/**
 * Request-scoped application context.
 *
 * Two things live here. The database handle and name are a convenience
 * facade over Connection, which owns the mysqli resource. The user
 * context (id, admin flag, multi-user mode) is load-bearing: QueryBuilder
 * reads it to scope every query to the current user, so a repository that
 * looks unscoped is in fact filtered. Clear it and queries stop being
 * fenced — treat setCurrentUserId() as a security boundary, not a setting.
 *
 * Usage:
 * ```php
 * // Get database connection
 * $db = Globals::getDbConnection();
 *
 * // Get current user ID
 * $userId = Globals::getCurrentUserId();
 *
 * // Require user ID (throws if not authenticated)
 * $userId = Globals::requireUserId();
 * ```
 *
 * @category Lwt
 * @package  Lwt\Shared\Infrastructure
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */
class Globals
{
    /**
     * Database name
     *
     * @var string
     */
    private static string $databaseName = '';

    /**
     * Current authenticated user ID
     *
     * @var int|null
     */
    private static ?int $currentUserId = null;

    /**
     * Whether multi-user mode is enabled
     *
     * When enabled, user_id filtering is applied to queries.
     *
     * @var bool
     */
    private static bool $multiUserEnabled = false;

    /**
     * Whether the current authenticated user is an admin.
     *
     * @var bool
     */
    private static bool $currentUserIsAdmin = false;

    /**
     * Whether backup restore is enabled.
     *
     * Disabled by default in multi-user mode for security.
     *
     * @var bool|null Null means use default based on multi-user mode
     */
    private static ?bool $backupRestoreEnabled = null;

    /**
     * Set the database connection.
     *
     * @param \mysqli $connection The mysqli connection object
     *
     * @return void
     */
    public static function setDbConnection(\mysqli $connection): void
    {
        Connection::setInstance($connection);
    }

    /**
     * Get the database connection.
     *
     * @return \mysqli|null The database connection object
     */
    public static function getDbConnection(): ?\mysqli
    {
        return Connection::tryGetInstance();
    }

    /**
     * Set the database name.
     *
     * @param string $name The database name
     *
     * @return void
     */
    public static function setDatabaseName(string $name): void
    {
        self::$databaseName = $name;
    }

    /**
     * Get the database name.
     *
     * @return string The database name
     */
    public static function getDatabaseName(): string
    {
        return self::$databaseName;
    }

    // =========================================================================
    // User Context Management
    // =========================================================================

    /**
     * Set the current authenticated user ID.
     *
     * This should be called after successful authentication to establish
     * the user context for all subsequent database operations.
     *
     * @param int|null $userId The authenticated user's ID, or null to clear
     *
     * @return void
     *
     * @since 3.0.0
     */
    public static function setCurrentUserId(?int $userId): void
    {
        self::$currentUserId = $userId;
    }

    /**
     * Get the current authenticated user ID.
     *
     * Returns null if no user is authenticated.
     *
     * @return int|null The current user ID or null
     *
     * @since 3.0.0
     */
    public static function getCurrentUserId(): ?int
    {
        return self::$currentUserId;
    }

    /**
     * Get the current user ID, throwing if not authenticated.
     *
     * Use this method when a user must be authenticated for the operation
     * to proceed. It provides a cleaner alternative to checking for null.
     *
     * Usage:
     * ```php
     * try {
     *     $userId = Globals::requireUserId();
     *     // Proceed with user-specific operation
     * } catch (AuthException $e) {
     *     // Handle unauthenticated user
     * }
     * ```
     *
     * @return int The current user ID
     *
     * @throws AuthException If no user is authenticated
     *
     * @since 3.0.0
     */
    public static function requireUserId(): int
    {
        if (self::$currentUserId === null) {
            throw AuthException::userNotAuthenticated();
        }
        return self::$currentUserId;
    }

    /**
     * Check if a user is currently authenticated.
     *
     * @return bool True if a user is authenticated
     *
     * @since 3.0.0
     */
    public static function isAuthenticated(): bool
    {
        return self::$currentUserId !== null;
    }

    /**
     * Enable multi-user mode.
     *
     * When enabled, QueryBuilder will automatically filter queries by user_id
     * for user-scoped tables.
     *
     * @param bool $enabled Whether to enable multi-user mode
     *
     * @return void
     *
     * @since 3.0.0
     */
    public static function setMultiUserEnabled(bool $enabled): void
    {
        self::$multiUserEnabled = $enabled;
    }

    /**
     * Check if multi-user mode is enabled.
     *
     * @return bool True if multi-user mode is enabled
     *
     * @since 3.0.0
     */
    public static function isMultiUserEnabled(): bool
    {
        return self::$multiUserEnabled;
    }

    /**
     * Set whether the current user is an admin.
     *
     * This should be called after successful authentication to establish
     * the admin context for authorization checks.
     *
     * @param bool $isAdmin Whether the current user is an admin
     *
     * @return void
     *
     * @since 3.0.0
     */
    public static function setCurrentUserIsAdmin(bool $isAdmin): void
    {
        self::$currentUserIsAdmin = $isAdmin;
    }

    /**
     * Check if the current authenticated user is an admin.
     *
     * @return bool True if the current user is an admin
     *
     * @since 3.0.0
     */
    public static function isCurrentUserAdmin(): bool
    {
        return self::$currentUserIsAdmin;
    }

    /**
     * Set whether backup restore is enabled.
     *
     * @param bool|null $enabled Whether to enable backup restore, null for default
     *
     * @return void
     *
     * @since 3.0.0
     */
    public static function setBackupRestoreEnabled(?bool $enabled): void
    {
        self::$backupRestoreEnabled = $enabled;
    }

    /**
     * Check if backup restore is enabled.
     *
     * In multi-user mode, backup restore is disabled by default for security.
     * Single-user mode allows restore by default.
     * This can be overridden by explicitly setting BACKUP_RESTORE_ENABLED.
     *
     * @return bool True if backup restore is enabled
     *
     * @since 3.0.0
     */
    public static function isBackupRestoreEnabled(): bool
    {
        // Explicit configuration takes precedence
        if (self::$backupRestoreEnabled !== null) {
            return self::$backupRestoreEnabled;
        }

        // Default: disabled in multi-user mode, enabled in single-user
        return !self::$multiUserEnabled;
    }

    /**
     * Check whether the given language belongs to the current user.
     *
     * Returns true in single-user mode unconditionally — there are
     * no other users to fence against. In multi-user mode, leverages
     * the auto-scoping on the `languages` table: an EXISTS that
     * comes back negative means either the row doesn't exist or it
     * belongs to someone else, and either way the caller must
     * refuse the request.
     *
     * Use this on any handler that writes a cross-table reference
     * with a client-supplied LgID (local-dict create, feed create,
     * etc.) — without the check, an authenticated user can pin
     * their new row against a stranger's language.
     */
    public static function languageBelongsToCurrentUser(int $langId): bool
    {
        if (!self::$multiUserEnabled) {
            return true;
        }
        if ($langId <= 0) {
            return false;
        }
        return \Lwt\Shared\Infrastructure\Database\QueryBuilder::table('languages')
            ->where('LgID', '=', $langId)
            ->existsPrepared();
    }

    /**
     * Reset all globals to initial state.
     *
     * Primarily used for testing.
     *
     * @return void
     */
    public static function reset(): void
    {
        Connection::reset();
        self::$databaseName = '';
        self::$currentUserId = null;
        self::$multiUserEnabled = false;
        self::$currentUserIsAdmin = false;
        self::$backupRestoreEnabled = null;
    }
}
