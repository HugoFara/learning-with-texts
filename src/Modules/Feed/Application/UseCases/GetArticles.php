<?php

/**
 * Get Articles Use Case
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

use Lwt\Modules\Feed\Domain\Article;
use Lwt\Modules\Feed\Domain\ArticleRepositoryInterface;

/**
 * Use case for getting articles with status information.
 *
 * @since 3.0.0
 */
class GetArticles
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
     * Execute the use case for multiple feeds.
     *
     * @param int[]  $feedIds   Feed IDs
     * @param int    $offset    Pagination offset
     * @param int    $limit     Page size
     * @param string $orderBy   Sort column
     * @param string $direction Sort direction
     * @param string $search    Search query
     *
     * @return array{
     *     articles: array<array{article: Article, text_id: int|null, archived_id: int|null, status: string}>,
     *     total: int
     * }
     */
    public function execute(
        array $feedIds,
        int $offset = 0,
        int $limit = 50,
        string $orderBy = 'FlDate',
        string $direction = 'DESC',
        string $search = ''
    ): array {
        if (empty($feedIds)) {
            return ['articles' => [], 'total' => 0];
        }

        $articles = $this->articleRepository->findByFeedsWithStatus(
            $feedIds,
            $offset,
            $limit,
            $orderBy,
            $direction,
            $search
        );

        $total = $this->articleRepository->countByFeeds($feedIds, $search);

        return [
            'articles' => $articles,
            'total' => $total,
        ];
    }

    /**
     * Get articles for a single feed.
     *
     * @param int    $feedId    Feed ID
     * @param int    $offset    Pagination offset
     * @param int    $limit     Page size
     * @param string $orderBy   Sort column
     * @param string $direction Sort direction
     * @param string $search    Search query
     *
     * @return array{
     *     articles: array<array{article: Article, text_id: int|null, archived_id: int|null, status: string}>,
     *     total: int
     * }
     */
    public function executeForFeed(
        int $feedId,
        int $offset = 0,
        int $limit = 50,
        string $orderBy = 'FlDate',
        string $direction = 'DESC',
        string $search = ''
    ): array {
        return $this->execute([$feedId], $offset, $limit, $orderBy, $direction, $search);
    }

    /**
     * Get a single article by ID.
     *
     * @param int $articleId Article ID
     *
     * @return Article|null Article entity or null
     */
    public function getById(int $articleId): ?Article
    {
        return $this->articleRepository->find($articleId);
    }

    /**
     * Get multiple articles by IDs.
     *
     * @param int[] $articleIds Article IDs
     *
     * @return Article[]
     */
    public function getByIds(array $articleIds): array
    {
        return $this->articleRepository->findByIds($articleIds);
    }
}
