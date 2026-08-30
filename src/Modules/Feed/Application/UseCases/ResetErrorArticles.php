<?php

/**
 * Reset Error Articles Use Case
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

/**
 * Use case for resetting error-marked articles.
 *
 * Articles are marked as errors by prepending a space to their link.
 * This use case removes that marker to allow reimport attempts.
 *
 * @since 3.0.0
 */
class ResetErrorArticles
{
    /**
     * Constructor.
     *
     * @param ArticleRepositoryInterface $articleRepository Article repository
     */
    public function __construct(
        private ArticleRepositoryInterface $articleRepository
    ) {
    }

    /**
     * Execute the use case.
     *
     * @param int[] $feedIds Feed IDs to reset error articles for
     *
     * @return int Number of reset articles
     */
    public function execute(array $feedIds): int
    {
        if (empty($feedIds)) {
            return 0;
        }

        return $this->articleRepository->resetErrorsByFeeds($feedIds);
    }

    /**
     * Reset errors for a single feed.
     *
     * @param int $feedId Feed ID
     *
     * @return int Number of reset articles
     */
    public function executeForFeed(int $feedId): int
    {
        return $this->execute([$feedId]);
    }
}
