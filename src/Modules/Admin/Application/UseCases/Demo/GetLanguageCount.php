<?php

/**
 * Get Language Count Use Case
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Admin\Application\UseCases\Demo
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Admin\Application\UseCases\Demo;

use Lwt\Modules\Admin\Infrastructure\MySqlStatisticsRepository;

/**
 * Use case for getting language count.
 *
 * Used to warn users before installing demo database.
 *
 * @since 3.0.0
 */
class GetLanguageCount
{
    private MySqlStatisticsRepository $repository;

    /**
     * Constructor.
     *
     * @param MySqlStatisticsRepository|null $repository Statistics repository
     */
    public function __construct(?MySqlStatisticsRepository $repository = null)
    {
        $this->repository = $repository ?? new MySqlStatisticsRepository();
    }

    /**
     * Execute the use case.
     *
     * @return int Language count
     */
    public function execute(): int
    {
        return $this->repository->getLanguageCount();
    }
}
