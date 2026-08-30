<?php

/**
 * Unit tests for GetArticles use case.
 *
 * PHP version 8.2
 *
 * @category Testing
 * @package  Lwt\Tests\Modules\Feed\Application\UseCases
 * @license  Unlicense <http://unlicense.org/>
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Tests\Modules\Feed\Application\UseCases;

use Lwt\Modules\Feed\Application\UseCases\GetArticles;
use Lwt\Modules\Feed\Domain\Article;
use Lwt\Modules\Feed\Domain\ArticleRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the GetArticles use case.
 *
 * Verifies article retrieval by feed IDs, single feed, by ID,
 * and empty/edge case handling.
 *
 * @since 3.0.0
 */
class GetArticlesTest extends TestCase
{
    private ArticleRepositoryInterface&MockObject $articleRepository;
    private GetArticles $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->articleRepository = $this->createMock(ArticleRepositoryInterface::class);
        $this->useCase = new GetArticles($this->articleRepository);
    }

    /**
     * Create an Article entity for testing.
     */
    private function makeArticle(
        int $id,
        int $feedId,
        string $title,
        string $link
    ): Article {
        return Article::reconstitute(
            $id,
            $feedId,
            $title,
            $link,
            '',
            '2026-01-01',
            '',
            ''
        );
    }

    // -----------------------------------------------------------------
    // execute (multiple feeds)
    // -----------------------------------------------------------------

    #[Test]
    public function executeReturnsArticlesAndTotal(): void
    {
        $article = $this->makeArticle(1, 10, 'Article One', 'https://example.com/1');

        $articleData = [
            [
                'article' => $article,
                'text_id' => null,
                'archived_id' => null,
                'status' => 'new',
            ],
        ];

        $this->articleRepository
            ->expects($this->once())
            ->method('findByFeedsWithStatus')
            ->with([10], 0, 50, 'FlDate', 'DESC', '')
            ->willReturn($articleData);

        $this->articleRepository
            ->expects($this->once())
            ->method('countByFeeds')
            ->with([10], '')
            ->willReturn(1);

        $result = $this->useCase->execute([10]);

        $this->assertCount(1, $result['articles']);
        $this->assertSame(1, $result['total']);
        $this->assertSame('new', $result['articles'][0]['status']);
    }

    #[Test]
    public function executeWithEmptyFeedIdsReturnsEmptyResult(): void
    {
        $this->articleRepository
            ->expects($this->never())
            ->method('findByFeedsWithStatus');

        $this->articleRepository
            ->expects($this->never())
            ->method('countByFeeds');

        $result = $this->useCase->execute([]);

        $this->assertSame([], $result['articles']);
        $this->assertSame(0, $result['total']);
    }

    #[Test]
    public function executePassesPaginationParameters(): void
    {
        $this->articleRepository
            ->expects($this->once())
            ->method('findByFeedsWithStatus')
            ->with([1, 2], 20, 10, 'FlTitle', 'ASC', 'search term')
            ->willReturn([]);

        $this->articleRepository
            ->expects($this->once())
            ->method('countByFeeds')
            ->with([1, 2], 'search term')
            ->willReturn(0);

        $result = $this->useCase->execute(
            feedIds: [1, 2],
            offset: 20,
            limit: 10,
            orderBy: 'FlTitle',
            direction: 'ASC',
            search: 'search term'
        );

        $this->assertSame([], $result['articles']);
        $this->assertSame(0, $result['total']);
    }

    #[Test]
    public function executeWithMultipleFeedIds(): void
    {
        $article1 = $this->makeArticle(1, 10, 'Art 1', 'https://a.com/1');
        $article2 = $this->makeArticle(2, 20, 'Art 2', 'https://b.com/2');

        $articleData = [
            ['article' => $article1, 'text_id' => 100, 'archived_id' => null, 'status' => 'imported'],
            ['article' => $article2, 'text_id' => null, 'archived_id' => 50, 'status' => 'archived'],
        ];

        $this->articleRepository
            ->expects($this->once())
            ->method('findByFeedsWithStatus')
            ->with([10, 20], 0, 50, 'FlDate', 'DESC', '')
            ->willReturn($articleData);

        $this->articleRepository
            ->expects($this->once())
            ->method('countByFeeds')
            ->with([10, 20], '')
            ->willReturn(25);

        $result = $this->useCase->execute([10, 20]);

        $this->assertCount(2, $result['articles']);
        $this->assertSame(25, $result['total']);
        $this->assertSame('imported', $result['articles'][0]['status']);
        $this->assertSame('archived', $result['articles'][1]['status']);
    }

    // -----------------------------------------------------------------
    // executeForFeed (single feed convenience method)
    // -----------------------------------------------------------------

    #[Test]
    public function executeForFeedDelegatesToExecuteWithSingleFeedId(): void
    {
        $this->articleRepository
            ->expects($this->once())
            ->method('findByFeedsWithStatus')
            ->with([5], 0, 50, 'FlDate', 'DESC', '')
            ->willReturn([]);

        $this->articleRepository
            ->expects($this->once())
            ->method('countByFeeds')
            ->with([5], '')
            ->willReturn(0);

        $result = $this->useCase->executeForFeed(5);

        $this->assertSame([], $result['articles']);
        $this->assertSame(0, $result['total']);
    }

    #[Test]
    public function executeForFeedPassesPaginationParameters(): void
    {
        $this->articleRepository
            ->expects($this->once())
            ->method('findByFeedsWithStatus')
            ->with([7], 10, 25, 'FlTitle', 'ASC', 'query')
            ->willReturn([]);

        $this->articleRepository
            ->expects($this->once())
            ->method('countByFeeds')
            ->with([7], 'query')
            ->willReturn(0);

        $result = $this->useCase->executeForFeed(
            feedId: 7,
            offset: 10,
            limit: 25,
            orderBy: 'FlTitle',
            direction: 'ASC',
            search: 'query'
        );

        $this->assertSame([], $result['articles']);
    }

    // -----------------------------------------------------------------
    // getById
    // -----------------------------------------------------------------

    #[Test]
    public function getByIdReturnsArticleWhenFound(): void
    {
        $article = $this->makeArticle(42, 1, 'Found Article', 'https://example.com/found');

        $this->articleRepository
            ->expects($this->once())
            ->method('find')
            ->with(42)
            ->willReturn($article);

        $result = $this->useCase->getById(42);

        $this->assertInstanceOf(Article::class, $result);
        $this->assertSame(42, $result->id());
        $this->assertSame('Found Article', $result->title());
    }

    #[Test]
    public function getByIdReturnsNullWhenNotFound(): void
    {
        $this->articleRepository
            ->expects($this->once())
            ->method('find')
            ->with(999)
            ->willReturn(null);

        $result = $this->useCase->getById(999);

        $this->assertNull($result);
    }

    // -----------------------------------------------------------------
    // getByIds
    // -----------------------------------------------------------------

    #[Test]
    public function getByIdsReturnsMultipleArticles(): void
    {
        $article1 = $this->makeArticle(1, 10, 'Art 1', 'https://a.com/1');
        $article2 = $this->makeArticle(2, 10, 'Art 2', 'https://a.com/2');

        $this->articleRepository
            ->expects($this->once())
            ->method('findByIds')
            ->with([1, 2])
            ->willReturn([$article1, $article2]);

        $result = $this->useCase->getByIds([1, 2]);

        $this->assertCount(2, $result);
        $this->assertSame(1, $result[0]->id());
        $this->assertSame(2, $result[1]->id());
    }

    #[Test]
    public function getByIdsWithEmptyArrayReturnsEmptyArray(): void
    {
        $this->articleRepository
            ->expects($this->once())
            ->method('findByIds')
            ->with([])
            ->willReturn([]);

        $result = $this->useCase->getByIds([]);

        $this->assertSame([], $result);
    }

    #[Test]
    public function getByIdsReturnsOnlyFoundArticles(): void
    {
        $article = $this->makeArticle(1, 10, 'Art 1', 'https://a.com/1');

        $this->articleRepository
            ->expects($this->once())
            ->method('findByIds')
            ->with([1, 999])
            ->willReturn([$article]);

        $result = $this->useCase->getByIds([1, 999]);

        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]->id());
    }
}
