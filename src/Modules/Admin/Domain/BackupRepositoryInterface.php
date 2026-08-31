<?php

/**
 * Backup Repository Interface
 *
 * Domain port for backup/restore operations.
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
 * Repository interface for backup operations.
 *
 * This is a domain port defining the contract for backup/restore operations.
 * Infrastructure implementations provide the actual database access.
 *
 * @since 3.0.0
 */
interface BackupRepositoryInterface
{
    /**
     * Get the database name.
     *
     * @return string Database name
     */
    public function getDatabaseName(): string;

    /**
     * Restore database from a file handle.
     *
     * @param resource $handle   File handle to read from
     * @param string   $fileName File name for logging
     *
     * @return string Status message
     */
    public function restoreFromHandle($handle, string $fileName): string;

    /**
     * Get backup SQL for all tables.
     *
     * @return string SQL dump content
     */
    public function generateBackupSql(): string;

    /**
     * Get official LWT format backup SQL.
     *
     * @return string SQL dump in official format
     */
    public function generateOfficialBackupSql(): string;

    /**
     * Truncate all user data tables (keep settings).
     *
     * @return void
     */
    public function truncateUserTables(): void;

    /**
     * Get list of tables to backup.
     *
     * @return string[] Table names
     */
    public function getBackupTables(): array;

    /**
     * Get list of tables for official backup format.
     *
     * @return string[] Table names
     */
    public function getOfficialBackupTables(): array;
}
