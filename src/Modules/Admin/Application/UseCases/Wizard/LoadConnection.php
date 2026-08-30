<?php

/**
 * Load Connection Use Case
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Admin\Application\UseCases\Wizard
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Admin\Application\UseCases\Wizard;

use Lwt\Modules\Admin\Application\DTO\DatabaseConnectionDTO;
use Lwt\Modules\Admin\Infrastructure\FileSystemEnvRepository;

/**
 * Use case for loading database connection from .env file.
 *
 * This use case does NOT require database access.
 *
 * @since 3.0.0
 */
class LoadConnection
{
    private FileSystemEnvRepository $repository;

    /**
     * Constructor.
     *
     * @param FileSystemEnvRepository|null $repository Env file repository
     */
    public function __construct(?FileSystemEnvRepository $repository = null)
    {
        $this->repository = $repository ?? new FileSystemEnvRepository();
    }

    /**
     * Execute the use case.
     *
     * @return DatabaseConnectionDTO Connection data
     */
    public function execute(): DatabaseConnectionDTO
    {
        if ($this->repository->exists()) {
            return $this->repository->load();
        }

        return new DatabaseConnectionDTO();
    }

    /**
     * Check if .env file exists.
     *
     * @return bool True if file exists
     */
    public function envExists(): bool
    {
        return $this->repository->exists();
    }

    /**
     * Get path to .env file.
     *
     * @return string Path to .env file
     */
    public function getEnvPath(): string
    {
        return $this->repository->getPath();
    }
}
