<?php

/**
 * Unit tests for DeleteArticles use case.
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

use Lwt\Modules\Feed\Application\UseCases\DeleteArticles;
use Lwt\Modules\Feed\Domain\ArticleRepositoryInterface;
use Lwt\Modules\Feed\Domain\FeedRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the DeleteArticles use case.
 *
 * Verifies article deletion by IDs, by feed IDs, and edge cases
 * with empty arrays.
 *
 * @since 3.0.0
 */
class DeleteArticlesTest extends TestCase
{
    private ArticleRepositoryInterface&MockObject $articleRepository;
    private FeedRepositoryInterface&MockObject $feedRepository;
    private DeleteArticles $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->articleRepository = $this->createMock(ArticleRepositoryInterface::class);
        $this->feedRepository = $this->createMock(FeedRepositoryInterface::class);
        $this->useCase = new DeleteArticles(
            $this->articleRepository,
            $this->feedRepository
        );
    }

    // -----------------------------------------------------------------
    // execute (delete by article IDs)
    // -----------------------------------------------------------------

    #[Test]
    public function executeDeletesArticlesByIds(): void
    {
        $this->articleRepository
            ->expects($this->once())
            ->method('deleteByIds')
            ->with([1, 2, 3])
            ->willReturn(3);

        $result = $this->useCase->execute([1, 2, 3]);

        $this->assertSame(3, $result);
    }

    #[Test]
    public function executeWithEmptyArrayReturnsZero(): void
    {
        $this->articleRepository
            ->expects($this->never())
            ->method('deleteByIds');

        $result = $this->useCase->execute([]);

        $this->assertSame(0, $result);
    }

    #[Test]
    public function executeWithSingleIdDeletesOneArticle(): void
    {
        $this->articleRepository
            ->expects($this->once())
            ->method('deleteByIds')
            ->with([42])
            ->willReturn(1);

        $result = $this->useCase->execute([42]);

        $this->assertSame(1, $result);
    }

    #[Test]
    public function executeReturnsActualDeleteCount(): void
    {
        // Some IDs might not exist, so deleted count may differ
        $this->articleRepository
            ->expects($this->once())
            ->method('deleteByIds')
            ->with([1, 2, 999])
            ->willReturn(2);

        $result = $this->useCase->execute([1, 2, 999]);

        $this->assertSame(2, $result);
    }

    // -----------------------------------------------------------------
    // executeByFeeds (delete by feed IDs)
    // -----------------------------------------------------------------

    #[Test]
    public function executeByFeedsDeletesArticlesAndUpdatesTimestamps(): void
    {
        $this->articleRepository
            ->expects($this->once())
            ->method('deleteByFeeds')
            ->with([10, 20])
            ->willReturn(15);

        $this->feedRepository
            ->expects($this->exactly(2))
            ->method('updateTimestamp')
            ->willReturnCallback(function (int $feedId, int $timestamp): void {
                $this->assertContains($feedId, [10, 20]);
                $this->assertGreaterThan(0, $timestamp);
            });

        $result = $this->useCase->executeByFeeds([10, 20]);

        $this->assertSame(15, $result);
    }

    #[Test]
    public function executeByFeedsWithEmptyArrayReturnsZero(): void
    {
        $this->articleRepository
            ->expects($this->never())
            ->method('deleteByFeeds');

        $this->feedRepository
            ->expects($this->never())
            ->method('updateTimestamp');

        $result = $this->useCase->executeByFeeds([]);

        $this->assertSame(0, $result);
    }

    #[Test]
    public function executeByFeedsUpdatesTimestampForEachFeed(): void
    {
        $this->articleRepository
            ->expects($this->once())
            ->method('deleteByFeeds')
            ->with([5, 10, 15])
            ->willReturn(30);

        $updatedFeedIds = [];
        $this->feedRepository
            ->expects($this->exactly(3))
            ->method('updateTimestamp')
            ->willReturnCallback(function (int $feedId) use (&$updatedFeedIds): void {
                $updatedFeedIds[] = $feedId;
            });

        $this->useCase->executeByFeeds([5, 10, 15]);

        $this->assertSame([5, 10, 15], $updatedFeedIds);
    }

    // -----------------------------------------------------------------
    // executeByFeed (delete by single feed ID)
    // -----------------------------------------------------------------

    #[Test]
    public function executeByFeedDelegatesToExecuteByFeeds(): void
    {
        $this->articleRepository
            ->expects($this->once())
            ->method('deleteByFeeds')
            ->with([7])
            ->willReturn(5);

        $this->feedRepository
            ->expects($this->once())
            ->method('updateTimestamp')
            ->with(
                $this->identicalTo(7),
                $this->isType('int')
            );

        $result = $this->useCase->executeByFeed(7);

        $this->assertSame(5, $result);
    }

    #[Test]
    public function executeByFeedReturnsZeroWhenNoArticlesExist(): void
    {
        $this->articleRepository
            ->expects($this->once())
            ->method('deleteByFeeds')
            ->with([42])
            ->willReturn(0);

        $this->feedRepository
            ->expects($this->once())
            ->method('updateTimestamp');

        $result = $this->useCase->executeByFeed(42);

        $this->assertSame(0, $result);
    }
}
