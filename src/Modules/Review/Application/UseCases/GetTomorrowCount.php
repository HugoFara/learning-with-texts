<?php

/**
 * Get Tomorrow Count Use Case
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Review\Application\UseCases
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Review\Application\UseCases;

use Lwt\Modules\Review\Domain\ReviewRepositoryInterface;
use Lwt\Modules\Review\Domain\ReviewConfiguration;

/**
 * Use case for getting count of words due tomorrow.
 *
 * @since 3.0.0
 */
class GetTomorrowCount
{
    private ReviewRepositoryInterface $repository;

    /**
     * Constructor.
     *
     * @param ReviewRepositoryInterface $repository Review repository
     */
    public function __construct(ReviewRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Get count of words due tomorrow.
     *
     * @param ReviewConfiguration $config Test configuration
     *
     * @return array{count: int}
     */
    public function execute(ReviewConfiguration $config): array
    {
        if (!$config->isValid()) {
            return ['count' => 0];
        }

        return [
            'count' => $this->repository->getTomorrowCount($config)
        ];
    }
}
