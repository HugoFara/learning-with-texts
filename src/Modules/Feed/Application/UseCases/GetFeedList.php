<?php

/**
 * Get Feed List Use Case
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
use Lwt\Modules\Feed\Domain\Feed;
use Lwt\Modules\Feed\Domain\FeedRepositoryInterface;

/**
 * Use case for getting a paginated list of feeds.
 *
 * @since 3.0.0
 */
class GetFeedList
{
    /**
     * Constructor.
     *
     * @param FeedRepositoryInterface    $feedRepository    Feed repository
     * @param ArticleRepositoryInterface $articleRepository Article repository
     */
    public function __construct(
        private FeedRepositoryInterface $feedRepository,
        private ArticleRepositoryInterface $articleRepository
    ) {
    }

    /**
     * Execute the use case.
     *
     * @param int         $offset       Pagination offset
     * @param int         $limit        Page size
     * @param int|null    $languageId   Filter by language (null for all)
     * @param string|null $queryPattern Search pattern for name
     * @param string      $orderBy      Sort column
     * @param string      $direction    Sort direction
     *
     * @return array{
     *     feeds: Feed[],
     *     total: int,
     *     article_counts: array<int, int>
     * }
     */
    public function execute(
        int $offset = 0,
        int $limit = 50,
        ?int $languageId = null,
        ?string $queryPattern = null,
        string $orderBy = 'NfUpdate',
        string $direction = 'DESC'
    ): array {
        $feeds = $this->feedRepository->findPaginated(
            $offset,
            $limit,
            $languageId,
            $queryPattern,
            $orderBy,
            $direction
        );

        $total = $this->feedRepository->countFeeds($languageId, $queryPattern);

        // Get article counts for each feed
        $feedIds = array_map(
            fn(Feed $feed) => (int) $feed->id(),
            $feeds
        );

        $articleCounts = !empty($feedIds)
            ? $this->articleRepository->getCountPerFeed($feedIds)
            : [];

        return [
            'feeds' => $feeds,
            'total' => $total,
            'article_counts' => $articleCounts,
        ];
    }

    /**
     * Get all feeds without pagination.
     *
     * @param int|null $languageId Filter by language (null for all)
     * @param string   $orderBy    Sort column
     * @param string   $direction  Sort direction
     *
     * @return Feed[]
     */
    public function executeAll(
        ?int $languageId = null,
        string $orderBy = 'NfUpdate',
        string $direction = 'DESC'
    ): array {
        if ($languageId !== null && $languageId > 0) {
            return $this->feedRepository->findByLanguage($languageId, $orderBy, $direction);
        }

        return $this->feedRepository->findAll($orderBy, $direction);
    }

    /**
     * Get feeds for select dropdown.
     *
     * @param int $languageId    Language ID (0 for all)
     * @param int $maxNameLength Maximum name length before truncation
     *
     * @return array<array{id: int, name: string, language_id: int}>
     */
    public function executeForSelect(int $languageId = 0, int $maxNameLength = 40): array
    {
        return $this->feedRepository->getForSelect($languageId, $maxNameLength);
    }
}
