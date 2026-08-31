<?php

/**
 * Delete Articles Use Case
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

use Lwt\Modules\Feed\Domain\ArticleRepositoryInterface;
use Lwt\Modules\Feed\Domain\FeedRepositoryInterface;

/**
 * Use case for deleting articles.
 *
 * @since 3.0.0
 */
class DeleteArticles
{
    /**
     * Constructor.
     *
     * @param ArticleRepositoryInterface $articleRepository Article repository
     * @param FeedRepositoryInterface    $feedRepository    Feed repository
     */
    public function __construct(
        private ArticleRepositoryInterface $articleRepository,
        private FeedRepositoryInterface $feedRepository
    ) {
    }

    /**
     * Delete articles by IDs.
     *
     * @param int[] $articleIds Article IDs to delete
     *
     * @return int Number of deleted articles
     */
    public function execute(array $articleIds): int
    {
        if (empty($articleIds)) {
            return 0;
        }

        return $this->articleRepository->deleteByIds($articleIds);
    }

    /**
     * Delete all articles for specified feeds.
     *
     * @param int[] $feedIds Feed IDs
     *
     * @return int Number of deleted articles
     */
    public function executeByFeeds(array $feedIds): int
    {
        if (empty($feedIds)) {
            return 0;
        }

        $deleted = $this->articleRepository->deleteByFeeds($feedIds);

        // Update feed timestamps
        foreach ($feedIds as $feedId) {
            $this->feedRepository->updateTimestamp($feedId, time());
        }

        return $deleted;
    }

    /**
     * Delete all articles for a single feed.
     *
     * @param int $feedId Feed ID
     *
     * @return int Number of deleted articles
     */
    public function executeByFeed(int $feedId): int
    {
        return $this->executeByFeeds([$feedId]);
    }
}
