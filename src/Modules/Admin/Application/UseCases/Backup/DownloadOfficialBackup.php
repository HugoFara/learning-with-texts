<?php

/**
 * Download Official Backup Use Case
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

use Lwt\Modules\Admin\Domain\BackupRepositoryInterface;

/**
 * Use case for generating official LWT format backup.
 *
 * This format is compatible with the original LWT application.
 *
 * @since 3.0.0
 */
class DownloadOfficialBackup
{
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
     * Execute the use case - generate and output official backup file.
     *
     * @return never Outputs file and terminates
     */
    public function execute(): never
    {
        $fname = "lwt-backup-" . date('Y-m-d-H-i-s') . ".sql.gz";
        $out = "-- " . $fname . "\n";
        $out .= $this->repository->generateOfficialBackupSql();

        header('Content-Type: application/plain');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        echo gzencode($out);
        exit();
    }

    /**
     * Generate official backup content without outputting.
     *
     * @return array{filename: string, content: string} Backup data
     */
    public function generate(): array
    {
        $fname = "lwt-backup-" . date('Y-m-d-H-i-s') . ".sql.gz";
        $out = "-- " . $fname . "\n";
        $out .= $this->repository->generateOfficialBackupSql();

        return [
            'filename' => $fname,
            'content' => $out
        ];
    }
}
