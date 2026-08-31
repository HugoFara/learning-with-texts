<?php

/**
 * Autocomplete Connection Use Case
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
 * Use case for auto-filling connection with server defaults.
 *
 * This use case does NOT require database access.
 *
 * @since 3.0.0
 */
class AutocompleteConnection
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
     * Returns connection data pre-filled with server environment values.
     *
     * @return DatabaseConnectionDTO Pre-filled connection data
     */
    public function execute(): DatabaseConnectionDTO
    {
        return $this->repository->getAutocompleteSuggestions();
    }
}
