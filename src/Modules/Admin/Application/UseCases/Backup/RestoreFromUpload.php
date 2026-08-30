<?php

/**
 * Restore From Upload Use Case
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Admin\Application\UseCases\Backup
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Admin\Application\UseCases\Backup;

use Lwt\Shared\Infrastructure\Globals;
use Lwt\Modules\Admin\Domain\BackupRepositoryInterface;

/**
 * Use case for restoring database from uploaded file.
 *
 * @since 3.0.0
 */
class RestoreFromUpload
{
    /**
     * Hard cap on the uploaded backup file (compressed bytes on disk).
     *
     * 200 MB of gzipped SQL is far above any plausible legitimate LWT
     * backup. The decompressed-bytes cap in Restore::restoreFile is
     * the deeper defense; this stops the worker from buffering an
     * obviously hostile upload before we even open it.
     */
    public const MAX_UPLOAD_SIZE = 200 * 1024 * 1024;

    private BackupRepositoryInterface $repository;

    /**
     * Constructor.
     *
     * @param BackupRepositoryInterface $repository Backup repository
     */
    public function __construct(BackupRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Execute the use case.
     *
     * @param array{name: string, type: string, tmp_name: string, error: int, size: int}|null $fileData
     *        Validated file data from InputValidator::getUploadedFile()
     *
     * @return array{success: bool, error: ?string, message?: string}
     *         On success, `message` carries the restore's own report (query
     *         and record counts), which the caller should show verbatim
     *         rather than substituting a generic "restored" string.
     */
    public function execute(?array $fileData): array
    {
        // Check if restore is enabled
        if (!Globals::isBackupRestoreEnabled()) {
            return [
                'success' => false,
                'error' => "Database restore is disabled. Set BACKUP_RESTORE_ENABLED=true in .env to enable."
            ];
        }

        if ($fileData === null) {
            return ['success' => false, 'error' => "No Restore file specified"];
        }

        // PHP populates $_FILES['size'] from the real upload, not the
        // client-supplied Content-Length, so this is the actual on-disk
        // size of the gzipped backup.
        if (($fileData['size'] ?? 0) > self::MAX_UPLOAD_SIZE) {
            return [
                'success' => false,
                'error' => 'Restore file exceeds the '
                    . intdiv(self::MAX_UPLOAD_SIZE, 1024 * 1024)
                    . ' MB upload limit.'
            ];
        }

        $handle = @gzopen($fileData["tmp_name"], "r");
        if ($handle === false) {
            return ['success' => false, 'error' => "Restore file could not be opened"];
        }

        $message = $this->repository->restoreFromHandle($handle, "Database");
        // Restore::restoreFile reports outcome via a prose string. Treat
        // any "Error:" prefix as failure so the controller surfaces it
        // to the admin instead of claiming a successful restore — the
        // multi-user safety guard relies on this to refuse a wipe of
        // every account.
        if (str_starts_with($message, 'Error:')) {
            return ['success' => false, 'error' => $message];
        }
        return ['success' => true, 'error' => null, 'message' => $message];
    }
}
