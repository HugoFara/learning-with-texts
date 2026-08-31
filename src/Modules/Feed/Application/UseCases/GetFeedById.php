<?php

/**
 * Get Feed By ID Use Case
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Feed\Application\UseCases
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Feed\Application\UseCases;

use Lwt\Modules\Feed\Domain\Feed;
use Lwt\Modules\Feed\Domain\FeedRepositoryInterface;

/**
 * Use case for retrieving a single feed by ID.
 *
 * @since 3.0.0
 */
class GetFeedById
{
    /**
     * Constructor.
     *
     * @param FeedRepositoryInterface $feedRepository Feed repository
     */
    public function __construct(
        private FeedRepositoryInterface $feedRepository
    ) {
    }

    /**
     * Execute the use case.
     *
     * @param int $feedId Feed ID
     *
     * @return Feed|null Feed entity or null if not found
     */
    public function execute(int $feedId): ?Feed
    {
        return $this->feedRepository->find($feedId);
    }

    /**
     * Check if a feed exists.
     *
     * @param int $feedId Feed ID
     *
     * @return bool True if exists
     */
    public function exists(int $feedId): bool
    {
        return $this->feedRepository->exists($feedId);
    }
}
