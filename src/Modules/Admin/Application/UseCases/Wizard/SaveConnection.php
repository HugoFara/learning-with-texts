<?php

/**
 * Save Connection Use Case
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
 * Use case for saving database connection to .env file.
 *
 * This use case does NOT require database access.
 *
 * @since 3.0.0
 */
class SaveConnection
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
     * @param DatabaseConnectionDTO $connection Connection data to save
     *
     * @return bool True on success
     */
    public function execute(DatabaseConnectionDTO $connection): bool
    {
        return $this->repository->save($connection);
    }

    /**
     * Execute with form data array.
     *
     * @param array<string, mixed> $formData Form input data
     *
     * @return bool True on success
     */
    public function executeFromForm(array $formData): bool
    {
        $dto = DatabaseConnectionDTO::fromFormData($formData);
        return $this->repository->save($dto);
    }
}
